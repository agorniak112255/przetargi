<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Zwija Cenniki do jednego wpisu na producenta. Każdy import zakładał dotąd własny wiersz, więc pięć
 * aktualizacji ARTRY dawało pięć pozycji na liście; konto B2B miało jeszcze jedną, osobną.
 *
 * Nic nie ginie: raport każdego zwijanego wpisu przechodzi do dziennika aktualizacji (price_list_imports)
 * razem z jego dawnym identyfikatorem, a wskaźniki cen (slot z pliku, historia ceny, konto B2B) są
 * przepinane na wpis, który zostaje. Dopiero potem puste już wiersze znikają.
 *
 * Domyślnie tylko podgląd — zapis wymaga --apply.
 */
final class CollapsePriceListsCommand extends Command
{
    protected $signature = 'price-lists:collapse
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}
                            {--limit=0 : Ile grup pokazać w podglądzie (0 = wszystkie)}';

    protected $description = 'Zwija wpisy w Cennikach do jednego na producenta, przenosząc historię aktualizacji do dziennika';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $missingKeys = $this->rowsWithoutKey();
        $groups = $this->groups();

        if ($missingKeys->isNotEmpty()) {
            $this->info('Wpisów bez klucza producenta: '.$missingKeys->count()
                .' — bez niego kolejny import założyłby obok nich nowy wiersz.');
        }

        if ($groups->isEmpty() && $missingKeys->isEmpty()) {
            $this->info('Nie ma czego zwijać — każdy producent ma już jeden wpis i swój klucz.');

            return self::SUCCESS;
        }

        if ($groups->isNotEmpty()) {
            $this->table(
                ['Klucz producenta', 'Zapisy nazwy', 'Wpisów', 'Zostaje', 'Kart łącznie'],
                $this->previewRows($groups),
            );
        }

        $collapsed = $groups->sum(static fn (Collection $rows): int => $rows->count() - 1);
        $this->line('');
        $this->info('Grup do scalenia: '.$groups->count().', wpisów do zwinięcia: '.$collapsed.'.');

        if (! $apply) {
            $this->warn('Podgląd — nic nie zapisano. Zapis: --apply.');

            return self::SUCCESS;
        }

        // Klucz dostaje KAŻDY wpis, nie tylko scalany: producent z jednym cennikiem też musi dać się
        // odnaleźć przy następnym imporcie, inaczej powstałby obok niego drugi wiersz.
        $keyed = $this->fillMissingKeys();
        if ($keyed > 0) {
            $this->info('Uzupełniono klucz producenta w '.$keyed.' wpisach.');
        }

        $moved = 0;
        foreach ($groups as $rows) {
            $moved += $this->collapse($rows);
        }

        $this->info('Zwinięto. Przeniesionych aktualizacji do dziennika: '.$moved.'.');

        return self::SUCCESS;
    }

    /**
     * Wpisy bez klucza producenta — po zwinięciu każdy musi go mieć, także ten jedyny w swojej grupie.
     *
     * @return Collection<int, PriceList>
     */
    private function rowsWithoutKey(): Collection
    {
        return PriceList::query()
            ->where(static fn ($query) => $query->whereNull('manufacturer_key')->orWhere('manufacturer_key', ''))
            ->get();
    }

    private function fillMissingKeys(): int
    {
        $n = 0;
        foreach ($this->rowsWithoutKey() as $row) {
            $key = PriceList::manufacturerKey((string) $row->manufacturer);
            if ($key === '') {
                continue;
            }
            $row->forceFill(['manufacturer_key' => $key])->save();
            $n++;
        }

        return $n;
    }

    /**
     * Grupy po kluczu producenta, wyłącznie te z więcej niż jednym wpisem. Wpis bez nazwy producenta
     * zostaje nietknięty — nie ma po czym go przypisać, a zgadywanie scaliłoby obce katalogi.
     *
     * @return Collection<string, Collection<int, PriceList>>
     */
    private function groups(): Collection
    {
        return PriceList::query()
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (PriceList $list): string => PriceList::manufacturerKey((string) $list->manufacturer))
            ->reject(static fn (Collection $rows, string $key): bool => $key === '' || $rows->count() < 2);
    }

    /**
     * @param  Collection<string, Collection<int, PriceList>>  $groups
     * @return list<array<int, string>>
     */
    private function previewRows(Collection $groups): array
    {
        $limit = (int) $this->option('limit');
        $out = [];
        foreach ($groups as $key => $rows) {
            $survivor = $rows->last();
            $names = $rows->pluck('manufacturer')->unique()->values()->all();
            $products = [];
            foreach ($rows as $row) {
                foreach ($row->product_ids ?? [] as $id) {
                    $products[(int) $id] = true;
                }
            }
            $out[] = [
                (string) $key,
                implode(' / ', array_map(static fn ($name): string => (string) $name, $names)),
                (string) $rows->count(),
                '#'.$survivor?->id.' '.(string) $survivor?->version,
                (string) count($products),
            ];
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, PriceList>  $rows
     */
    private function collapse(Collection $rows): int
    {
        /** @var PriceList $survivor */
        $survivor = $rows->last();
        $others = $rows->filter(static fn (PriceList $row): bool => (int) $row->id !== (int) $survivor->id);

        return DB::transaction(function () use ($survivor, $others, $rows): int {
            $moved = 0;
            $productIds = [];
            foreach ($rows as $row) {
                foreach ($row->product_ids ?? [] as $id) {
                    $productIds[(int) $id] = true;
                }
                $moved += $this->archive($row, (int) $survivor->id);
            }

            foreach ($others as $row) {
                ProductSourcePrice::query()
                    ->where('price_list_id', $row->id)
                    ->update(['price_list_id' => $survivor->id]);
                ProductPriceHistory::query()
                    ->where('price_list_id', $row->id)
                    ->update(['price_list_id' => $survivor->id]);
                B2bAccount::query()
                    ->where('last_price_list_id', $row->id)
                    ->update(['last_price_list_id' => $survivor->id]);
                $row->delete();
            }

            $survivor->forceFill([
                'manufacturer_key' => PriceList::manufacturerKey((string) $survivor->manufacturer),
                'product_ids' => array_map('intval', array_keys($productIds)),
            ])->save();

            return $moved;
        });
    }

    /** Raport wpisu do dziennika; wpis już przeniesiony (ma swój ślad) drugi raz nie wchodzi. */
    private function archive(PriceList $row, int $priceListId): int
    {
        $exists = PriceListImport::query()->where('legacy_price_list_id', $row->id)->exists();
        if ($exists) {
            return 0;
        }

        $import = PriceListImport::query()->create([
            'price_list_id' => $priceListId,
            'source' => str_ends_with((string) $row->original_filename, '(API)')
                ? PriceListImport::SOURCE_B2B
                : PriceListImport::SOURCE_FILE,
            'version' => $row->version,
            'original_filename' => $row->original_filename,
            'imported_by' => $row->imported_by,
            'rows_total' => (int) $row->rows_total,
            'products_created' => (int) $row->products_created,
            'products_updated' => (int) $row->products_updated,
            'prices_changed' => (int) $row->prices_changed,
            'rows_skipped' => (int) $row->rows_skipped,
            'errors' => $row->errors,
            'price_changes' => $row->price_changes,
            'updated_products' => $row->updated_products,
            'skipped_details' => $row->skipped_details,
            'product_ids' => $row->product_ids,
            'legacy_price_list_id' => $row->id,
        ]);
        // data zwijanego wpisu zostaje datą aktualizacji — historia ma się układać tak jak działo się naprawdę
        $import->forceFill(['created_at' => $row->created_at, 'updated_at' => $row->updated_at])->save();

        return 1;
    }
}
