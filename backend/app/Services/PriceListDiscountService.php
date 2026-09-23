<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssortmentGroup;
use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Services\Pricing\ProductEffectivePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Zmiana rabatu cennika z pliku po imporcie. Rabat dotyczy tylko cen z tego cennika (sloty „file” z jego
 * price_list_id): zakup = katalogowa × (1 − rabat). Cena obowiązująca karty przeliczana jest ze slotów
 * (ProductEffectivePrice::explain): cennik producenta z pliku przegrywa tylko z kontem B2B tego producenta (z włączoną
 * ceną) — karta z ceną z takiego konta zostaje przy niej, zmienia się tylko cena z pliku.
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
            'b2b_priced_count' => $this->b2bPricedCount($slots, $this->ownAccountKeys($priceList)),
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

        $ownKeys = $this->ownAccountKeys($priceList);

        $changed = 0;
        $b2bPriced = 0;
        DB::transaction(function () use (
            $priceList, $slots, $groupIds, $groups, $groupDiscounts, $ungroupedDiscount, $ownKeys, &$changed, &$b2bPriced
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
                // z cennikiem producenta z pliku wygrywa tylko konto B2B producenta — tam cena karty się nie zmieni
                if ($this->hasOwnB2bPrice((int) $product->id, $ownKeys)) {
                    $b2bPriced++;
                }
                ProductPriceHistory::query()->create([
                    'product_id' => $product->id,
                    'price_list_id' => $priceList->id,
                    'catalog_price_net' => $slot->catalog_price_net,
                    'purchase_price' => $purchase,
                    'currency' => $slot->currency,
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
     * Sloty kont B2B, które są cennikiem producenta tego cennika i mają włączoną jego cenę — tylko one wygrywają
     * z plikiem producenta (ProductEffectivePrice::explain). Konto dystrybutora wielu marek z plikiem przegrywa.
     *
     * @return list<string> source_key slotów
     */
    private function ownAccountKeys(PriceList $priceList): array
    {
        // cennik sugerowany (bez cen zakupu) przegrywa z każdym kontem B2B, nie tylko z kontem producenta
        $ids = $priceList->suggested_prices
            ? B2bAccount::query()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()
            : $this->effectivePrices->ownB2bAccountIds((string) $priceList->manufacturer);

        return array_map(static fn (int $id): string => ProductSourcePrice::b2bKey($id), $ids);
    }

    /**
     * @param  Collection<int, ProductSourcePrice>  $slots
     * @param  list<string>  $ownKeys
     */
    private function b2bPricedCount(Collection $slots, array $ownKeys): int
    {
        if ($ownKeys === []) {
            return 0;
        }

        $count = 0;
        foreach ($slots->pluck('product_id')->chunk(1000) as $chunk) {
            $count += $this->ownB2bPriced($ownKeys)
                ->whereIn('product_id', $chunk->all())
                ->distinct()
                ->count('product_id');
        }

        return $count;
    }

    /**
     * @param  list<string>  $ownKeys
     */
    private function hasOwnB2bPrice(int $productId, array $ownKeys): bool
    {
        return $ownKeys !== [] && $this->ownB2bPriced($ownKeys)->where('product_id', $productId)->exists();
    }

    /**
     * Slot bez ceny nie ustala ceny karty (explain: „brak ceny w tym źródle”), więc się nie liczy.
     *
     * @param  list<string>  $ownKeys
     * @return Builder<ProductSourcePrice>
     */
    private function ownB2bPriced(array $ownKeys): Builder
    {
        return ProductSourcePrice::query()
            ->whereIn('source_key', $ownKeys)
            ->where(static fn (Builder $q) => $q->where('purchase_price', '>', 0)->orWhere('catalog_price_net', '>', 0));
    }
}
