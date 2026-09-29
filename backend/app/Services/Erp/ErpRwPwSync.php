<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use App\Models\ErpRwPwPair;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pary RW → PW z ERP XL: towar wydany dokumentem RW i przyjęty z powrotem PW w tej samej ilości najwyżej 30 dni
 * później. Tak pracownicy „odmładzają” zalegającą partię — PW zakłada nową partię z nową datą, choć towar nie rotował.
 * Część par to uczciwe korekty (np. PZ przyjęta w złej ilości), dlatego zapisujemy dokumenty dosłownie i znaczniki
 * (ta sama wartość, ten sam magazyn), a ocenę zostawiamy człowiekowi. Tabela przeliczana w całości przy każdym odczycie.
 */
final class ErpRwPwSync
{
    /** Najdłuższy odstęp RW → PW, jaki trzymamy; ekran zawęża go dalej (0/3/7/30 dni). */
    public const MAX_GAP_DAYS = 30;

    private const QUANTITY_EPSILON = 0.0001;

    public function __construct(private readonly ErpXlGateway $gateway) {}

    /**
     * @return array{moves: int, pairs: int, items: int}
     */
    public function run(int $months = 12): array
    {
        if (! $this->gateway->configured()) {
            throw new RuntimeException('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).');
        }
        $from = CarbonImmutable::today()->subMonthsNoOverflow($months);
        $moves = $this->gateway->internalMoves(ClarionDate::fromDate($from));

        $byItem = [];
        foreach ($moves as $move) {
            $byItem[$move['gid']][$move['type']][] = $move;
        }
        $pairs = [];
        foreach ($byItem as $gid => $docs) {
            foreach ($this->pairItem($docs['rw'] ?? [], $docs['pw'] ?? []) as [$rw, $pw]) {
                $pairs[] = [$gid, $rw, $pw];
            }
        }

        $itemIds = $pairs === [] ? [] : ErpItem::query()
            ->whereIn('xl_gid', array_values(array_unique(array_column($pairs, 0))))
            ->pluck('id', 'xl_gid')
            ->all();
        $now = CarbonImmutable::now();
        $rows = [];
        foreach ($pairs as [$gid, $rw, $pw]) {
            $rwDate = ClarionDate::toDate($rw['date']);
            $pwDate = ClarionDate::toDate($pw['date']);
            if ($rwDate === null || $pwDate === null) {
                continue;
            }
            $rows[] = [
                'xl_gid' => $gid,
                'erp_item_id' => $itemIds[$gid] ?? null,
                ...$this->docFields('rw', $rw, $rwDate),
                ...$this->docFields('pw', $pw, $pwDate),
                'gap_days' => (int) $rwDate->diffInDays($pwDate),
                'same_value' => abs(round($rw['value'], 2) - round($pw['value'], 2)) < 0.005,
                'same_warehouse' => $rw['warehouse'] !== null && $rw['warehouse'] === $pw['warehouse'],
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            ErpRwPwPair::query()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                ErpRwPwPair::query()->insert($chunk);
            }
        });

        return ['moves' => count($moves), 'pairs' => count($rows), 'items' => count(array_unique(array_column($rows, 'xl_gid')))];
    }

    /**
     * Każde RW (od najstarszego) bierze jedno wolne PW tego towaru w tej samej ilości, 0–30 dni później: najbliższe
     * w czasie, potem z tego samego magazynu, potem o najbliższej wartości. Jeden dokument należy najwyżej do jednej pary.
     *
     * @param  list<array<string, mixed>>  $rws
     * @param  list<array<string, mixed>>  $pws
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private function pairItem(array $rws, array $pws): array
    {
        if ($rws === [] || $pws === []) {
            return [];
        }
        $order = static fn (array $a, array $b): int => [$a['date'], $a['document_id']] <=> [$b['date'], $b['document_id']];
        usort($rws, $order);
        usort($pws, $order);

        $used = [];
        $out = [];
        foreach ($rws as $rw) {
            $best = null;
            $bestKey = null;
            foreach ($pws as $i => $pw) {
                $gap = $pw['date'] - $rw['date'];
                if (isset($used[$i]) || $gap < 0 || $gap > self::MAX_GAP_DAYS
                    || abs($pw['quantity'] - $rw['quantity']) >= self::QUANTITY_EPSILON) {
                    continue;
                }
                $key = [$gap, $pw['warehouse'] === $rw['warehouse'] ? 0 : 1, abs($pw['value'] - $rw['value']), $pw['document_id']];
                if ($bestKey === null || $key < $bestKey) {
                    $best = $i;
                    $bestKey = $key;
                }
            }
            if ($best !== null) {
                $used[$best] = true;
                $out[] = [$rw, $pws[$best]];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function docFields(string $prefix, array $doc, CarbonImmutable $date): array
    {
        return [
            $prefix.'_document_id' => $doc['document_id'],
            $prefix.'_number' => mb_substr((string) $doc['number'], 0, 40),
            $prefix.'_date' => $date->toDateString(),
            $prefix.'_warehouse' => $doc['warehouse'] !== null ? mb_substr((string) $doc['warehouse'], 0, 10) : null,
            $prefix.'_quantity' => $doc['quantity'],
            $prefix.'_value' => round((float) $doc['value'], 2),
            $prefix.'_operator' => $doc['operator'] !== null ? mb_substr((string) $doc['operator'], 0, 20) : null,
            $prefix.'_approver' => $doc['approver'] !== null ? mb_substr((string) $doc['approver'], 0, 20) : null,
        ];
    }
}
