<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\BattlecardService;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\ProductMatchService;
use App\Services\TenderActivityLogger;
use App\Services\TenderPricingService;
use App\Services\Tenders\TenderPriceView;
use App\Services\TenderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenderItemController extends Controller
{
    /**
     * Kolumny wariantu w odpowiedziach z pozycjami (wybór wariantu w ofercie) — także TenderController. Konto
     * (b2b_account_id) potrzebne masce ceny specjalnej: rozmiar konta ze slotem specjalnym jest skalowany.
     */
    public const VARIANT_COLUMNS = 'id,product_id,kind,sku,label,purchase_price,currency,availability,sort_order,removed_at,b2b_account_id';

    public function __construct(
        private readonly TenderPricingService $pricing,
        private readonly TenderWorkflowService $workflow,
        private readonly TenderActivityLogger $activities,
        private readonly ProductMatchService $matcher,
        private readonly BattlecardService $battlecards,
        private readonly TenderPriceView $priceView,
    ) {}

    /**
     * Zastosuj najtańszy zamiennik (po upuście) na pozycjach, gdzie oszczędność ≥ próg.
     */
    public function applyCheaperSubstitutes(Request $request, Tender $tender): JsonResponse
    {
        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Oferty nie można już zmieniać — przetarg ma status „'.TenderWorkflowService::statusLabel($tender->status).'”.'],
            ]);
        }

        $data = $request->validate([
            'min_save_percent' => ['sometimes', 'numeric', 'min:1', 'max:80'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);
        $minSave = (float) ($data['min_save_percent'] ?? 3);
        $dryRun = (bool) ($data['dry_run'] ?? false);

        $tender->load(['items.mainProduct']);
        $applied = [];
        $candidates = [];
        // tańszy zamiennik i nowa oferta w cenach osoby, która zleca zamianę
        $mask = SupplierSpecialMask::forUser($request->user());
        $changed = [];

        // zamiany i ich marże razem albo wcale (jak bulkUpdate)
        DB::transaction(function () use ($tender, $request, $mask, $minSave, $dryRun, &$applied, &$candidates, &$changed): void {
            foreach ($tender->items as $item) {
                $pick = $this->battlecards->bestCheaperSubstitute($item, $mask, $minSave);
                if ($pick === null) {
                    continue;
                }
                $fromSku = $item->mainProduct?->sku;
                $candidates[] = [
                    'item_id' => $item->id,
                    'line_no' => $item->line_no,
                    'from_sku' => $fromSku,
                    'to_sku' => $pick['sku'],
                    'to_product_id' => $pick['product_id'],
                    'save_percent' => $pick['save_percent'],
                    'purchase_price' => $pick['purchase_price'],
                ];

                if ($dryRun) {
                    continue;
                }

                $before = [
                    'main_product_id' => $item->main_product_id,
                    'offer_price' => $item->offer_price,
                ];
                $product = Product::query()->find($pick['product_id']);
                if ($product === null) {
                    continue;
                }

                $item->main_product_id = $product->id;
                if ($item->companion_product_id !== null && (int) $item->companion_product_id === (int) $product->id) {
                    $item->clearCompanion();
                }
                $item->offer_price = $this->pricing->offerFromProduct($tender, $product, $mask);
                $item->status = 'matched';
                $item->match_source = 'battlecard';
                $item->ai_match_percent = $pick['match_percent'];
                $item->ai_match_reasons = [
                    [
                        'code' => 'battlecard_batch',
                        'label' => sprintf(
                            'Zastosowano tańszy zamiennik %s (%.0f%% taniej po upuście)',
                            $pick['sku'],
                            $pick['save_percent'],
                        ),
                        'points' => $pick['match_percent'],
                    ],
                ];
                $item->save();
                $item->load('mainProduct');
                $changed[] = $item;
                $this->activities->log($tender, 'item_updated', $request->user(), $item, [
                    'before' => $before,
                    'after' => [
                        'main_product_id' => $item->main_product_id,
                        'offer_price' => $item->offer_price,
                        'match_source' => $item->match_source,
                    ],
                    'batch' => 'cheaper_substitutes',
                ]);
                $applied[] = [
                    'item_id' => $item->id,
                    'line_no' => $item->line_no,
                    'from_sku' => $fromSku,
                    'to_sku' => $pick['sku'],
                    'save_percent' => $pick['save_percent'],
                    'offer_price' => $item->offer_price,
                ];
            }

            if (! $dryRun && $applied !== []) {
                // marże po pętli, jedną maską ceny standardowej z kartami wczytanymi hurtem
                $standard = $this->pricing->standardMask($changed);
                foreach ($changed as $item) {
                    $this->pricing->recalculateItemMargin($item, $standard);
                }
                $this->pricing->recalculateTenderTotals($tender->fresh());
                $tender->last_activity_at = now();
                $tender->save();
            }
        });

        return response()->json([
            'dry_run' => $dryRun,
            'min_save_percent' => $minSave,
            'candidates_count' => count($candidates),
            'applied_count' => count($applied),
            'candidates' => $candidates,
            'applied' => $applied,
        ]);
    }

    public function bulkUpdate(Request $request, Tender $tender): JsonResponse
    {
        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Oferty nie można już zmieniać — przetarg ma status „'.TenderWorkflowService::statusLabel($tender->status).'”.'],
            ]);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.main_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.companion_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.offer_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.companion_offer_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.custom_name' => ['nullable', 'string', 'max:500'],
            'items.*.custom_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $updated = 0;
        $mask = SupplierSpecialMask::forUser($request->user());
        DB::transaction(function () use ($tender, $data, $request, $mask, &$updated): void {
            $saved = [];
            foreach ($data['items'] as $row) {
                $item = TenderItem::query()
                    ->where('tender_id', $tender->id)
                    ->where('id', $row['id'])
                    ->first();
                if ($item === null) {
                    continue;
                }

                $before = [
                    'main_product_id' => $item->main_product_id,
                    'companion_product_id' => $item->companion_product_id,
                    'quantity' => $item->quantity,
                    'offer_price' => $item->offer_price,
                    'companion_offer_price' => $item->companion_offer_price,
                ];

                $item->main_product_id = $row['main_product_id'] ?? null;
                $item->quantity = (int) $row['quantity'];
                $item->offer_price = array_key_exists('offer_price', $row) ? $row['offer_price'] : $item->offer_price;
                $this->applyCompanion($tender, $item, $row, $mask, 'items.'.$item->id.'.companion_product_id');
                if (array_key_exists('custom_name', $row)) {
                    $item->custom_name = $this->nullableTrim($row['custom_name'] ?? null);
                }
                if (array_key_exists('custom_url', $row)) {
                    $url = $this->nullableTrim($row['custom_url'] ?? null);
                    if ($url !== null && ! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                        throw ValidationException::withMessages([
                            'items.'.$item->id.'.custom_url' => ['Link musi zaczynać się od http:// lub https://.'],
                        ]);
                    }
                    $item->custom_url = $url;
                }
                if ($item->main_product_id !== null) {
                    $item->custom_name = null;
                    $item->custom_url = null;
                    $item->status = 'matched';
                } elseif ($item->hasCustomOffer()) {
                    $item->status = 'matched';
                    $item->match_source = $item->match_source ?: 'custom';
                    $item->ai_match_reasons = $this->mergeCustomOfferReason($item);
                }
                $item->save();
                $item->load(['mainProduct', 'companionProduct']);
                $saved[] = $item;
                $this->activities->log($tender, 'item_bulk_updated', $request->user(), $item, [
                    'before' => $before,
                    'after' => [
                        'main_product_id' => $item->main_product_id,
                        'companion_product_id' => $item->companion_product_id,
                        'quantity' => $item->quantity,
                        'offer_price' => $item->offer_price,
                        'companion_offer_price' => $item->companion_offer_price,
                    ],
                ]);
                $updated++;
            }
            // marże po pętli, jedną maską ceny standardowej z kartami wczytanymi hurtem — nie zapytania na pozycję
            $standard = $this->pricing->standardMask($saved);
            foreach ($saved as $item) {
                $this->pricing->recalculateItemMargin($item, $standard);
            }
        });

        $this->pricing->recalculateTenderTotals($tender->fresh());
        $tender->last_activity_at = now();
        $tender->save();

        return response()->json([
            'updated' => $updated,
            'tender_id' => $tender->id,
        ]);
    }

    public function update(Request $request, Tender $tender, TenderItem $item): JsonResponse
    {
        if ($item->tender_id !== $tender->id) {
            abort(404);
        }

        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Oferty nie można już zmieniać — przetarg ma status „'.TenderWorkflowService::statusLabel($tender->status).'”.'],
            ]);
        }

        $data = $request->validate([
            'main_product_id' => ['sometimes', 'nullable', 'exists:products,id'],
            // przynależność do karty i wycofanie sprawdza applyVariant (karta może zmieniać się w tym samym żądaniu)
            'main_variant_id' => ['sometimes', 'nullable', 'integer'],
            'companion_product_id' => ['sometimes', 'nullable', 'exists:products,id'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'offer_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'companion_offer_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'requirement' => ['sometimes', 'string', 'max:2000'],
            'status' => ['sometimes', 'string', 'max:32'],
            'ai_match_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'ai_match_reasons' => ['sometimes', 'nullable', 'array'],
            'match_source' => ['sometimes', 'nullable', 'string', 'max:32'],
            'custom_name' => ['sometimes', 'nullable', 'string', 'max:500'],
            'custom_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        if (isset($data['custom_url']) && is_string($data['custom_url']) && $data['custom_url'] !== '') {
            if (! str_starts_with($data['custom_url'], 'http://') && ! str_starts_with($data['custom_url'], 'https://')) {
                throw ValidationException::withMessages([
                    'custom_url' => ['Link musi zaczynać się od http:// lub https://.'],
                ]);
            }
        }

        $before = [
            'main_product_id' => $item->main_product_id,
            'companion_product_id' => $item->companion_product_id,
            'quantity' => $item->quantity,
            'offer_price' => $item->offer_price,
            'companion_offer_price' => $item->companion_offer_price,
            'ai_match_percent' => $item->ai_match_percent,
            'custom_name' => $item->custom_name,
            'main_variant_id' => $item->main_variant_id,
            'main_variant_label' => $item->main_variant_label,
        ];

        if (array_key_exists('custom_name', $data)) {
            $item->custom_name = $this->nullableTrim($data['custom_name']);
        }
        if (array_key_exists('custom_url', $data)) {
            $item->custom_url = $this->nullableTrim($data['custom_url']);
        }

        // oferty z karty i wariantu w cenach osoby, która edytuje pozycję
        $mask = SupplierSpecialMask::forUser($request->user());
        $repricedFromCard = false;
        if (array_key_exists('main_product_id', $data)) {
            $item->main_product_id = $data['main_product_id'];
            if ($data['main_product_id'] !== null) {
                $item->custom_name = null;
                $item->custom_url = null;
            }
            if ($data['main_product_id'] !== null) {
                $product = Product::query()->find($data['main_product_id']);
                if (! array_key_exists('offer_price', $data)
                    && $product !== null && (float) $product->purchase_price > 0) {
                    $item->offer_price = $this->pricing->offerFromProduct($tender, $product, $mask);
                    $repricedFromCard = true;
                }
                // Ocena i uzasadnienie opisują kartę, nie cenę. Dotąd całe przeliczenie wisiało pod
                // warunkiem „żądanie nie niesie ceny”, a panel przy „Zapisz” cenę wysyła zawsze —
                // więc po ręcznej podmianie produktu przy pozycji zostawał procent i uzasadnienie
                // poprzedniej karty (ocena opisywała wyrób, którego już tam nie ma).
                // Tylko przy zmianie wyrobu: panel wysyła komplet pól także przy zmianie samej
                // ilości, a to nie powód, żeby ocena modelu stała się oceną ręczną.
                $productChanged = (int) ($before['main_product_id'] ?? 0) !== (int) $data['main_product_id'];
                if ($product !== null && $productChanged && ! array_key_exists('ai_match_reasons', $data)) {
                    $explained = $this->matcher->explainMatch($item->requirement, $product);
                    $item->ai_match_reasons = $explained['reasons'];
                    if (! array_key_exists('ai_match_percent', $data)) {
                        $item->ai_match_percent = $explained['score'];
                    }
                    if (! array_key_exists('match_source', $data)) {
                        $item->match_source = 'manual';
                    }
                }
            }
            if ($data['main_product_id'] === null && ! $item->hasCustomOffer()) {
                $item->ai_match_reasons = null;
                $item->match_source = null;
            }
        }

        // Wariant po karcie: zmiana karty bez wariantu w żądaniu zeruje wariant przy zapisie (TenderItem::saving).
        $variantChanged = array_key_exists('main_variant_id', $data)
            && $this->applyVariant($item, $data['main_variant_id']);
        // Cena oferty z wariantu, gdy ma własną cenę — także wtedy, gdy blok karty wyżej przeliczył cenę z karty,
        // a wariant zostaje (panel wysyła main_product_id przy każdym zapisie). Zdjęty wariant = z powrotem cena karty.
        $variant = $item->offerVariant();
        if (! array_key_exists('offer_price', $data) && ($variant !== null || $variantChanged)
            && ($repricedFromCard || $variantChanged)) {
            $mainProduct = Product::query()->find($item->main_product_id);
            $variantOffer = $variant !== null ? $this->pricing->offerFromVariant($tender, $variant, $mainProduct, $mask) : null;
            if ($variantOffer !== null) {
                $item->offer_price = $variantOffer;
            } elseif ($variantChanged && ! $repricedFromCard
                && $mainProduct !== null && (float) $mainProduct->purchase_price > 0) {
                $item->offer_price = $this->pricing->offerFromProduct($tender, $mainProduct, $mask);
            }
        }

        if (array_key_exists('quantity', $data)) {
            $item->quantity = $data['quantity'];
        }
        if (array_key_exists('offer_price', $data)) {
            $item->offer_price = $data['offer_price'];
        }
        $this->applyCompanion($tender, $item, $data, $mask);
        if (array_key_exists('requirement', $data)) {
            $item->requirement = $data['requirement'];
        }
        if (array_key_exists('status', $data)) {
            $item->status = $data['status'];
        }
        if (array_key_exists('ai_match_percent', $data)) {
            $item->ai_match_percent = $data['ai_match_percent'];
        }
        if (array_key_exists('ai_match_reasons', $data)) {
            $item->ai_match_reasons = $data['ai_match_reasons'];
        }
        if (array_key_exists('match_source', $data)) {
            $item->match_source = $data['match_source'];
        }

        if ($item->hasCustomOffer() && $item->main_product_id === null) {
            $item->status = $data['status'] ?? 'matched';
            if (! array_key_exists('match_source', $data) || $item->match_source === null) {
                $item->match_source = 'custom';
            }
            $item->ai_match_reasons = $this->mergeCustomOfferReason($item);
        } elseif (
            ! $item->hasCustomOffer()
            && $item->main_product_id === null
            && ! array_key_exists('status', $data)
        ) {
            $item->status = 'brak';
        }

        $item->save();
        $item->load(['mainProduct', 'companionProduct', 'tender']);
        $this->pricing->recalculateItemMargin($item);
        $this->pricing->recalculateTenderTotals($tender->fresh());
        if (($data['match_source'] ?? null) === 'ai') {
            $this->battlecards->rebuild($item);
        }

        $this->activities->log($tender, 'item_updated', $request->user(), $item, [
            'before' => $before,
            'after' => [
                'main_product_id' => $item->main_product_id,
                'companion_product_id' => $item->companion_product_id,
                'quantity' => $item->quantity,
                'offer_price' => $item->offer_price,
                'companion_offer_price' => $item->companion_offer_price,
                'ai_match_percent' => $item->ai_match_percent,
                'match_source' => $item->match_source,
                'custom_name' => $item->custom_name,
                'main_variant_id' => $item->main_variant_id,
                'main_variant_label' => $item->main_variant_label,
            ],
        ]);

        $fresh = $item->fresh([
            'mainProduct.images',
            'mainProduct.activeVariants:'.self::VARIANT_COLUMNS,
            'mainVariant:'.self::VARIANT_COLUMNS,
            'companionProduct.images',
        ]);
        if ($fresh === null) {
            return response()->json(null);
        }
        $this->pricing->appendVariantPricesPln($fresh);

        return response()->json($this->priceView->item($fresh, $mask));
    }

    public function destroy(Request $request, Tender $tender, TenderItem $item): JsonResponse
    {
        if ((int) $item->tender_id !== (int) $tender->id) {
            abort(404);
        }

        $meta = [
            'line_no' => $item->line_no,
            'requirement' => mb_substr((string) $item->requirement, 0, 200),
        ];
        $item->delete();
        $this->pricing->recalculateTenderTotals($tender->fresh());
        $tender->last_activity_at = now();
        $tender->save();
        $this->activities->log($tender, 'item_deleted', $request->user(), null, $meta);

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyCompanion(Tender $tender, TenderItem $item, array $data, SupplierSpecialMask $mask, string $errorKey = 'companion_product_id'): void
    {
        if ($item->main_product_id === null) {
            $item->clearCompanion();

            return;
        }

        if (array_key_exists('companion_product_id', $data)) {
            $item->companion_product_id = $data['companion_product_id'];
        }

        if ($item->companion_product_id === null) {
            $item->companion_offer_price = null;

            return;
        }

        if ((int) $item->companion_product_id === (int) $item->main_product_id) {
            throw ValidationException::withMessages([
                $errorKey => ['Drugi produkt musi być inny niż pierwszy.'],
            ]);
        }

        if (array_key_exists('companion_offer_price', $data) && $data['companion_offer_price'] !== null) {
            $item->companion_offer_price = $data['companion_offer_price'];

            return;
        }

        if (array_key_exists('companion_product_id', $data)) {
            $companion = Product::query()->find($item->companion_product_id);
            if ($companion !== null && (float) $companion->purchase_price > 0) {
                $item->companion_offer_price = $this->pricing->offerFromProduct($tender, $companion, $mask);
            }
        }
    }

    /**
     * Ręczny wybór wariantu karty do oferty: tylko aktywny wiersz bieżącej karty pozycji (także karty zmienianej
     * w tym samym żądaniu). Etykieta i kod kopiowane z chwili wyboru. Zwraca true, gdy wariant się zmienił.
     */
    private function applyVariant(TenderItem $item, mixed $variantId): bool
    {
        $before = $item->main_variant_id !== null ? (int) $item->main_variant_id : null;
        if ($variantId === null) {
            $item->clearVariant();
            $item->setRelation('mainVariant', null);

            return $before !== null;
        }

        $variant = $item->main_product_id === null ? null : ProductVariant::query()
            ->whereKey((int) $variantId)
            ->where('product_id', (int) $item->main_product_id)
            ->whereNull('removed_at')
            ->first();
        if ($variant === null) {
            throw ValidationException::withMessages([
                'main_variant_id' => ['Wariant nie należy do karty tej pozycji albo został wycofany.'],
            ]);
        }

        $item->main_variant_id = $variant->id;
        $item->main_variant_label = mb_substr((string) $variant->label, 0, 255);
        $item->main_variant_sku = $variant->sku !== null ? mb_substr((string) $variant->sku, 0, 255) : null;
        $item->main_variant_source = 'manual';
        $item->setRelation('mainVariant', $variant);

        return $before !== (int) $variant->id;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trim = trim($value);

        return $trim === '' ? null : $trim;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mergeCustomOfferReason(TenderItem $item): array
    {
        $reasons = is_array($item->ai_match_reasons) ? array_values($item->ai_match_reasons) : [];
        $url = trim((string) ($item->custom_url ?? ''));
        $label = 'Własna propozycja (nie z katalogu SUPON): '.(string) $item->custom_name;
        foreach ($reasons as $i => $row) {
            if (! is_array($row) || ($row['code'] ?? '') !== 'custom_offer') {
                continue;
            }
            $reasons[$i] = [
                'code' => 'custom_offer',
                'label' => $label,
                'points' => 0,
                'url' => $url !== '' ? $url : ($row['url'] ?? null),
            ];

            return $reasons;
        }
        $reasons[] = [
            'code' => 'custom_offer',
            'label' => $label,
            'points' => 0,
            'url' => $url !== '' ? $url : null,
        ];

        return $reasons;
    }
}
