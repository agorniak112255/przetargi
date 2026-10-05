<?php

declare(strict_types=1);

namespace App\Services\Offers;

use App\Models\CampaignItem;
use App\Models\OfferItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Campaigns\CampaignItemPresenter;
use App\Services\Pricing\SupplierSpecialMask;
use Illuminate\Support\Collection;

/**
 * Kształt pozycji oferty (OfferItem z frontend/src/lib/offers.ts). Nazwa, kod, stan, zdjęcie i wycinek opisu — jak
 * w kampanii (CampaignItemPresenter na przejściowych CampaignItem, niezapisanych); koszt i cena sugerowana:
 * towar XL — średni koszt partii jak w kampanii, karta bez towaru XL — cena zakupu karty w zł widziana przez
 * oglądającego (SupplierSpecialMask: bez uprawnienia cena standardowa zamiast specjalnej). Nie final — testy
 * podmieniają zależności.
 */
class OfferItemPresenter
{
    public function __construct(private readonly CampaignItemPresenter $campaignItems) {}

    /**
     * Pozycje oferty jako przejściowe pozycje kampanii (id i pozycja oferty, cena oferty jako cena kampanii) —
     * wejście dla CampaignItemPresenter i CampaignRenderer::renderItems. Nigdy nie zapisywane.
     *
     * @param  Collection<int, OfferItem>  $items
     * @return Collection<int, CampaignItem>
     */
    public static function campaignItems(Collection $items): Collection
    {
        return $items->values()->map(static fn (OfferItem $item): CampaignItem => (new CampaignItem)->forceFill([
            'id' => (int) $item->id,
            'campaign_id' => null,
            'position' => (int) $item->position,
            'erp_item_id' => $item->erp_item_id !== null ? (int) $item->erp_item_id : null,
            'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
            'promo_price_net' => $item->price_net,
            'note' => $item->note,
            'description' => $item->description,
        ]));
    }

    /**
     * @param  Collection<int, OfferItem>  $items
     * @return list<array<string, mixed>>
     */
    public function presentMany(Collection $items, User $viewer): array
    {
        $items = $items->values();
        if ($items->isEmpty()) {
            return [];
        }
        $rows = $this->campaignItems->presentMany(self::campaignItems($items), $viewer);

        // karty bez towaru XL: koszt = cena zakupu karty w zł po masce ceny specjalnej oglądającego
        $cardIds = $items->filter(static fn (OfferItem $i): bool => $i->erp_item_id === null && $i->product_id !== null)
            ->map(static fn (OfferItem $i): int => (int) $i->product_id)->unique()->values()->all();
        $cards = $cardIds === [] ? new Collection : Product::query()->whereIn('id', $cardIds)
            ->get(['id', 'purchase_price', 'currency', 'catalog_price_net', 'discount_percent'])->keyBy('id');
        $mask = SupplierSpecialMask::forUser($viewer);
        $mask->preload($cardIds);
        $margin = $viewer->defaultMarginPercent();

        $out = [];
        foreach ($rows as $i => $row) {
            /** @var OfferItem $item */
            $item = $items[$i];
            if ($item->erp_item_id !== null) {
                $unitCost = $row['unit_cost'];
                $suggested = $row['suggested_price'];
            } else {
                $card = $item->product_id !== null ? $cards->get((int) $item->product_id) : null;
                $unitCost = $card !== null ? $mask->purchasePln($card) : null;
                $suggested = $unitCost !== null ? round($unitCost * (1 + $margin / 100), 2) : null;
            }
            $price = $item->price_net !== null ? (float) $item->price_net : null;

            $out[] = [
                'id' => (int) $item->id,
                'position' => (int) $item->position,
                'erp_item_id' => $row['erp_item_id'],
                'product_id' => $row['product_id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'stock' => $row['stock'],
                'unit_cost' => $unitCost,
                'suggested_price' => $suggested,
                'price_net' => $price,
                'note' => $row['note'],
                'description' => $row['description'],
                'card_excerpt' => $row['card_excerpt'],
                'card' => $row['card'],
                'image_url' => $row['image_url'],
                'warnings' => [
                    'below_cost' => $price !== null && $unitCost !== null && $price < $unitCost,
                    'no_price' => $price === null,
                    'no_image' => (bool) $row['warnings']['no_image'],
                ],
            ];
        }

        return $out;
    }
}
