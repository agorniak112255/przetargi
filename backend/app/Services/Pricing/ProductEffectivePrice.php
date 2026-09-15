<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cena obowiązująca karty (products.catalog_price_net, discount_percent, purchase_price, currency) liczona ze slotów
 * product_source_prices. Decyzje użytkownika 15.09.2026:
 * - importy z pliku i z B2B zapisują tylko swój slot i nie nadpisują sobie cen;
 * - gdy karta ma slot B2B, CAŁA cena (zakup, katalogowa, rabat, waluta) pochodzi z B2B — przy kilku kontach z
 *   najświeżej sprawdzonego; inaczej ze slotu pliku; bez slotów cena karty zostaje bez zmian.
 * Cena zawsze z jednego slotu — waluty i ceny z różnych źródeł się nie mieszają.
 * Karty z aktywnymi wersjami (product_variants, cena karty 0 = „brak ceny”) nie są przeliczane.
 */
final class ProductEffectivePrice
{
    /** Pola ceny karty ustalane przez slot. */
    public const PRICE_FIELDS = ['catalog_price_net', 'discount_percent', 'purchase_price', 'currency'];

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
        if ($this->hasActiveVariants($product)) {
            return null;
        }
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

    private function winningSlot(Product $product): ?ProductSourcePrice
    {
        $slots = ProductSourcePrice::query()->where('product_id', $product->id)->get();
        $b2b = $slots->filter(static fn (ProductSourcePrice $s): bool => $s->isB2b())
            ->sortByDesc(static fn (ProductSourcePrice $s): int => $s->checked_at?->getTimestamp() ?? 0)
            ->first();

        return $b2b ?? $slots->firstWhere('source_key', ProductSourcePrice::SOURCE_FILE);
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
