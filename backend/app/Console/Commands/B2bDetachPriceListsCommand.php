<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\ProductPriceHistory;
use App\Services\B2b\B2bConnectorRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sprząta wpisy w historii cenników założone przez dawną synchronizację B2B („{host} (API)”).
 * Karty, zdjęcia, powiązania i historia cen produktów zostają — odpinamy je od wpisu i usuwamy sam
 * wiersz price_lists bezpośrednio (usuwanie cennika z panelu kasuje też produkty).
 */
final class B2bDetachPriceListsCommand extends Command
{
    protected $signature = 'b2b:detach-price-lists
        {--dry-run : Tylko pokazuje, co zostałoby odpięte i usunięte}';

    protected $description = 'Usuwa z historii cenników wpisy synchronizacji B2B, zostawiając produkty i historię cen';

    /** Dawny kod uzupełniał wpis (product_ids) dopiero na końcu przebiegu. */
    private const UNFINISHED_GRACE_HOURS = 6;

    public function handle(B2bConnectorRegistry $connectors): int
    {
        $keysByFilename = [];
        foreach ($connectors->options() as $option) {
            $keysByFilename[$option['host'].' (API)'] = $option['key'];
        }

        $lists = PriceList::query()
            ->whereIn('original_filename', array_keys($keysByFilename))
            ->orderBy('id')
            ->get();
        if ($lists->isEmpty()) {
            $this->info('Brak wpisów B2B w historii cenników.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        foreach ($lists as $list) {
            $history = ProductPriceHistory::query()->where('price_list_id', $list->id)->count();
            $line = sprintf(
                '#%d %s · produkty w cenniku: %d · wpisy historii cen do odpięcia: %d',
                $list->id,
                $list->version,
                count($list->product_ids ?? []),
                $history,
            );

            // przebieg starym kodem jeszcze trwa — usunięcie teraz zgubiłoby jego podsumowanie i odpięcie
            if ($list->product_ids === null && $list->created_at?->greaterThan(now()->subHours(self::UNFINISHED_GRACE_HOURS))) {
                $this->warn($line.' · pominięty: przebieg jeszcze trwa, uruchom ponownie po jego zakończeniu');

                continue;
            }

            if ($dryRun) {
                $this->line($line.' · bez zmian (--dry-run)');

                continue;
            }

            $key = $keysByFilename[$list->original_filename];
            DB::transaction(static function () use ($list, $key): void {
                ProductPriceHistory::query()
                    ->where('price_list_id', $list->id)
                    ->where('source', 'b2b_api')
                    ->update(['source' => 'b2b:'.$key, 'price_list_id' => null]);
                ProductPriceHistory::query()
                    ->where('price_list_id', $list->id)
                    ->update(['price_list_id' => null]);
                B2bAccount::query()
                    ->where('last_price_list_id', $list->id)
                    ->update(['last_price_list_id' => null]);
                PriceList::query()->whereKey($list->id)->delete();
            });

            $this->info($line.' · usunięty');
        }

        return self::SUCCESS;
    }
}
