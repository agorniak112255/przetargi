<?php

declare(strict_types=1);

namespace App\Services\Offers;

use App\Models\CampaignItem;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Product;
use App\Models\ProductVariant;
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
     * wejście dla CampaignItemPresenter i CampaignRenderer::renderItems. Nigdy nie zapisywane. Nietrwałe atrybuty dla
     * maila: offer_price_unit (etykieta wybranej jednostki ceny; null = jednostka XL) i offer_sizes (rozmiary pozycji).
     *
     * @param  Collection<int, OfferItem>  $items
     * @param  bool  $gross  cena brutto (Offer::gross) zamiast netto — tylko mail oferty z cenami brutto (OfferRenderer)
     * @return Collection<int, CampaignItem>
     */
    public static function campaignItems(Collection $items, bool $gross = false): Collection
    {
        return $items->values()->map(static fn (OfferItem $item): CampaignItem => (new CampaignItem)->forceFill([
            'id' => (int) $item->id,
            'campaign_id' => null,
            'position' => (int) $item->position,
            'erp_item_id' => $item->erp_item_id !== null ? (int) $item->erp_item_id : null,
            'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
            'promo_price_net' => $gross && $item->price_net !== null ? Offer::gross((float) $item->price_net) : $item->price_net,
            'note' => $item->note,
            'description' => $item->description,
            // przycisk z linkiem handlowca (np. do sklepu) — jak drugi przycisk pozycji kampanii
            'link_url' => $item->link_url,
            'link_label' => $item->link_label,
            'link_color' => $item->link_color,
            'offer_price_unit' => $item->price_unit !== null ? (OfferItem::PRICE_UNITS[$item->price_unit] ?? null) : null,
            'offer_sizes' => $item->sizes,
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
        // rozmiary karty widzianej w wierszu (ta sama co `card`) — kafelki do wpisania rozmiarów pozycji
        $sizeChoices = $this->sizeChoices(array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?int => $row['card'] !== null ? (int) $row['card']['id'] : null,
            $rows,
        )))));

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
            $priceUnit = $item->price_unit !== null && isset(OfferItem::PRICE_UNITS[$item->price_unit]) ? (string) $item->price_unit : null;
            // koszt jest za jednostkę XL (albo sztukę) — przy innej jednostce ceny porównanie „poniżej kosztu” traci sens
            $unitMismatch = OfferItem::unitMismatch($priceUnit, $row['unit']);

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
                // brutto do podpowiedzi w edytorze — niezależnie od price_mode oferty
                'price_gross' => $price !== null ? Offer::gross($price) : null,
                'price_unit' => $priceUnit,
                'price_unit_label' => OfferItem::priceUnitLabel($priceUnit, $row['unit']),
                'unit_mismatch' => $unitMismatch,
                'sizes' => $item->sizes,
                'size_choices' => $row['card'] !== null ? ($sizeChoices[(int) $row['card']['id']] ?? []) : [],
                'note' => $row['note'],
                'description' => $row['description'],
                'card_excerpt' => $row['card_excerpt'],
                'card' => $row['card'],
                'image_url' => $row['image_url'],
                'link' => $row['link'],
                'warnings' => [
                    'below_cost' => ! $unitMismatch && $price !== null && $unitCost !== null && $price < $unitCost,
                    'no_price' => $price === null,
                    'no_image' => (bool) $row['warnings']['no_image'],
                ],
            ];
        }

        return $out;
    }

    /**
     * Etykiety rozmiarów kart (product_variants kind = size, bez usuniętych) w kolejności sort_order — jedno zapytanie.
     * Ten sam rozmiar z kilku kont dostawcy raz.
     *
     * @param  list<int>  $cardIds
     * @return array<int, list<string>>
     */
    private function sizeChoices(array $cardIds): array
    {
        if ($cardIds === []) {
            return [];
        }
        $out = [];
        foreach (ProductVariant::query()->sizes()->active()->whereIn('product_id', $cardIds)
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'product_id', 'label']) as $variant) {
            $label = trim((string) $variant->label);
            if ($label !== '' && ! in_array($label, $out[(int) $variant->product_id] ?? [], true)) {
                $out[(int) $variant->product_id][] = $label;
            }
        }

        return $out;
    }
}
