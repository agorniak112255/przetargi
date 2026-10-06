<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\ErpService;
use App\Models\InspectionPosition;
use App\Services\Erp\ErpXlGateway;
use App\Services\Erp\WarehouseLocations;
use App\Support\ClarionDate;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Nocny odczyt modułu Przeglądy z Comarch ERP XL (erp:inspections; XL tylko czytany):
 *
 * 1. katalog usług (Twr_Typ 4) → erp_services; usługa, której XL już nie zwrócił, dostaje removed_at; kod, nazwa
 *    i jednostka pozycji inspection_positions odświeżane z erp_services (usługi) i erp_items (towary);
 * 2. okno (domyślnie WINDOW_DAYS dni wstecz albo od --since): pozycje faktur i WZ ze wszystkimi usługami i z towarami
 *    pozycji, których historia jest już doczytana — w oknie zostaje dokładnie to, co XL zwrócił tym razem (dokument
 *    anulowany albo cofnięty do bufora znika);
 * 3. towary pozycji bez historii (history_loaded_at = null, np. dodane w dzień) — pozycje faktur od HISTORY_FROM, potem
 *    history_loaded_at = teraz.
 *
 * Kasowanie nieaktualnych wierszy dopiero po pełnym odczycie danego zakresu (synced_at starszy niż start przebiegu) —
 * przerwany odczyt XL nie zostawia dziury w historii. Pamięć (CLI 128 MB): strumień z XL, zapis paczkami po
 * UPSERT_CHUNK, bez dziennika zapytań.
 */
final class InspectionSaleSync
{
    /** Domyślne okno nocnego odczytu (dokumenty wystawione z datą wsteczną, korekty, anulowania). */
    public const WINDOW_DAYS = 60;

    /** Początek historii sprzedaży (plan 06.10.2026: legalizacji co 10 lat ta historia nie pokryje). */
    public const HISTORY_FROM = '2019-01-01';

    private const CATALOG_CHUNK = 500;

    private const UPSERT_CHUNK = 500;

    /** Paczki numerów w warunkach whereIn (limit parametrów SQLite / MariaDB). */
    private const GID_CHUNK = 500;

    public function __construct(private readonly ErpXlGateway $gateway) {}

    /**
     * @return array{services: int, lines: int, deleted: int, history_positions: int}
     */
    public function run(?CarbonImmutable $since = null): array
    {
        if (! $this->gateway->configured()) {
            throw new RuntimeException('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).');
        }

        DB::disableQueryLog();
        // bez ułamków sekund — synced_at zapisany z dokładnością do sekundy nie może wyjść „starszy” niż start
        $startedAt = CarbonImmutable::now()->startOfSecond();

        $services = $this->syncCatalog($startedAt);
        $this->refreshPositionNames();

        $from = ($since ?? PolishTime::today()->subDays(self::WINDOW_DAYS))->startOfDay();
        $fromDate = $from->toDateString();

        // 2. okno: wszystkie usługi i towary pozycji z doczytaną historią
        $loadedGoods = $this->goodsGids(true);
        $lines = $this->copyLines($loadedGoods, true, $from, $startedAt);
        $deleted = 0;
        $deleted += DB::table('inspection_sale_lines')
            ->where('issued_on', '>=', $fromDate)
            ->where('synced_at', '<', $startedAt)
            ->where('xl_item_type', InspectionPosition::TYPE_SERVICE)
            ->delete();
        foreach (array_chunk($loadedGoods, self::GID_CHUNK) as $chunk) {
            $deleted += DB::table('inspection_sale_lines')
                ->where('issued_on', '>=', $fromDate)
                ->where('synced_at', '<', $startedAt)
                ->whereIn('xl_item_gid', $chunk)
                ->delete();
        }

        // 3. towary pozycji bez historii — od początku historii (albo od wcześniejszego --since)
        $newGoods = $this->goodsGids(false);
        if ($newGoods !== []) {
            $historyFrom = CarbonImmutable::parse(self::HISTORY_FROM)->startOfDay();
            if ($from->lessThan($historyFrom)) {
                $historyFrom = $from;
            }
            $lines += $this->copyLines($newGoods, false, $historyFrom, $startedAt);
            foreach (array_chunk($newGoods, self::GID_CHUNK) as $chunk) {
                $deleted += DB::table('inspection_sale_lines')
                    ->where('issued_on', '>=', $historyFrom->toDateString())
                    ->where('synced_at', '<', $startedAt)
                    ->whereIn('xl_item_gid', $chunk)
                    ->delete();
                // zapis techniczny — bez zmiany updated_at (to data zmiany pozycji przez człowieka)
                InspectionPosition::query()
                    ->where('xl_type', InspectionPosition::TYPE_GOODS)
                    ->whereNull('history_loaded_at')
                    ->whereIn('xl_gid', $chunk)
                    ->toBase()
                    ->update(['history_loaded_at' => CarbonImmutable::now()]);
            }
        }

        return ['services' => $services, 'lines' => $lines, 'deleted' => $deleted, 'history_positions' => count($newGoods)];
    }

    /** Pełny odczyt katalogu usług; zwraca liczbę usług z XL. */
    private function syncCatalog(CarbonImmutable $startedAt): int
    {
        $after = 0;
        $count = 0;
        while (true) {
            $rows = $this->gateway->services($after, self::CATALOG_CHUNK);
            if ($rows === []) {
                break;
            }
            $after = max(array_column($rows, 'gid'));
            $upsert = [];
            foreach ($rows as $r) {
                $upsert[] = [
                    'xl_gid' => $r['gid'],
                    'xl_type' => $r['type'],
                    'code' => mb_substr($r['code'], 0, 100),
                    'name' => mb_substr($r['name'], 0, 500),
                    'unit' => $r['unit'] !== null ? mb_substr($r['unit'], 0, 20) : null,
                    'archived' => $r['archived'],
                    'synced_at' => $startedAt,
                    'removed_at' => null,
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ];
            }
            DB::table('erp_services')->upsert($upsert, ['xl_gid'], ['xl_type', 'code', 'name', 'unit', 'archived', 'synced_at', 'removed_at', 'updated_at']);
            $count += count($rows);
            if (count($rows) < self::CATALOG_CHUNK) {
                break;
            }
        }

        // pusty katalog to raczej błąd odczytu niż XL bez usług — wtedy nic nie oznaczamy jako usunięte
        if ($count > 0) {
            ErpService::query()
                ->whereNull('removed_at')
                ->where(static fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', $startedAt))
                ->update(['removed_at' => $startedAt]);
        }

        return $count;
    }

    /**
     * Kod, nazwa i jednostka pozycji z katalogu (usługi z erp_services, towary z erp_items) — gdy się zmieniły w XL.
     * Pozycja bez wpisu w katalogu zostaje z ostatnimi znanymi danymi.
     */
    private function refreshPositionNames(): void
    {
        $positions = InspectionPosition::query()->toBase()->get(['id', 'xl_gid', 'xl_type', 'code', 'name', 'unit']);
        foreach ([InspectionPosition::TYPE_SERVICE => 'erp_services', InspectionPosition::TYPE_GOODS => 'erp_items'] as $type => $table) {
            $ofType = $positions->filter(static fn ($p): bool => (int) $p->xl_type === $type);
            foreach ($ofType->chunk(self::GID_CHUNK) as $chunk) {
                $catalog = DB::table($table)->whereIn('xl_gid', $chunk->pluck('xl_gid')->all())
                    ->get(['xl_gid', 'code', 'name', 'unit'])->keyBy(static fn ($r): int => (int) $r->xl_gid);
                foreach ($chunk as $p) {
                    $source = $catalog->get((int) $p->xl_gid);
                    if ($source === null) {
                        continue;
                    }
                    $fresh = [
                        'code' => mb_substr((string) $source->code, 0, 100),
                        'name' => mb_substr((string) $source->name, 0, 500),
                        'unit' => $source->unit !== null && $source->unit !== '' ? mb_substr((string) $source->unit, 0, 20) : null,
                    ];
                    if ($fresh['code'] !== (string) $p->code || $fresh['name'] !== (string) $p->name || $fresh['unit'] !== $p->unit) {
                        // zapis techniczny — bez zmiany updated_at (to data zmiany pozycji przez człowieka)
                        InspectionPosition::query()->whereKey((int) $p->id)->toBase()->update($fresh);
                    }
                }
            }
        }
    }

    /**
     * Numery XL towarów pozycji z historią doczytaną ($loaded = true) albo jeszcze nie.
     *
     * @return list<int>
     */
    private function goodsGids(bool $loaded): array
    {
        $query = InspectionPosition::query()->where('xl_type', InspectionPosition::TYPE_GOODS);
        $loaded ? $query->whereNotNull('history_loaded_at') : $query->whereNull('history_loaded_at');
        $gids = $query->pluck('xl_gid')->map(static fn ($gid): int => (int) $gid)->unique()->sort()->values()->all();

        return $gids;
    }

    /**
     * Strumień pozycji faktur z XL → inspection_sale_lines (upsert po dokumencie i pozycji); zwraca liczbę zapisanych.
     *
     * @param  list<int>  $itemGids
     */
    private function copyLines(array $itemGids, bool $allServices, CarbonImmutable $from, CarbonImmutable $startedAt): int
    {
        if ($itemGids === [] && ! $allServices) {
            return 0;
        }
        $fromDate = $from->toDateString();
        $count = 0;
        $buffer = [];
        foreach ($this->gateway->inspectionSaleLines($itemGids, $allServices, ClarionDate::fromDate($from)) as $row) {
            $issued = ClarionDate::toDate($row['issued']);
            if ($issued === null || $issued->toDateString() < $fromDate || $row['customer_gid'] <= 0) {
                continue;
            }
            $sold = $row['sold'] > 0 ? ClarionDate::toDate($row['sold']) : null;
            $warehouse = $row['warehouse_code'] !== null ? mb_substr(trim($row['warehouse_code']), 0, 20) : '';
            $location = $warehouse !== '' ? WarehouseLocations::of($warehouse) : null;
            $operator = $row['operator'] !== null ? mb_substr(trim($row['operator']), 0, 20) : '';
            $recipient = $row['recipient_gid'];
            $buffer[] = [
                'document_type' => $row['doc_type'],
                'document_id' => $row['document_id'],
                'line' => $row['line'],
                'document_number' => mb_substr($row['document_number'], 0, 40),
                'invoice_number' => $row['invoice_number'] !== null ? mb_substr($row['invoice_number'], 0, 40) : null,
                'issued_on' => $issued->toDateString(),
                'sold_on' => $sold?->toDateString(),
                'customer_xl_gid' => $row['customer_gid'],
                // odbiorca tylko, gdy inny niż nabywca
                'recipient_xl_gid' => $recipient > 0 && $recipient !== $row['customer_gid'] ? $recipient : null,
                'xl_item_gid' => $row['item_gid'],
                'xl_item_type' => $row['item_type'],
                'quantity' => round($row['quantity'], 3),
                'net_value' => round($row['net_value'], 2),
                'warehouse_code' => $warehouse !== '' ? $warehouse : null,
                // kolumna mieści 4 znaki — dłuższy (nieznany) oddział zostaje pusty zamiast uciętego
                'location' => $location !== null && strlen($location) <= 4 ? $location : null,
                'operator_ident' => $operator !== '' ? $operator : null,
                'corrects_document_type' => $row['corrects_type'],
                'corrects_document_id' => $row['corrects_id'],
                'synced_at' => $startedAt,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ];
            if (count($buffer) >= self::UPSERT_CHUNK) {
                $count += $this->flush($buffer);
                $buffer = [];
            }
        }

        return $count + $this->flush($buffer);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function flush(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        DB::table('inspection_sale_lines')->upsert($rows, ['document_type', 'document_id', 'line'], [
            'document_number', 'invoice_number', 'issued_on', 'sold_on', 'customer_xl_gid', 'recipient_xl_gid', 'xl_item_gid', 'xl_item_type',
            'quantity', 'net_value', 'warehouse_code', 'location', 'operator_ident', 'corrects_document_type',
            'corrects_document_id', 'synced_at', 'updated_at',
        ]);

        return count($rows);
    }
}
