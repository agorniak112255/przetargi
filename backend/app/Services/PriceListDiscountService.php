<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssortmentGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Services\Pricing\ProductEffectivePrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Zmiana rabatu cennika z pliku po imporcie. Rabat dotyczy tylko cen z tego cennika (sloty „file” z jego
 * price_list_id): zakup = katalogowa × (1 − rabat). Cena obowiązująca karty przeliczana jest ze slotów, więc
 * karta z ceną z konta B2B zostaje przy cenie B2B — zmienia się tylko cena z pliku.
 * Przy grupach asortymentowych rabat ustawia się per grupa (jak przy imporcie), reszta kart ma rabat wspólny.
 */
final class PriceListDiscountService
{
    public function __construct(
        private readonly ProductEffectivePrice $effectivePrices,
    ) {}

    /**
     * @return array{
     *     groups: list<array{id: int, name: string, discount_percent: float, product_count: int}>,
     *     ungrouped: array{product_count: int, discount_percent: float|null, mixed: bool},
     *     product_count: int,
     *     b2b_priced_count: int
     * }
     */
    public function summary(PriceList $priceList): array
    {
        $slots = $this->slots($priceList);
        $groupIds = $this->groupIdsByProduct($slots);
        $groups = $this->groups($groupIds);

        $byGroup = [];
        $ungroupedDiscounts = [];
        foreach ($slots as $slot) {
            $groupId = $groupIds[(int) $slot->product_id] ?? null;
            if ($groupId !== null && isset($groups[$groupId])) {
                $byGroup[$groupId] = ($byGroup[$groupId] ?? 0) + 1;

                continue;
            }
            // brak rabatu w slocie to brak informacji, nie 0% (jak w ProductEffectivePrice::resolve)
            $ungroupedDiscounts[] = $slot->discount_percent !== null ? (string) round((float) $slot->discount_percent, 2) : '';
        }

        $groupRows = [];
        foreach ($byGroup as $groupId => $count) {
            $group = $groups[$groupId];
            $groupRows[] = [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'discount_percent' => (float) $group->discount_percent,
                'product_count' => $count,
            ];
        }
        usort($groupRows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        // rabat „reszty” pokazujemy tylko, gdy jest wspólny — przy różnych wartościach nie ma jednej liczby do pokazania
        $distinct = array_values(array_unique($ungroupedDiscounts));

        return [
            'groups' => $groupRows,
            'ungrouped' => [
                'product_count' => count($ungroupedDiscounts),
                'discount_percent' => count($distinct) === 1 && $distinct[0] !== '' ? (float) $distinct[0] : null,
                'mixed' => count($distinct) > 1,
            ],
            'product_count' => $slots->count(),
            'b2b_priced_count' => $this->b2bPricedCount($slots),
        ];
    }

    /**
     * @param  array<int, float>  $groupDiscounts  assortment_group_id => rabat %
     * @return array{products_changed: int, b2b_priced: int}
     */
    public function apply(PriceList $priceList, array $groupDiscounts, ?float $ungroupedDiscount): array
    {
        $slots = $this->slots($priceList);
        $groupIds = $this->groupIdsByProduct($slots);
        $groups = $this->groups($groupIds);

        $changed = 0;
        $b2bPriced = 0;
        DB::transaction(function () use (
            $priceList, $slots, $groupIds, $groups, $groupDiscounts, $ungroupedDiscount, &$changed, &$b2bPriced
        ): void {
            foreach ($groupDiscounts as $groupId => $discount) {
                if (isset($groups[$groupId])) {
                    $groups[$groupId]->update(['discount_percent' => $discount]);
                }
            }
            // rabat wspólny cennika bez grup zostaje przy producencie — kolejny import podpowie go w formularzu
            if ($groups === [] && $ungroupedDiscount !== null) {
                AssortmentGroup::query()->updateOrCreate(
                    ['manufacturer' => (string) $priceList->manufacturer, 'name' => AssortmentGroup::GLOBAL_NAME],
                    ['discount_percent' => $ungroupedDiscount, 'is_global' => true],
                );
            }

            foreach ($slots as $slot) {
                $groupId = $groupIds[(int) $slot->product_id] ?? null;
                $discount = $groupId !== null && isset($groups[$groupId])
                    ? ($groupDiscounts[$groupId] ?? null)
                    : $ungroupedDiscount;
                if ($discount === null) {
                    continue;
                }
                $catalog = (float) $slot->catalog_price_net;
                $purchase = round($catalog * (1 - ($discount / 100)), 2);
                if (abs((float) $slot->discount_percent - $discount) < 0.005
                    && abs((float) $slot->purchase_price - $purchase) < 0.005) {
                    continue;
                }

                $product = Product::query()->find($slot->product_id);
                if ($product === null) {
                    continue;
                }
                $this->effectivePrices->saveSlot($product, ProductSourcePrice::SOURCE_FILE, [
                    'discount_percent' => $discount,
                    'purchase_price' => $purchase,
                    // data sprawdzenia ceny z pliku zostaje — zmienił się rabat, nie odczyt cennika
                    'checked_at' => $slot->checked_at,
                ]);
                // slot B2B wygrywa z plikiem (ProductEffectivePrice) — cena karty się nie zmieni
                if ($this->hasB2bSlot((int) $product->id)) {
                    $b2bPriced++;
                }
                ProductPriceHistory::query()->create([
                    'product_id' => $product->id,
                    'price_list_id' => $priceList->id,
                    'catalog_price_net' => $slot->catalog_price_net,
                    'purchase_price' => $purchase,
                    'source' => 'price_list_discount',
                ]);
                $changed++;
            }
        });

        return ['products_changed' => $changed, 'b2b_priced' => $b2bPriced];
    }

    /**
     * Sloty cen z tego cennika z ceną katalogową — bez niej rabat nie ma od czego liczyć zakupu.
     *
     * @return Collection<int, ProductSourcePrice>
     */
    private function slots(PriceList $priceList): Collection
    {
        return ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->where('price_list_id', $priceList->id)
            ->where('catalog_price_net', '>', 0)
            ->get();
    }

    /**
     * @param  Collection<int, ProductSourcePrice>  $slots
     * @return array<int, int|null> product_id => assortment_group_id
     */
    private function groupIdsByProduct(Collection $slots): array
    {
        return Product::query()
            ->whereIn('id', $slots->pluck('product_id')->all())
            ->pluck('assortment_group_id', 'id')
            ->map(static fn ($id): ?int => $id !== null ? (int) $id : null)
            ->all();
    }

    /**
     * Grupy asortymentowe kart cennika (bez grupy „cały asortyment” — to rabat wspólny, nie grupa).
     *
     * @param  array<int, int|null>  $groupIds
     * @return array<int, AssortmentGroup>
     */
    private function groups(array $groupIds): array
    {
        $ids = array_values(array_unique(array_filter($groupIds)));
        if ($ids === []) {
            return [];
        }

        return AssortmentGroup::query()
            ->whereIn('id', $ids)
            ->where('is_global', false)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  Collection<int, ProductSourcePrice>  $slots
     */
    private function b2bPricedCount(Collection $slots): int
    {
        return ProductSourcePrice::query()
            ->whereIn('product_id', $slots->pluck('product_id')->all())
            ->where('source_key', 'like', 'b2b:%')
            ->distinct()
            ->count('product_id');
    }

    private function hasB2bSlot(int $productId): bool
    {
        return ProductSourcePrice::query()
            ->where('product_id', $productId)
            ->where('source_key', 'like', 'b2b:%')
            ->exists();
    }
}
