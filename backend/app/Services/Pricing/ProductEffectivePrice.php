<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bManufacturerRules;
use App\Support\BrandKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cena obowiązująca karty (products.catalog_price_net, discount_percent, purchase_price, currency) liczona ze slotów
 * product_source_prices. Decyzje użytkownika 15.09.2026:
 * - importy z pliku i z B2B zapisują tylko swój slot i nie nadpisują sobie cen;
 * - CAŁA cena (zakup, katalogowa, rabat, waluta) pochodzi z jednego slotu; bez slotów z ceną cena karty zostaje.
 * Który slot — explain() (23.09.2026): cennik producenta tej marki (konto B2B producenta, potem plik producenta)
 * ma pierwszeństwo przed dystrybutorem wielu marek, bez względu na datę; dalej B2B przed plikiem jak 15.09.
 * Cena zawsze z jednego slotu — waluty i ceny z różnych źródeł się nie mieszają.
 * Karty z aktywnymi wersjami (product_variants, cena karty 0 = „brak ceny”) nie są przeliczane.
 */
final class ProductEffectivePrice
{
    /** Pola ceny karty ustalane przez slot. */
    public const PRICE_FIELDS = ['catalog_price_net', 'discount_percent', 'purchase_price', 'currency'];

    public function __construct(
        private readonly B2bConnectorRegistry $connectors = new B2bConnectorRegistry,
        private readonly B2bManufacturerRules $rules = new B2bManufacturerRules,
    ) {}

    /**
     * Zapis slotu i przeliczenie ceny obowiązującej w jednej transakcji (blokada karty — dwa importy naraz).
     *
     * @param  array<string, mixed>  $values  pola ProductSourcePrice (bez product_id i source_key)
     * @return array{slot: ProductSourcePrice, previous: ProductSourcePrice|null, card_changes: array<string, array{0: mixed, 1: mixed}>}
     */
    public function saveSlot(Product $product, string $sourceKey, array $values): array
    {
        return DB::transaction(function () use ($product, $sourceKey, $values): array {
            $this->lock($product);

            $slot = ProductSourcePrice::query()
                ->where('product_id', $product->id)
                ->where('source_key', $sourceKey)
                ->first();
            $previous = null;
            if ($slot !== null) {
                $previous = $slot->replicate();
                $previous->id = $slot->id;
            }

            $slot ??= new ProductSourcePrice(['product_id' => $product->id, 'source_key' => $sourceKey]);
            $slot->fill($values);
            $slot->checked_at = $values['checked_at'] ?? Carbon::now();
            $slot->save();

            return ['slot' => $slot, 'previous' => $previous, 'card_changes' => $this->refreshLocked($product)];
        });
    }

    /**
     * Usunięcie slotu (np. usunięty cennik z pliku, usunięte konto B2B) i przeliczenie ceny obowiązującej.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function deleteSlot(Product $product, string $sourceKey): array
    {
        return DB::transaction(function () use ($product, $sourceKey): array {
            $this->lock($product);
            ProductSourcePrice::query()->where('product_id', $product->id)->where('source_key', $sourceKey)->delete();

            return $this->refreshLocked($product);
        });
    }

    /**
     * Przeliczenie ceny obowiązującej ze slotów (w transakcji z blokadą karty).
     *
     * @return array<string, array{0: mixed, 1: mixed}> zmienione pola karty [stara, nowa]; [] = bez zmian
     */
    public function refresh(Product $product): array
    {
        return DB::transaction(function () use ($product): array {
            $this->lock($product);

            return $this->refreshLocked($product);
        });
    }

    /**
     * Cena obowiązująca wg slotów bez zapisu — null, gdy slotów brak albo karta ma aktywne wersje.
     *
     * @return array{catalog_price_net: mixed, discount_percent: mixed, purchase_price: mixed, currency: mixed, source_key: string}|null
     */
    public function resolve(Product $product): ?array
    {
        $slot = $this->winningSlot($product);
        if ($slot === null) {
            return null;
        }

        return [
            'catalog_price_net' => $slot->catalog_price_net ?? $slot->purchase_price,
            // brak rabatu w slocie (np. slot odtworzony z historii, która rabatu nie ma) to brak informacji, nie 0% —
            // rabat karty zostaje (raport migracji 15.09.2026: 13002 kart straciłoby rabat przy pierwszym przeliczeniu)
            'discount_percent' => $slot->discount_percent ?? $product->discount_percent,
            'purchase_price' => $slot->purchase_price ?? $slot->catalog_price_net,
            'currency' => $slot->currency ?? $product->currency,
            'source_key' => (string) $slot->source_key,
        ];
    }

    /**
     * Niezapisana kopia karty z cenami danego slotu — do raportów zmian cen (detectPriceChange / summarizeUpdate),
     * które mają porównywać z poprzednią ceną TEGO źródła, a nie z ceną obowiązującą. Bez slotu — kopia z ceną
     * obowiązującą (pierwszy import źródła porównuje z tym, co było na karcie).
     */
    public function cardWithSlotPrices(Product $product, ?ProductSourcePrice $slot): Product
    {
        $copy = clone $product;
        if ($slot === null) {
            return $copy;
        }
        $attributes = $product->getAttributes();
        $copy->setRawAttributes([
            ...$attributes,
            'catalog_price_net' => $slot->getAttributes()['catalog_price_net'] ?? $slot->getAttributes()['purchase_price'] ?? null,
            'discount_percent' => $slot->getAttributes()['discount_percent'] ?? ($attributes['discount_percent'] ?? 0),
            'purchase_price' => $slot->getAttributes()['purchase_price'] ?? $slot->getAttributes()['catalog_price_net'] ?? null,
            'currency' => $slot->getAttributes()['currency'] ?? ($attributes['currency'] ?? null),
        ], true);

        return $copy;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function refreshLocked(Product $product): array
    {
        $product->refresh();
        $resolved = $this->resolve($product);
        if ($resolved === null) {
            return [];
        }

        $changes = [];
        foreach (self::PRICE_FIELDS as $field) {
            $old = $product->getAttribute($field);
            $new = $resolved[$field];
            $same = $field === 'currency'
                ? strtoupper((string) $old) === strtoupper((string) $new)
                : abs((float) $old - (float) $new) < 0.005;
            if (! $same) {
                $changes[$field] = [$old, $new];
                $product->setAttribute($field, $new);
            }
        }
        if ($changes !== []) {
            $product->save();
        }

        return $changes;
    }

    /**
     * Który slot ustala cenę karty i dlaczego pozostałe nie. Kolejność (decyzje użytkownika 15.09 i 23.09.2026):
     * 1) konto B2B będące cennikiem producenta tej marki (najświeżej sprawdzone), 2) cennik producenta z pliku,
     * 3) konto dystrybutora (najświeżej sprawdzone), 4) inny plik — także cennik producenta oznaczony jako
     * sugerowany (price_lists.suggested_prices: ceny sugerowane bez cen zakupu). Pomijane: slot bez ceny, slot konta, które
     * ma wyłączoną cenę tego producenta (okno „Producenci”). Slot zostaje w bazie — nic nie kasujemy.
     * Karta z aktywnymi wersjami: winner = null, powody puste (ceny są w wersjach).
     *
     * @return array{winner: ProductSourcePrice|null, reasons: array<string, string>} powody po source_key
     */
    public function explain(Product $product): array
    {
        if ($this->hasActiveVariants($product)) {
            return ['winner' => null, 'reasons' => []];
        }
        $slots = ProductSourcePrice::query()->where('product_id', $product->id)->with('priceList')->get();
        if ($slots->isEmpty()) {
            return ['winner' => null, 'reasons' => []];
        }

        $accountIds = $slots->filter(static fn (ProductSourcePrice $s): bool => $s->isB2b())
            ->map(static fn (ProductSourcePrice $s): int => self::accountIdOf($s))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $accounts = $accountIds === [] ? collect() : B2bAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');
        // producent w brzmieniu konta (b2b_product_links.manufacturer), inaczej z karty
        $linkManufacturers = $accountIds === [] ? [] : B2bProductLink::query()
            ->where('product_id', $product->id)
            ->whereIn('b2b_account_id', $accountIds)
            ->whereNotNull('manufacturer')
            ->pluck('manufacturer', 'b2b_account_id')
            ->all();
        $disabled = $this->rules->priceDisabled($accountIds);
        $cardManufacturer = (string) $product->manufacturer;

        $reasons = [];
        $own = [];
        $ownFile = null;
        $distributors = [];
        $otherFile = null;
        foreach ($slots as $slot) {
            $key = (string) $slot->source_key;
            if (! self::hasPrice($slot)) {
                $reasons[$key] = 'brak ceny w tym źródle';

                continue;
            }
            if (! $slot->isB2b()) {
                if ($key !== ProductSourcePrice::SOURCE_FILE) {
                    continue;
                }
                $listManufacturer = (string) ($slot->priceList?->manufacturer ?? '');
                // cennik sugerowany (bez cen zakupu, np. „ATG-sugerowany”) nie jest cennikiem zakupu u producenta
                $suggested = (bool) ($slot->priceList?->suggested_prices ?? false);
                if (! $suggested && $listManufacturer !== '' && BrandKey::same($listManufacturer, $cardManufacturer)) {
                    $ownFile = $slot;
                } else {
                    $otherFile = $slot;
                }

                continue;
            }
            $accountId = self::accountIdOf($slot);
            $manufacturer = (string) ($linkManufacturers[$accountId] ?? $cardManufacturer);
            if (isset($disabled[$accountId][B2bManufacturerRules::key($manufacturer)])) {
                $reasons[$key] = 'cena producenta '.$manufacturer.' wyłączona w tym cenniku';

                continue;
            }
            $account = $accounts->get($accountId);
            $brands = $account !== null ? $this->connectors->brandsForKey($this->connectors->keyForAccount($account)) : [];
            $isOwn = false;
            foreach ($brands as $brand) {
                $isOwn = $isOwn || BrandKey::same($brand, $manufacturer);
            }
            if ($isOwn) {
                $own[] = $slot;
            } else {
                $distributors[] = $slot;
            }
        }

        $freshest = static fn (array $list): ?ProductSourcePrice => collect($list)
            ->sortByDesc(static fn (ProductSourcePrice $s): int => $s->checked_at?->getTimestamp() ?? 0)
            ->first();
        $winner = $freshest($own) ?? $ownFile ?? $freshest($distributors) ?? $otherFile;

        if ($winner !== null && ($freshest($own) !== null || $ownFile !== null)) {
            $label = $winner->isB2b() ? 'konto B2B producenta' : 'plik cennika producenta';
            foreach ($distributors as $slot) {
                $reasons[(string) $slot->source_key] = 'pierwszeństwo ma cennik producenta ('.$label.')';
            }
            if ($otherFile !== null) {
                $reasons[(string) $otherFile->source_key] = 'pierwszeństwo ma cennik producenta ('.$label.')';
            }
        }
        // pozostałe przegrane sloty z ceną — każda cena na karcie ma powiedzieć, czemu nie obowiązuje
        if ($winner !== null) {
            foreach ([...$own, ...array_filter([$ownFile, $otherFile]), ...$distributors] as $slot) {
                $key = (string) $slot->source_key;
                if ($key === (string) $winner->source_key || isset($reasons[$key])) {
                    continue;
                }
                $reasons[$key] = match (true) {
                    $slot->isB2b() => 'cenę ustala świeżej sprawdzone konto B2B',
                    (bool) ($slot->priceList?->suggested_prices ?? false) => 'cennik sugerowany (bez cen zakupu) — pierwszeństwo ma cena z konta B2B',
                    in_array($winner, $own, true) => 'pierwszeństwo ma konto B2B producenta',
                    default => 'pierwszeństwo ma cena z konta B2B',
                };
            }
        }

        return ['winner' => $winner, 'reasons' => $reasons];
    }

    /**
     * Konta B2B, które są cennikiem producenta tej marki i mają włączoną jego cenę — tylko ich slot wygrywa
     * z plikiem cennika producenta (explain). Do komunikatów przy rabatach cennika z pliku.
     *
     * @return list<int>
     */
    public function ownB2bAccountIds(string $manufacturer): array
    {
        $ids = [];
        foreach (B2bAccount::query()->get() as $account) {
            foreach ($this->connectors->brandsForKey($this->connectors->keyForAccount($account)) as $brand) {
                if (BrandKey::same($brand, $manufacturer)) {
                    $ids[] = (int) $account->id;

                    break;
                }
            }
        }
        $disabled = $this->rules->priceDisabled($ids);
        $key = B2bManufacturerRules::key($manufacturer);

        return array_values(array_filter($ids, static fn (int $id): bool => ! isset($disabled[$id][$key])));
    }

    private function winningSlot(Product $product): ?ProductSourcePrice
    {
        return $this->explain($product)['winner'];
    }

    private static function hasPrice(ProductSourcePrice $slot): bool
    {
        return (float) ($slot->purchase_price ?? 0) > 0 || (float) ($slot->catalog_price_net ?? 0) > 0;
    }

    private static function accountIdOf(ProductSourcePrice $slot): int
    {
        return (int) ($slot->b2b_account_id ?? (int) substr((string) $slot->source_key, 4));
    }

    private function lock(Product $product): void
    {
        Product::query()->whereKey($product->id)->lockForUpdate()->first();
    }

    private function hasActiveVariants(Product $product): bool
    {
        return ProductVariant::query()->where('product_id', $product->id)->whereNull('removed_at')->exists();
    }
}
