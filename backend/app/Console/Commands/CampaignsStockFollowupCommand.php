<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Erp\InventoryQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Wynik kampanii: stan magazynów handlowych pozycji 7 i 30 dni po wysyłce (z nocnej kopii XL). Wpisuje tylko puste
 * pola — raz zapisany stan „po 7 dniach” się nie zmienia. Odczyt spóźniony ponad tolerancję z WINDOWS (np. przerwa
 * harmonogramu) zostaje pusty — stan po 20 dniach podany jako „po 7 dniach” zawyżałby wynik. Stan zapisywany tylko
 * z odczytu XL nie starszego niż wysyłka + N dni − 1 dzień (przerwana synchronizacja XL nie daje starego stanu).
 */
class CampaignsStockFollowupCommand extends Command
{
    /** Ile dni po terminie odczyt jeszcze się liczy: [kolumna => [dni po wysyłce, tolerancja]]. */
    private const WINDOWS = ['stock_after_7d' => [7, 7], 'stock_after_30d' => [30, 14]];

    protected $signature = 'campaigns:stock-followup';

    protected $description = 'Zapisuje stan pozycji kampanii 7 i 30 dni po wysyłce (wynik kampanii)';

    public function handle(): int
    {
        $counts = [];
        foreach (self::WINDOWS as $column => [$days, $late]) {
            $counts[$column] = $this->fill($column, $days, Carbon::now()->subDays($days), Carbon::now()->subDays($days + $late));
        }
        $this->info(sprintf('Stan po 7 dniach: %d pozycji, po 30 dniach: %d pozycji.', $counts['stock_after_7d'], $counts['stock_after_30d']));

        return self::SUCCESS;
    }

    private function fill(string $column, int $days, Carbon $sentBefore, Carbon $sentAfter): int
    {
        // pozycje bez towaru XL (karta bez powiązania) nie mają stanu — pomija je złączenie z erp_items
        $rows = DB::table('campaign_items as ci')
            ->join('campaigns as c', 'c.id', '=', 'ci.campaign_id')
            ->join('erp_items', 'erp_items.id', '=', 'ci.erp_item_id')
            // w wysyłce też: po dopisaniu odbiorców kampania wraca do sending, a sent_at zostaje z pierwszego zakończenia
            ->whereIn('c.status', [Campaign::STATUS_SENT, Campaign::STATUS_SENDING])
            ->whereNotNull('c.sent_at')
            ->where('c.sent_at', '<=', $sentBefore)
            ->where('c.sent_at', '>=', $sentAfter)
            ->whereNull('ci.'.$column)
            ->whereNotNull('erp_items.stock_synced_at')
            ->selectRaw('ci.id, c.sent_at, erp_items.stock_synced_at, '.InventoryQuery::quantitySql('trade').' as quantity')
            ->get();

        $filled = 0;
        foreach ($rows as $row) {
            // tylko świeży odczyt XL (z nocy po terminie; dzień zapasu na porę nocnej synchronizacji) — stary stan
            // sprzed wysyłki udawałby „po N dniach” zerowy spadek; pozycja czeka na kolejny dzień
            if (Carbon::parse($row->stock_synced_at)->lt(Carbon::parse($row->sent_at)->addDays($days - 1))) {
                continue;
            }
            $filled += DB::table('campaign_items')->where('id', $row->id)->whereNull($column)
                ->update([$column => round((float) $row->quantity, 3), 'updated_at' => Carbon::now()]);
        }

        return $filled;
    }
}
