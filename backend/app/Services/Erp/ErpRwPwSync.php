<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use App\Models\ErpRwPwPair;
use App\Support\ClarionDate;
use App\Support\XlTimestamp;
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

    /** Partia przyjęta dokumentem PW (CDN.Dostawy.Dst_TrnTyp) — była już wcześniej „odnawiana”. */
    private const PW_TYPE = 1617;

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
        $lots = [];
        foreach ($this->gateway->internalMoveLots(ClarionDate::fromDate($from)) as $lot) {
            $lots[$lot['type'].'-'.$lot['document_id'].'-'.$lot['gid']][] = $lot;
        }

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
                ...$this->lotFields($rwLots = $lots['rw-'.$rw['document_id'].'-'.$gid] ?? [], $rwDate),
                ...$this->featureFields($rwLots, $lots['pw-'.$pw['document_id'].'-'.$gid] ?? []),
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
     * Wiek partii zdjętych przez RW w dniu RW: najstarsza (pełne miesiące, jej dokument przyjęcia, czy weszła przez PW)
     * i średnia ważona ilością. Bez partii (XL nie podał) — puste pola.
     *
     * @param  list<array<string, mixed>>  $lots
     * @return array<string, mixed>
     */
    private function lotFields(array $lots, CarbonImmutable $rwDate): array
    {
        $oldest = null;
        $weighted = 0.0;
        $quantity = 0.0;
        $count = 0;
        foreach ($lots as $lot) {
            $at = XlTimestamp::toDate($lot['received_at']);
            if ($at === null) {
                continue;
            }
            $count++;
            $months = max(0.0, (float) $at->diffInMonths($rwDate));
            $weighted += $months * (float) $lot['quantity'];
            $quantity += (float) $lot['quantity'];
            if ($oldest === null || $at->lessThan($oldest['at'])) {
                $oldest = ['at' => $at, 'lot' => $lot];
            }
        }
        if ($oldest === null) {
            return ['rw_lot_at' => null, 'rw_lot_age_months' => null, 'rw_lot_avg_age_months' => null, 'rw_lots' => 0, 'rw_lot_source' => null, 'rw_lot_from_pw' => false];
        }

        return [
            'rw_lot_at' => $oldest['at']->toDateString(),
            'rw_lot_age_months' => max(0, (int) floor($oldest['at']->diffInMonths($rwDate))),
            'rw_lot_avg_age_months' => $quantity > 0 ? round($weighted / $quantity, 1) : null,
            'rw_lots' => $count,
            'rw_lot_source' => $oldest['lot']['source_number'] !== null ? mb_substr((string) $oldest['lot']['source_number'], 0, 40) : null,
            'rw_lot_from_pw' => (int) $oldest['lot']['source_type'] === self::PW_TYPE,
        ];
    }

    private function cut(mixed $value, int $length): ?string
    {
        return $value === null || $value === '' ? null : mb_substr((string) $value, 0, $length);
    }

    /**
     * Cechy partii (zwykle rozmiar) po stronie RW i PW. Inna cecha = zmiana rozmiaru (38 → 39), nie odmłodzenie partii.
     *
     * @param  list<array<string, mixed>>  $rwLots
     * @param  list<array<string, mixed>>  $pwLots
     * @return array{rw_features: string|null, pw_features: string|null, same_feature: bool}
     */
    private function featureFields(array $rwLots, array $pwLots): array
    {
        $rw = $this->features($rwLots);
        $pw = $this->features($pwLots);
        $rwLabel = $this->featureLabel($rw);
        $pwLabel = $this->featureLabel($pw);

        return [
            'rw_features' => $rwLabel,
            'pw_features' => $pwLabel,
            // zmianę stwierdzamy tylko przy danych o partiach po obu stronach i choć jednej nazwanej cesze —
            // brak danych to nie dowód zmiany rozmiaru
            'same_feature' => $rw === [] || $pw === [] || ($rwLabel === null && $pwLabel === null) || $rw === $pw,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lots
     * @return array<string, float> cecha → ilość (bez cechy = '')
     */
    private function features(array $lots): array
    {
        $out = [];
        foreach ($lots as $lot) {
            $feature = (string) ($lot['feature'] ?? '');
            $out[$feature] = round(($out[$feature] ?? 0.0) + (float) $lot['quantity'], 4);
        }
        ksort($out, SORT_NATURAL);

        return $out;
    }

    /** @param  array<string, float>  $features „38” albo „L×2, XL×1”; bez cech — null */
    private function featureLabel(array $features): ?string
    {
        $named = array_filter($features, static fn (float $q, string $f): bool => $f !== '', ARRAY_FILTER_USE_BOTH);
        if ($named === []) {
            return null;
        }
        if (count($features) === 1) {
            return mb_substr((string) array_key_first($features), 0, 120);
        }
        $parts = [];
        foreach ($features as $feature => $quantity) {
            $parts[] = ($feature === '' ? 'bez cechy' : $feature).'×'.rtrim(rtrim(number_format($quantity, 4, ',', ''), '0'), ',');
        }

        return mb_substr(implode(', ', $parts), 0, 120);
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
            $prefix.'_operator_name' => $this->cut($doc['operator_name'] ?? null, 100),
            $prefix.'_approver_name' => $this->cut($doc['approver_name'] ?? null, 100),
            $prefix.'_note' => $this->cut($doc['note'] ?? null, 255),
            // dokument obcy tylko, gdy nie powtarza własnego numeru (w RW XL wpisuje tam numer RW)
            $prefix.'_foreign_number' => ($doc['foreign_number'] ?? null) !== null && $doc['foreign_number'] !== $doc['number']
                ? $this->cut($doc['foreign_number'], 40) : null,
        ];
    }
}
