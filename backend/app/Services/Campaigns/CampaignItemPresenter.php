<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Erp\ErpItemCards;
use App\Services\Erp\InventoryQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Jedno źródło danych pozycji kampanii: karta (wybrana albo główna z ErpItemCards), nazwa, kod, jednostka, stan
 * magazynów handlowych, koszt z partii, sugerowana cena (koszt × (1 + marża autora)), zdjęcie (absolutny URL
 * z campaigns.public_url), ostrzeżenia. Korzysta z niego API (CampaignController), renderer maila i snapshoty przy starcie.
 * Nie final — testy podmieniają zależności.
 */
class CampaignItemPresenter
{
    public function __construct(private readonly ErpItemCards $cards) {}

    /**
     * Kształt CampaignItem z kontraktu API (bez pól kampanii).
     *
     * @return array<string, mixed>
     */
    public function present(CampaignItem $item, User $author): array
    {
        return $this->presentMany([$item], $author)[0];
    }

    /**
     * Wiele pozycji naraz bez N+1 (jedno zapytanie o karty, zdjęcia, inne kampanie).
     *
     * @param  iterable<CampaignItem>  $items
     * @return list<array<string, mixed>>
     */
    public function presentMany(iterable $items, User $author): array
    {
        $items = Collection::make($items)->values();
        if ($items->isEmpty()) {
            return [];
        }

        // towary XL ze stanem magazynów handlowych i pewnymi powiązaniami (główna karta wg ErpItemCards)
        $erpIds = $items->pluck('erp_item_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        /** @var Collection<int, ErpItem> $erpItems */
        $erpItems = $erpIds === [] ? new Collection : ErpItem::query()
            ->whereIn('id', $erpIds)
            ->select('erp_items.*')
            ->selectRaw(InventoryQuery::quantitySql('trade').' as campaign_stock')
            ->with(ErpItemCards::eagerLinks())
            ->get()
            ->keyBy('id');
        $mainCards = $this->cards->forItems($erpItems);

        // karty wybrane ręcznie dla pozycji
        $chosenIds = $items->pluck('product_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $chosen = $chosenIds === [] ? new Collection : Product::query()->whereIn('id', $chosenIds)->get(['id', 'sku', 'name'])->keyBy('id');

        // propozycja karty (suggested) — tylko dla towaru bez karty
        $suggestions = [];
        if ($erpIds !== []) {
            foreach (ErpItemLink::query()
                ->whereIn('erp_item_id', $erpIds)
                ->where('status', ErpItemLink::STATUS_SUGGESTED)
                ->whereNotNull('product_id')
                ->with('product:id,sku,name')
                ->orderBy('id')
                ->get() as $link) {
                if ($link->product !== null && ! isset($suggestions[(int) $link->erp_item_id])) {
                    $suggestions[(int) $link->erp_item_id] = $link->product;
                }
            }
        }

        // karta użyta w pozycji: wybrana ręcznie, inaczej główna karta towaru XL
        $cardOf = [];
        foreach ($items as $i => $item) {
            if ($item->product_id !== null && $chosen->has((int) $item->product_id)) {
                $p = $chosen->get((int) $item->product_id);
                $cardOf[$i] = ['id' => (int) $p->id, 'sku' => (string) $p->sku, 'name' => (string) $p->name];
            } elseif ($item->product_id === null && $item->erp_item_id !== null && ($mainCards[(int) $item->erp_item_id]['card'] ?? null) !== null) {
                $c = $mainCards[(int) $item->erp_item_id]['card'];
                $cardOf[$i] = ['id' => (int) $c['id'], 'sku' => (string) $c['sku'], 'name' => (string) $c['name']];
            } else {
                $cardOf[$i] = null;
            }
        }

        $imageIds = $this->primaryImageIds([
            ...array_column(array_filter($cardOf), 'id'),
            ...array_map(static fn (Product $p): int => (int) $p->id, $suggestions),
        ]);
        $others = $this->otherCampaigns($items);
        $margin = $author->defaultMarginPercent();
        // opis i normy kart — krótki opis do maila to WYCINEK opisu karty (ProductExcerpt), nie tekst dopisany
        $cardIds = array_column(array_filter($cardOf), 'id');
        $cardTexts = $cardIds === [] ? new Collection : Product::query()->whereIn('id', $cardIds)->get(['id', 'name', 'description', 'norms'])->keyBy('id');

        $out = [];
        foreach ($items as $i => $item) {
            /** @var ErpItem|null $erp */
            $erp = $item->erp_item_id !== null ? $erpItems->get((int) $item->erp_item_id) : null;
            $card = $cardOf[$i];
            $imageId = $card !== null ? ($imageIds[$card['id']] ?? null) : null;
            $stock = $erp !== null ? round((float) $erp->getAttribute('campaign_stock'), 3) : null;
            // średnia cena zakupu towaru na stanie = wartość partii ÷ ilość (jak lista Zapasów)
            $unitCost = $erp !== null && $erp->stock_value !== null && (float) $erp->stock_total > 0
                ? round((float) $erp->stock_value / (float) $erp->stock_total, 4)
                : null;
            $promo = $item->promo_price_net !== null ? (float) $item->promo_price_net : null;
            $suggestion = $card === null && $erp !== null ? ($suggestions[(int) $erp->id] ?? null) : null;
            $syncedAt = $erp?->stock_synced_at ?? $erp?->synced_at;

            $out[] = [
                'id' => (int) $item->id,
                'position' => (int) $item->position,
                'erp_item_id' => $item->erp_item_id !== null ? (int) $item->erp_item_id : null,
                'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                'code' => (string) ($erp?->code ?? $card['sku'] ?? ''),
                'name' => (string) ($card['name'] ?? $erp?->name ?? ''),
                'unit' => $erp?->unit !== null && $erp->unit !== '' ? (string) $erp->unit : null,
                'stock' => $stock,
                'stock_synced_at' => $syncedAt?->toIso8601String(),
                'unit_cost' => $unitCost,
                'suggested_price' => $unitCost !== null ? round($unitCost * (1 + $margin / 100), 2) : null,
                'promo_price_net' => $promo,
                'price_before_net' => $item->price_before_net !== null ? (float) $item->price_before_net : null,
                'note' => $item->note,
                // opis wpisany przy pozycji; null = w mailu idzie card_excerpt
                'description' => $item->description,
                // drugi przycisk w mailu (np. do sklepu); null = tylko „Zapytaj o ofertę”
                'link' => $item->link_url !== null && $item->link_label !== null ? [
                    'url' => (string) $item->link_url,
                    'label' => (string) $item->link_label,
                    'color' => in_array($item->link_color, CampaignBlocks::BRAND_COLORS, true) ? (string) $item->link_color : CampaignBlocks::DEFAULT_COLOR,
                ] : null,
                'card_excerpt' => $card !== null && $cardTexts->has($card['id'])
                    ? ProductExcerpt::fromDescription($cardTexts->get($card['id'])->description, (string) $cardTexts->get($card['id'])->name)
                    : null,
                'card_norms' => $card !== null && $cardTexts->has($card['id']) ? ProductExcerpt::norms($cardTexts->get($card['id'])->norms) : [],
                'card' => $card === null ? null : [...$card, 'thumb_url' => $imageId !== null ? $this->thumbUrl($imageId) : null],
                'card_suggestion' => $suggestion === null ? null : [
                    'product_id' => (int) $suggestion->id,
                    'sku' => (string) $suggestion->sku,
                    'name' => (string) $suggestion->name,
                    'thumb_url' => isset($imageIds[(int) $suggestion->id]) ? $this->thumbUrl($imageIds[(int) $suggestion->id]) : null,
                ],
                'image_url' => $imageId !== null ? $this->publicImageUrl($imageId) : null,
                'warnings' => [
                    'below_cost' => $promo !== null && $unitCost !== null && $promo < $unitCost,
                    'no_stock' => $erp !== null && $stock <= 0,
                    'no_image' => $imageId === null,
                    'other_campaigns' => $others[$i],
                ],
                'snapshot' => $item->snap_name === null && $item->snap_code === null ? null : [
                    'name' => $item->snap_name,
                    'code' => $item->snap_code,
                    'price' => $item->snap_price !== null ? (float) $item->snap_price : null,
                    'stock' => $item->snap_stock !== null ? (float) $item->snap_stock : null,
                    'stock_at' => $item->snap_stock_at?->toDateString(),
                    'image_url' => $item->snap_image_url,
                    'description' => $item->snap_description,
                    'norms' => ProductExcerpt::norms($item->snap_norms),
                ],
                'stock_after_7d' => $item->stock_after_7d !== null ? (float) $item->stock_after_7d : null,
                'stock_after_30d' => $item->stock_after_30d !== null ? (float) $item->stock_after_30d : null,
            ];
        }

        return $out;
    }

    /**
     * Absolutny adres zdjęcia do maila (publiczny adres aplikacji): kwadrat na białym tle, żeby karty w siatce miały
     * równą wysokość; null, gdy public_url nieustawiony.
     */
    public function publicImageUrl(int $imageId): ?string
    {
        $base = rtrim((string) config('campaigns.public_url'), '/');

        return $base === '' ? null : $base.'/api/product-images/'.$imageId.'/square';
    }

    private function thumbUrl(int $imageId): string
    {
        return route('product-images.thumb', ['image' => $imageId]);
    }

    /**
     * Id głównego zdjęcia karty (jak ErpItemCards: główne, potem kolejność) — jedno zapytanie.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function primaryImageIds(array $productIds): array
    {
        $productIds = array_values(array_unique($productIds));
        if ($productIds === []) {
            return [];
        }
        $out = [];
        foreach (ProductImage::query()
            ->whereIn('product_id', $productIds)
            ->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'product_id']) as $image) {
            $out[(int) $image->product_id] ??= (int) $image->id;
        }

        return $out;
    }

    /**
     * Ten sam towar w innej kampanii w projekcie/wysyłce albo wysłanej w ostatnich frequency_cap_days dniach —
     * jedno zapytanie dla wszystkich pozycji.
     *
     * @param  Collection<int, CampaignItem>  $items
     * @return array<int, list<array{id: int, code: string, name: string, author: string}>>
     */
    private function otherCampaigns(Collection $items): array
    {
        $erpIds = $items->pluck('erp_item_id')->filter()->unique()->values()->all();
        $productIds = $items->filter(static fn (CampaignItem $i): bool => $i->erp_item_id === null && $i->product_id !== null)
            ->pluck('product_id')->unique()->values()->all();
        $out = array_fill_keys($items->keys()->all(), []);
        if ($erpIds === [] && $productIds === []) {
            return $out;
        }

        $since = Carbon::now()->subDays((int) config('campaigns.frequency_cap_days', 14));
        $rows = DB::table('campaign_items as ci')
            ->join('campaigns as c', 'c.id', '=', 'ci.campaign_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where(static function ($q) use ($erpIds, $productIds): void {
                if ($erpIds !== []) {
                    $q->orWhereIn('ci.erp_item_id', $erpIds);
                }
                if ($productIds !== []) {
                    $q->orWhere(static fn ($p) => $p->whereNull('ci.erp_item_id')->whereIn('ci.product_id', $productIds));
                }
            })
            ->where(static function ($q) use ($since): void {
                // zaplanowana też wyjdzie do klientów — ostrzegamy jak przy projekcie
                $q->whereIn('c.status', [Campaign::STATUS_DRAFT, Campaign::STATUS_SCHEDULED, Campaign::STATUS_SENDING])
                    ->orWhere(static fn ($s) => $s->where('c.status', Campaign::STATUS_SENT)->where('c.sent_at', '>=', $since));
            })
            ->orderBy('c.id')
            ->get(['ci.erp_item_id', 'ci.product_id', 'c.id', 'c.code', 'c.name', 'u.name as author']);

        foreach ($items as $i => $item) {
            $seen = [];
            foreach ($rows as $row) {
                if ((int) $row->id === (int) $item->campaign_id || isset($seen[(int) $row->id])) {
                    continue;
                }
                $same = $item->erp_item_id !== null
                    ? (int) $row->erp_item_id === (int) $item->erp_item_id
                    : $row->erp_item_id === null && $item->product_id !== null && (int) $row->product_id === (int) $item->product_id;
                if ($same) {
                    $seen[(int) $row->id] = true;
                    $out[$i][] = ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'author' => (string) $row->author];
                }
            }
        }

        return $out;
    }
}
