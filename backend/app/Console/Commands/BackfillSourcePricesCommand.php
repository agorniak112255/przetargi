<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\Pricing\ProductEffectivePrice;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Wdrożenie slotów cen per źródło (decyzja użytkownika 15.09.2026): odtwarza product_source_prices z danych, które
 * już są w bazie, bez przeliczania cen kart (ostatni wiersz historii cen = cena karty).
 * - slot pliku: ostatni wiersz historii „price_list_import” karty;
 * - slot konta B2B: konto z b2b_product_links, ceny z ostatniego wiersza historii B2B („b2b_api” albo „b2b:…”),
 *   a gdy karta ma powiązanie bez takiego wiersza — z karty.
 * Historia nie ma waluty ani rabatu — waluta i pack_qty z karty, rabat null. Karty z aktywnymi wersjami są pomijane
 * (ceny mają wersje), istniejące sloty nie są nadpisywane.
 *
 * Kontrola: w transakcji porcji sloty są wstawiane i dla każdej karty liczona jest cena obowiązująca
 * (ProductEffectivePrice::resolve, ta sama logika co przy importach). Karta z ceną inną niż obecna nie dostaje slotów
 * (decyzja człowieka). Bez --apply transakcja każdej porcji jest wycofywana — nic nie zostaje zapisane.
 */
final class BackfillSourcePricesCommand extends Command
{
    protected $signature = 'prices:backfill-sources
        {--apply : Zapisz sloty (bez tego tylko raport)}';

    protected $description = 'Odtwarza ceny per źródło (cennik z pliku / konto B2B) z historii cen i powiązań B2B';

    private const FILE_HISTORY_SOURCE = 'price_list_import';

    /** Dawna nazwa źródła synchronizacji B2B (przed „b2b:{łącznik}”). */
    private const LEGACY_B2B_HISTORY_SOURCE = 'b2b_api';

    private const B2B_HISTORY_PREFIX = 'b2b:';

    private const CHUNK = 500;

    private const LIST_LIMIT = 20;

    private const PRICE_TOLERANCE = 0.005;

    private ProductEffectivePrice $prices;

    /** @var array<int, B2bAccount> */
    private array $accounts = [];

    /** @var array<string, int> source_key => liczba slotów */
    private array $created = [];

    private int $cards = 0;

    private int $existing = 0;

    private int $withVariants = 0;

    private int $linkWithoutHistory = 0;

    private int $historyWithoutLink = 0;

    /** @var list<string> */
    private array $multiAccount = [];

    /** @var list<string> */
    private array $connectorMismatch = [];

    /** @var list<string> */
    private array $differences = [];

    private int $withheldSlots = 0;

    private int $discountDiffers = 0;

    public function handle(ProductEffectivePrice $prices): int
    {
        // ta sama instancja komendy bywa użyta kilka razy w jednym procesie (Artisan::call) — liczniki od zera
        $this->created = $this->multiAccount = $this->connectorMismatch = $this->differences = [];
        $this->cards = $this->existing = $this->withVariants = $this->linkWithoutHistory = 0;
        $this->historyWithoutLink = $this->withheldSlots = $this->discountDiffers = 0;

        $this->prices = $prices;
        $apply = (bool) $this->option('apply');
        $this->accounts = B2bAccount::query()->get()->keyBy('id')->all();

        $this->line($apply
            ? 'Tryb: zapis (--apply).'
            : 'Tryb: raport bez zapisu (sloty liczone w transakcji wycofywanej po każdej porcji).');

        Product::query()
            ->select(['id', 'sku', 'catalog_price_net', 'discount_percent', 'purchase_price', 'currency', 'pack_qty'])
            ->where(function (EloquentBuilder $query): void {
                $query->whereExists(function (Builder $history): void {
                    $history->from('product_price_history')
                        ->whereColumn('product_price_history.product_id', 'products.id')
                        ->where(fn (Builder $sources) => $this->knownSources($sources, 'product_price_history.source'));
                })->orWhereExists(function (Builder $links): void {
                    $links->from('b2b_product_links')->whereColumn('b2b_product_links.product_id', 'products.id');
                });
            })
            ->chunkById(self::CHUNK, function (Collection $products) use ($apply): void {
                $this->processChunk($products, $apply);
            });

        $this->report($apply);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function processChunk(Collection $products, bool $apply): void
    {
        $ids = $products->modelKeys();

        $variantCards = array_flip(ProductVariant::query()
            ->whereIn('product_id', $ids)
            ->whereNull('removed_at')
            ->distinct()
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());

        /** @var array<int, array{file?: ProductPriceHistory, b2b?: ProductPriceHistory}> $lastRows */
        $lastRows = [];
        $rows = ProductPriceHistory::query()
            ->whereIn('product_id', $ids)
            ->where(fn (EloquentBuilder $sources) => $this->knownSources($sources, 'source'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $kind = $row->source === self::FILE_HISTORY_SOURCE ? 'file' : 'b2b';
            $lastRows[(int) $row->product_id][$kind] = $row;
        }

        $links = B2bProductLink::query()->whereIn('product_id', $ids)->orderBy('id')->get()
            ->groupBy(static fn (B2bProductLink $link): int => (int) $link->product_id);

        $existing = [];
        foreach (ProductSourcePrice::query()->whereIn('product_id', $ids)->get(['product_id', 'source_key']) as $slot) {
            $existing[$slot->product_id.'|'.$slot->source_key] = true;
        }

        DB::beginTransaction();
        try {
            foreach ($products as $product) {
                $id = (int) $product->id;
                if (isset($variantCards[$id])) {
                    $this->withVariants++;

                    continue;
                }

                $planned = $this->plannedSlots($product, $lastRows[$id] ?? [], $links->get($id), $existing);
                if ($planned === []) {
                    continue;
                }

                $newIds = [];
                foreach ($planned as $values) {
                    $newIds[] = ProductSourcePrice::query()->create($values)->id;
                }

                $resolved = $this->prices->resolve($product);
                $difference = $this->priceDifference($product, $resolved);
                if ($difference !== null) {
                    // nie zgadzamy ceny karty z danymi po cichu — sloty tej karty nie zostają zapisane
                    ProductSourcePrice::query()->whereKey($newIds)->delete();
                    $this->differences[] = $difference;
                    $this->withheldSlots += count($planned);

                    continue;
                }

                $this->cards++;
                foreach ($planned as $values) {
                    $this->created[$values['source_key']] = ($this->created[$values['source_key']] ?? 0) + 1;
                }
                if ($resolved !== null && abs((float) $product->discount_percent - (float) $resolved['discount_percent']) >= self::PRICE_TOLERANCE) {
                    $this->discountDiffers++;
                }
            }

            $apply ? DB::commit() : DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Sloty do utworzenia dla karty (bez już istniejących).
     *
     * @param  array{file?: ProductPriceHistory, b2b?: ProductPriceHistory}  $history  ostatnie wiersze historii
     * @param  SupportCollection<int, B2bProductLink>|null  $links
     * @param  array<string, true>  $existing  "product_id|source_key"
     * @return list<array<string, mixed>>
     */
    private function plannedSlots(Product $product, array $history, ?SupportCollection $links, array $existing): array
    {
        $common = [
            'product_id' => $product->id,
            'currency' => $product->currency,
            'pack_qty' => $product->pack_qty,
            'migrated' => true,
        ];
        $slots = [];

        $file = $history['file'] ?? null;
        if ($file !== null) {
            $slots[] = [
                ...$common,
                'source_key' => ProductSourcePrice::SOURCE_FILE,
                'b2b_account_id' => null,
                'price_list_id' => $file->price_list_id,
                'catalog_price_net' => $file->catalog_price_net,
                'purchase_price' => $file->purchase_price,
                'discount_percent' => $this->cardDiscountFor($product, $file->catalog_price_net, $file->purchase_price),
                'checked_at' => $file->created_at,
            ];
        }

        $b2b = $history['b2b'] ?? null;
        $byAccount = ($links ?? new SupportCollection)->groupBy(static fn (B2bProductLink $link): int => (int) $link->b2b_account_id);
        if ($b2b !== null && $byAccount->isEmpty()) {
            $this->historyWithoutLink++;
        }
        if ($b2b === null && $byAccount->isNotEmpty()) {
            $this->linkWithoutHistory++;
        }
        if ($byAccount->count() > 1) {
            $this->multiAccount[] = sprintf('%s (konta: %s)', $product->sku, $byAccount->keys()->implode(', '));
        }

        foreach ($byAccount as $accountId => $accountLinks) {
            $account = $this->accounts[(int) $accountId] ?? null;
            if ($b2b !== null && $account !== null && str_starts_with((string) $b2b->source, self::B2B_HISTORY_PREFIX)
                && $b2b->source !== self::B2B_HISTORY_PREFIX.$account->connector) {
                $this->connectorMismatch[] = sprintf('%s: historia %s, konto #%d (%s)', $product->sku, $b2b->source, $accountId, $account->connector);
            }

            $slots[] = [
                ...$common,
                'source_key' => ProductSourcePrice::b2bKey((int) $accountId),
                'b2b_account_id' => (int) $accountId,
                'price_list_id' => $b2b?->price_list_id,
                'catalog_price_net' => $b2b !== null ? $b2b->catalog_price_net : $product->catalog_price_net,
                'purchase_price' => $b2b !== null ? $b2b->purchase_price : $product->purchase_price,
                'discount_percent' => $b2b !== null
                    ? $this->cardDiscountFor($product, $b2b->catalog_price_net, $b2b->purchase_price)
                    : $product->discount_percent,
                'checked_at' => $b2b !== null
                    ? $b2b->created_at
                    : $accountLinks->pluck('last_seen_at')->filter()->max(),
            ];
        }

        return array_values(array_filter($slots, function (array $slot) use ($existing): bool {
            if (isset($existing[$slot['product_id'].'|'.$slot['source_key']])) {
                $this->existing++;

                return false;
            }

            return true;
        }));
    }

    /**
     * Rabat slotu odtworzonego z historii (historia rabatu nie ma): rabat karty, gdy ceny wiersza historii są cenami
     * karty — to ten sam stan źródła; inaczej null (brak informacji). Bez tego pierwsze przeliczenie ceny obowiązującej
     * zerowałoby rabat (raport 15.09.2026: 13002 kart).
     */
    private function cardDiscountFor(Product $product, mixed $catalog, mixed $purchase): mixed
    {
        return abs((float) $catalog - (float) $product->catalog_price_net) < self::PRICE_TOLERANCE
            && abs((float) $purchase - (float) $product->purchase_price) < self::PRICE_TOLERANCE
            ? $product->discount_percent
            : null;
    }

    /**
     * Opis różnicy ceny obowiązującej ze slotów względem obecnej ceny karty (zakup, katalogowa, waluta); null = zgodna.
     * Rabat liczony osobno — historia cen go nie zawiera.
     *
     * @param  array<string, mixed>|null  $resolved
     */
    private function priceDifference(Product $product, ?array $resolved): ?string
    {
        if ($resolved === null) {
            return null;
        }

        $parts = [];
        foreach (['purchase_price' => 'zakup', 'catalog_price_net' => 'katalogowa'] as $field => $label) {
            $old = $product->getAttribute($field);
            $new = $resolved[$field];
            if ($old === null || $new === null ? $old !== $new : abs((float) $old - (float) $new) >= self::PRICE_TOLERANCE) {
                $parts[] = sprintf('%s %s → %s', $label, $this->money($old), $this->money($new));
            }
        }
        if (strtoupper((string) $product->currency) !== strtoupper((string) $resolved['currency'])) {
            $parts[] = sprintf('waluta %s → %s', $product->currency, $resolved['currency']);
        }

        return $parts === [] ? null : sprintf('%s: %s (źródło: %s)', $product->sku, implode(', ', $parts), $resolved['source_key']);
    }

    private function report(bool $apply): void
    {
        $verb = $apply ? 'Sloty zapisane' : 'Sloty do utworzenia';

        $this->info(sprintf('Karty z nowymi slotami: %d', $this->cards));
        ksort($this->created);
        if ($this->created === []) {
            $this->line($verb.': 0');
        }
        foreach ($this->created as $key => $count) {
            $this->line(sprintf('%s · %s: %d', $verb, $this->sourceLabel($key), $count));
        }
        $this->line(sprintf('Sloty już istniejące (bez zmian): %d', $this->existing));
        $this->line(sprintf('Karty z aktywnymi wersjami (pominięte): %d', $this->withVariants));
        $this->line(sprintf('Karty z linkiem B2B bez historii cen B2B (slot B2B z ceny karty): %d', $this->linkWithoutHistory));
        $this->lineOrWarn($this->historyWithoutLink, sprintf('Karty z historią cen B2B bez linku do konta (slot B2B pominięty): %d', $this->historyWithoutLink));
        $this->listing('Karty z linkami do kilku kont B2B (slot na każde konto)', $this->multiAccount);
        $this->listing('Historia cen B2B z innego łącznika niż konto z linku', $this->connectorMismatch);

        if ($this->differences === []) {
            $this->info('Karty z ceną ze slotów inną niż cena karty: 0');
        } else {
            $this->listing(sprintf(
                'Karty z ceną ze slotów inną niż cena karty — ich sloty (%d) %s, decyzja człowieka',
                $this->withheldSlots,
                $apply ? 'nie zostały zapisane' : 'nie zostaną zapisane',
            ), $this->differences);
        }

        $this->lineOrWarn($this->discountDiffers, sprintf(
            'Karty z rabatem innym niż rabat ze slotów (historia nie ma rabatu — slot dostaje rabat karty, gdy ceny wiersza są cenami karty; ceny kart nie są przeliczane): %d',
            $this->discountDiffers,
        ));
    }

    /**
     * @param  list<string>  $items
     */
    private function listing(string $title, array $items): void
    {
        $this->lineOrWarn(count($items), sprintf('%s: %d', $title, count($items)));
        foreach (array_slice($items, 0, self::LIST_LIMIT) as $item) {
            $this->warn('  '.$item);
        }
        if (count($items) > self::LIST_LIMIT) {
            $this->warn(sprintf('  … i %d więcej', count($items) - self::LIST_LIMIT));
        }
    }

    private function lineOrWarn(int $count, string $message): void
    {
        $count > 0 ? $this->warn($message) : $this->line($message);
    }

    private function sourceLabel(string $sourceKey): string
    {
        if ($sourceKey === ProductSourcePrice::SOURCE_FILE) {
            return $sourceKey.' (cennik z pliku)';
        }
        $account = $this->accounts[(int) substr($sourceKey, strlen(self::B2B_HISTORY_PREFIX))] ?? null;

        return $account === null ? $sourceKey : sprintf('%s (%s, %s)', $sourceKey, $account->connector, $account->username);
    }

    private function money(mixed $value): string
    {
        return $value === null ? 'brak' : number_format((float) $value, 2, '.', '');
    }

    private function knownSources(Builder|EloquentBuilder $query, string $column): void
    {
        $query->where($column, self::FILE_HISTORY_SOURCE)
            ->orWhere($column, self::LEGACY_B2B_HISTORY_SOURCE)
            ->orWhere($column, 'like', self::B2B_HISTORY_PREFIX.'%');
    }
}
