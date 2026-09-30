<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\ErpCustomer;
use App\Models\ErpItem;
use App\Models\ErpSaleLine;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sprzedaż towarów z kampanii wysłanych w ostatnich WINDOW_DAYS + MARGIN_DAYS dniach: pozycje FS/PA od dnia
 * najwcześniejszej wysyłki (erp_sale_lines). Tylko te towary i ten okres — jedno krótkie zapytanie do XL w nocy.
 * Pozycje, których XL już nie zwraca (dokument anulowany), są kasowane w tym samym zakresie.
 */
final class ErpCampaignSalesSync
{
    /** Okres liczenia wyniku po wysyłce (tyle dni pokazuje wynik kampanii). */
    public const WINDOW_DAYS = 30;

    /** Zapas na nocny odczyt po końcu okresu (dokumenty wystawione z datą wsteczną). */
    private const MARGIN_DAYS = 7;

    private const INSERT_CHUNK = 500;

    /** Kiedy ostatnio odczytano sprzedaż kampanii z XL (ISO) — „stan na” przy wyniku. */
    public const SYNCED_AT_CACHE_KEY = 'erp.campaign_sales.synced_at';

    public function __construct(private readonly ErpXlGateway $gateway) {}

    /**
     * @return array{campaigns: int, items: int, lines: int, removed: int}
     */
    public function run(): array
    {
        $startedAt = CarbonImmutable::now()->startOfSecond();
        $campaigns = Campaign::query()
            ->whereIn('status', [Campaign::STATUS_SENDING, Campaign::STATUS_SENT, Campaign::STATUS_CANCELLED])
            ->whereNotNull('sending_started_at')
            ->where('sending_started_at', '>=', $startedAt->subDays(self::WINDOW_DAYS + self::MARGIN_DAYS))
            ->get(['id', 'sending_started_at']);
        if ($campaigns->isEmpty()) {
            return ['campaigns' => 0, 'items' => 0, 'lines' => 0, 'removed' => 0];
        }

        $itemIds = CampaignItem::query()->whereIn('campaign_id', $campaigns->pluck('id'))->whereNotNull('erp_item_id')
            ->distinct()->pluck('erp_item_id')->map(static fn ($id): int => (int) $id)->all();
        $items = ErpItem::query()->whereIn('id', $itemIds)->pluck('id', 'xl_gid')->map(static fn ($id): int => (int) $id)->all();
        if ($items === []) {
            return ['campaigns' => $campaigns->count(), 'items' => 0, 'lines' => 0, 'removed' => 0];
        }

        $from = CarbonImmutable::parse((string) $campaigns->min('sending_started_at'))->startOfDay();
        $customers = ErpCustomer::query()->pluck('id', 'xl_gid')->map(static fn ($id): int => (int) $id)->all();

        $lines = 0;
        $buffer = [];
        foreach ($this->gateway->itemSaleLines(array_keys($items), ClarionDate::fromDate($from)) as $row) {
            $itemId = $items[$row['item_gid']] ?? null;
            $soldAt = ClarionDate::toDate($row['date']);
            if ($itemId === null || $soldAt === null) {
                continue;
            }
            $buffer[] = [
                'document_type' => $row['document_type'],
                'document_id' => $row['document_id'],
                'line' => $row['line'],
                'document_number' => mb_substr($row['document_number'], 0, 40),
                'sold_at' => $soldAt->toDateString(),
                'customer_xl_gid' => $row['customer_gid'],
                'erp_customer_id' => $customers[$row['customer_gid']] ?? null,
                'erp_item_id' => $itemId,
                'quantity' => round($row['quantity'], 3),
                'net_value' => round($row['net_value'], 2),
                'synced_at' => $startedAt,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ];
            if (count($buffer) >= self::INSERT_CHUNK) {
                $lines += $this->flush($buffer);
                $buffer = [];
            }
        }
        $lines += $this->flush($buffer);

        // dokument anulowany albo cofnięty do bufora od ostatniego odczytu — znika z wyniku
        $removed = ErpSaleLine::query()
            ->whereIn('erp_item_id', array_values($items))
            ->where('sold_at', '>=', $from->toDateString())
            ->where('synced_at', '<', $startedAt)
            ->delete();

        Cache::forever(self::SYNCED_AT_CACHE_KEY, $startedAt->toIso8601String());

        return ['campaigns' => $campaigns->count(), 'items' => count($items), 'lines' => $lines, 'removed' => $removed];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function flush(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        DB::table('erp_sale_lines')->upsert($rows, ['document_type', 'document_id', 'line'], [
            'document_number', 'sold_at', 'customer_xl_gid', 'erp_customer_id', 'erp_item_id', 'quantity', 'net_value', 'synced_at', 'updated_at',
        ]);

        return count($rows);
    }
}
