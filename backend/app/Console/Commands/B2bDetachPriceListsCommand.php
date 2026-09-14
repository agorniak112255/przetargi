<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\ProductPriceHistory;
use App\Services\B2b\B2bAccountPriceList;
use App\Services\B2b\B2bConnectorRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sprząta zdublowane wpisy B2B w historii cenników („{host} (API)” — dawniej jeden na przebieg). Stały wpis
 * konta (b2b_accounts.last_price_list_id) zostaje zawsze; konto bez wpisu przejmuje najnowszy wolny wpis swojego
 * łącznika, tak jak zrobiłoby to najbliższe pobranie. Z pozostałych wpisów karty, zdjęcia, powiązania i historia
 * cen produktów zostają — odpinamy je i usuwamy sam wiersz price_lists bezpośrednio (usuwanie cennika z panelu
 * kasuje też produkty).
 */
final class B2bDetachPriceListsCommand extends Command
{
    protected $signature = 'b2b:detach-price-lists
        {--dry-run : Tylko pokazuje, co zostałoby przejęte, odpięte i usunięte}';

    protected $description = 'Usuwa zdublowane wpisy synchronizacji B2B z historii cenników, zostawiając wpis konta, produkty i historię cen';

    /** Dawny kod uzupełniał wpis (product_ids) dopiero na końcu przebiegu. */
    private const UNFINISHED_GRACE_HOURS = 6;

    public function handle(B2bConnectorRegistry $connectors, B2bAccountPriceList $accountLists): int
    {
        $keysByFilename = [];
        $hostsByKey = [];
        foreach ($connectors->options() as $option) {
            $keysByFilename[B2bAccountPriceList::filename($option['host'])] = $option['key'];
            $hostsByKey[$option['key']] = $option['host'];
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
        $existingIds = PriceList::query()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        /** @var array<int, B2bAccount> $owners price_list_id => konto */
        $owners = [];
        $adopted = [];
        $accounts = B2bAccount::query()->orderBy('id')->get();
        foreach ($accounts as $account) {
            if ($account->last_price_list_id !== null && in_array((int) $account->last_price_list_id, $existingIds, true)) {
                $owners[(int) $account->last_price_list_id] = $account;
            }
        }
        foreach ($accounts as $account) {
            if (in_array($account, $owners, true)) {
                continue;
            }
            $host = $hostsByKey[(string) $accountLists->connectorKey($account)] ?? null;
            $candidate = $host !== null ? $accountLists->adoptionCandidate($host, array_keys($owners)) : null;
            if ($candidate === null) {
                continue;
            }
            $owners[(int) $candidate->id] = $account;
            $adopted[(int) $candidate->id] = true;
            if (! $dryRun) {
                $account->forceFill(['last_price_list_id' => $candidate->id])->save();
            }
        }

        foreach ($lists as $list) {
            $history = ProductPriceHistory::query()->where('price_list_id', $list->id)->count();
            $line = sprintf(
                '#%d %s · produkty w cenniku: %d · wpisy historii cen: %d',
                $list->id,
                $list->version,
                count($list->product_ids ?? []),
                $history,
            );

            $owner = $owners[(int) $list->id] ?? null;
            if ($owner !== null) {
                $this->info(sprintf(
                    '%s · zostaje: wpis konta B2B %s%s',
                    $line,
                    $owner->username,
                    isset($adopted[(int) $list->id]) ? ($dryRun ? ' (zostałby przejęty, --dry-run)' : ' (przejęty)') : '',
                ));

                continue;
            }

            // przebieg starym kodem jeszcze trwa — usunięcie teraz zgubiłoby jego podsumowanie i odpięcie
            if ($list->product_ids === null && $list->created_at?->greaterThan(now()->subHours(self::UNFINISHED_GRACE_HOURS))) {
                $this->warn($line.' · pominięty: przebieg jeszcze trwa, uruchom ponownie po jego zakończeniu');

                continue;
            }

            if ($dryRun) {
                $this->line($line.' · duplikat, bez zmian (--dry-run)');

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
                PriceList::query()->whereKey($list->id)->delete();
            });

            $this->info($line.' · duplikat usunięty');
        }

        return self::SUCCESS;
    }
}
