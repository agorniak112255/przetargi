<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\EnrichmentCancelledException;
use App\Http\Controllers\Controller;
use App\Jobs\EnrichProductJob;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Services\Ai\AiSettingsService;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\PriceListDescriptionSources;
use App\Services\Enrichment\PriceListSourceSettings;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\PriceListCards;
use App\Services\Pricing\SupplierSpecialMask;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ProductEnrichmentController extends Controller
{
    public function __construct(
        private readonly ProductEnrichmentService $enrichment,
        private readonly AiSettingsService $aiSettings,
        private readonly EnrichmentSlots $slots,
    ) {}

    public function limits(): JsonResponse
    {
        return response()->json([
            'enrichment_batch_limit' => $this->aiSettings->enrichmentBatchLimit(),
            'match_concurrency' => $this->aiSettings->matchConcurrency(),
            'catalog_search_limit' => $this->aiSettings->catalogSearchLimit(),
        ]);
    }

    public function enrichProduct(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'force' => ['sometimes', 'boolean'],
            // potwierdzenie użytkownika: AI może zastąpić opis z cennika B2B (B2bDescriptionSource)
            'overwrite_b2b_description' => ['sometimes', 'boolean'],
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            // Jedna sztuka — synchronicznie (bez ryzyka starego queue:work)
            $batch = $this->enrichment->enrichProductSync(
                $product,
                $request->user(),
                (bool) ($data['force'] ?? false),
                (bool) ($data['overwrite_b2b_description'] ?? false),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $product->refresh()->load([
            'images',
            'documents',
            'substitutes.substituteProduct:id,sku,name,manufacturer,catalog_price_net',
            'substitutes.approver:id,name',
        ]);

        // widok cen specjalnych B2B: bez uprawnienia karta i zamienniki z ceną specjalną w cenie standardowej
        $mask = SupplierSpecialMask::forUser($request->user());
        $mask->preload([(int) $product->id, ...$product->substitutes->pluck('substitute_product_id')->filter()->all()]);
        $payload = $mask->productRow($product->toArray());
        foreach ($payload['substitutes'] ?? [] as $i => $row) {
            if (is_array($row['substitute_product'] ?? null)) {
                $payload['substitutes'][$i]['substitute_product'] = $mask->productRow($row['substitute_product']);
            }
        }
        $payload['images'] = $product->images->map(static fn ($img): array => [
            'id' => $img->id,
            'url' => $img->url(),
            'source_url' => $img->source_url,
            'is_primary' => $img->is_primary,
            'sort_order' => $img->sort_order,
        ])->values()->all();
        $payload['documents'] = $product->documents->map(static fn ($doc): array => [
            'id' => $doc->id,
            'url' => $doc->url(),
            'source_url' => $doc->source_url,
            'title' => $doc->title,
            'kind' => $doc->kind,
            'size_bytes' => $doc->size_bytes,
            'sort_order' => $doc->sort_order,
        ])->values()->all();

        return response()->json([
            'batch' => $this->batchPayload($batch),
            'product_id' => $product->id,
            'product' => $payload,
            'images_count' => count($payload['images']),
            'documents_count' => count($payload['documents']),
        ]);
    }

    public function enrichPriceList(Request $request, PriceList $priceList): JsonResponse
    {
        $data = $request->validate([
            'force' => ['sometimes', 'boolean'],
            // Cenniki → „Z pliku” → „Pobierz opisy ponownie”: filtry kart i podgląd przed kolejką
            'only_not_from_sites' => ['sometimes', 'boolean'],
            'skip_manufacturer' => ['sometimes', 'boolean'],
            'enriched_before' => ['sometimes', 'nullable', 'date'],
            'apply' => ['sometimes', 'boolean'],
            // „Pobierz ponownie” bez filtrów: gotowe opisy od producenta tylko na wyraźne życzenie
            'include_manufacturer' => ['sometimes', 'boolean'],
        ]);
        $filtered = array_key_exists('only_not_from_sites', $data)
            || array_key_exists('skip_manufacturer', $data)
            || ($data['enriched_before'] ?? null) !== null;
        if ($filtered || ! (bool) ($data['apply'] ?? true)) {
            return $this->enrichPriceListFiltered($request, $priceList, $data, $filtered);
        }

        $force = (bool) ($data['force'] ?? false);
        $skippedManufacturer = [];
        try {
            if ($force && ! (bool) ($data['include_manufacturer'] ?? false)) {
                // ta sama lista kart co enqueuePriceList, bez gotowych opisów od producenta
                $ids = app(PriceListCards::class)->ids($priceList);
                if ($ids === []) {
                    throw new RuntimeException('Ten cennik nie ma zapisanych produktów do wzbogacenia (stary import?).');
                }
                [$ids, $skippedManufacturer] = $this->withoutManufacturerDescribed($ids);
                if ($ids === []) {
                    return $this->allManufacturerDescribed($skippedManufacturer);
                }
                $queued = $this->enrichment->enqueueProductIds(
                    $ids,
                    $request->user(),
                    true,
                    ProductEnrichmentBatch::SCOPE_PRICE_LIST,
                    (int) $priceList->id,
                );
            } else {
                $queued = $this->enrichment->enqueuePriceList($priceList, $request->user(), $force);
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'batch' => $this->batchPayload($queued['batch']),
            'product_ids' => $queued['product_ids'],
            'skipped_b2b' => $queued['skipped_b2b'],
            'price_list_id' => $priceList->id,
            // tylko przy ponownym pobraniu (force) — bez niego odpowiedź jak przed 07.10.2026
            ...($force ? [
                'skipped_manufacturer' => count($skippedManufacturer),
                'skipped_manufacturer_ids' => $skippedManufacturer,
            ] : []),
        ], 202);
    }

    /**
     * Partia cennika z filtrami kart (Cenniki → „Z pliku”) albo sam podgląd (apply=false). Karty filtrowane PRZED
     * enqueueProductIds — dalej ta sama droga co enqueuePriceList (zakres partii price_list, pomijanie opisów z B2B,
     * limit partii z Ustawień AI). skip_manufacturer (domyślnie tak) działa tylko z którymkolwiek filtrem: karta
     * z opisem ze strony producenta zostaje, bo strony cennika działają wyłącznie bez karty producenta w puli.
     *
     * @param  array<string, mixed>  $data
     */
    private function enrichPriceListFiltered(Request $request, PriceList $priceList, array $data, bool $filtered): JsonResponse
    {
        $force = (bool) ($data['force'] ?? false);
        // karty ze slotem pliku — do nich stosują się strony cennika (product_ids bywa listą kart konta B2B)
        $ids = app(PriceListCards::class)->fileSlotIds($priceList);
        if ($ids === []) {
            return response()->json(['message' => 'Ten cennik nie ma zapisanych produktów do wzbogacenia (stary import?).'], 422);
        }

        $cards = app(PriceListDescriptionSources::class)->cards($ids, PriceListSourceSettings::fromList($priceList));
        if ($filtered) {
            $onlyNotFromSites = (bool) ($data['only_not_from_sites'] ?? false);
            $skipManufacturer = (bool) ($data['skip_manufacturer'] ?? true);
            $before = ($data['enriched_before'] ?? null) !== null
                ? CarbonImmutable::parse((string) $data['enriched_before'])->getTimestamp()
                : null;
            $ids = array_values(array_filter($ids, static function (int $id) use ($cards, $onlyNotFromSites, $skipManufacturer, $before): bool {
                $card = $cards[$id] ?? null;
                if ($card === null) {
                    return false;
                }
                if ($onlyNotFromSites && $card['source'] === PriceListDescriptionSources::SOURCE_PRICE_LIST_SITES) {
                    return false;
                }
                // od producenta także ręczny link do jego strony i stary opis z jego strony bez zapisanego rodzaju
                // (PriceListDescriptionSources) — rodzaj manufacturer/catalog zostaje, jak dotąd, także bez opisu
                if ($skipManufacturer && ($card['source'] === PriceListDescriptionSources::SOURCE_MANUFACTURER
                    || in_array($card['kind'], PriceListDescriptionSources::MANUFACTURER_KINDS, true))) {
                    return false;
                }

                // karta bez daty opisu też jest „bez opisu od tej daty”
                return $before === null || $card['enriched_at'] === null || $card['enriched_at'] < $before;
            }));
        }

        // Opis z B2B zostaje — także opis AI z karty katalogowej albo uzupełnienia B2B (b2b_datasheet/b2b_supplement),
        // którego enqueueProductIds sam nie rozpoznaje (patrzy tylko na zgodność skrótu opisu z powiązaniem B2B).
        $matched = count($ids);
        $b2bCards = array_values(array_filter(
            $ids,
            static fn (int $id): bool => ($cards[$id]['source'] ?? null) === PriceListDescriptionSources::SOURCE_B2B
        ));
        $ids = array_values(array_diff($ids, $b2bCards));

        if (! (bool) ($data['apply'] ?? true)) {
            // te same reguły co enqueueProductIds: bez force karty gotowe i ręczne odpadają, opis z B2B zostaje
            $eligible = array_values(array_filter(
                $ids,
                static fn (int $id): bool => isset($cards[$id])
                    && ($force || ! in_array($cards[$id]['status'], [Product::ENRICHMENT_DONE, Product::ENRICHMENT_MANUAL], true)),
            ));
            $skippedB2b = $eligible === [] ? 0 : count(app(B2bDescriptionSource::class)->productIds($eligible));
            $limit = $this->aiSettings->enrichmentBatchLimit();

            return response()->json([
                'preview' => true,
                'matched' => $matched,
                'will_queue' => min(max(0, count($eligible) - $skippedB2b), $limit),
                'skipped_b2b' => $skippedB2b + count($b2bCards),
                'limit' => $limit,
            ]);
        }

        if ($ids === []) {
            return response()->json(['message' => $b2bCards !== []
                ? 'Wybrane karty mają opis z B2B — tych opisów ponowne pobieranie nie zastępuje.'
                : 'Żadna karta cennika nie spełnia wybranych warunków.'], 422);
        }
        try {
            $queued = $this->enrichment->enqueueProductIds(
                $ids,
                $request->user(),
                $force,
                ProductEnrichmentBatch::SCOPE_PRICE_LIST,
                (int) $priceList->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'batch' => $this->batchPayload($queued['batch']),
            'product_ids' => $queued['product_ids'],
            'skipped_b2b' => $queued['skipped_b2b'] + count($b2bCards),
            'price_list_id' => $priceList->id,
        ], 202);
    }

    public function enrichProducts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:'.$this->aiSettings->enrichmentBatchLimit()],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'force' => ['sometimes', 'boolean'],
            'include_manufacturer' => ['sometimes', 'boolean'],
        ]);

        $force = (bool) ($data['force'] ?? false);
        $ids = array_map('intval', $data['product_ids']);
        $skippedManufacturer = [];
        if ($force && ! (bool) ($data['include_manufacturer'] ?? false)) {
            [$ids, $skippedManufacturer] = $this->withoutManufacturerDescribed($ids);
            if ($ids === []) {
                return $this->allManufacturerDescribed($skippedManufacturer);
            }
        }
        try {
            $queued = $this->enrichment->enqueueProductIds($ids, $request->user(), $force);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'batch' => $this->batchPayload($queued['batch']),
            'product_ids' => $queued['product_ids'],
            'skipped_b2b' => $queued['skipped_b2b'],
            ...($force ? [
                'skipped_manufacturer' => count($skippedManufacturer),
                'skipped_manufacturer_ids' => $skippedManufacturer,
            ] : []),
        ], 202);
    }

    /**
     * Ponowne pobranie hurtem (force) pomija karty z gotowym opisem od producenta (plan 07.10.2026): MAPA i AJ GROUP
     * miały po 4+ udane opisy na kartę w miesiąc — każdy przebieg to czas modelu i ryzyko podmiany dobrego opisu gorszym.
     * Karty „błąd” i „ręcznie” idą jak dotąd, pojedynczą kartę „Pobierz” w karcie produktu też. Kartę od producenta
     * przepuszcza include_manufacturer (przycisk „Pobierz też te” po komunikacie).
     *
     * @param  list<int>  $ids
     * @return array{0: list<int>, 1: list<int>} [karty do kolejki, pominięte od producenta]
     */
    private function withoutManufacturerDescribed(array $ids): array
    {
        $cards = app(PriceListDescriptionSources::class)->cards($ids, null);
        $keep = [];
        $skipped = [];
        foreach ($ids as $id) {
            $card = $cards[$id] ?? null;
            if ($card !== null && $card['status'] === Product::ENRICHMENT_DONE
                && $card['source'] === PriceListDescriptionSources::SOURCE_MANUFACTURER) {
                $skipped[] = $id;

                continue;
            }
            $keep[] = $id;
        }

        return [$keep, $skipped];
    }

    /** @param  list<int>  $skipped */
    private function allManufacturerDescribed(array $skipped): JsonResponse
    {
        return response()->json([
            'message' => 'Wszystkie wybrane karty ('.count($skipped).') mają gotowy opis ze strony producenta — ponowne pobranie '
                .'ich pomija. Żeby pobrać je mimo to, użyj „Pobierz też te karty”.',
            'skipped_manufacturer' => count($skipped),
            'skipped_manufacturer_ids' => $skipped,
        ], 422);
    }

    public function activeBatches(): JsonResponse
    {
        // najpierw produkty: zablokowany w „running” wstrzymuje domykanie każdej partii
        $this->enrichment->releaseStaleRunningProducts();

        $batches = ProductEnrichmentBatch::query()
            ->whereIn('status', [
                ProductEnrichmentBatch::STATUS_QUEUED,
                ProductEnrichmentBatch::STATUS_RUNNING,
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ProductEnrichmentBatch $batch): ProductEnrichmentBatch => $this->enrichment->finalizeIfJobsGone($batch))
            ->filter(fn (ProductEnrichmentBatch $batch): bool => in_array($batch->status, [
                ProductEnrichmentBatch::STATUS_QUEUED,
                ProductEnrichmentBatch::STATUS_RUNNING,
            ], true));

        $ctx = $this->batchLinkContext($batches);

        $recent = ProductEnrichmentBatch::query()
            ->whereIn('status', [
                ProductEnrichmentBatch::STATUS_DONE,
                ProductEnrichmentBatch::STATUS_FAILED,
                ProductEnrichmentBatch::STATUS_CANCELLED,
            ])
            ->where('updated_at', '>=', now()->subDays(2))
            ->orderByDesc('id')
            ->limit(8)
            ->get();
        $recentCtx = $this->batchLinkContext($recent);

        return response()->json([
            'batches' => $batches
                ->map(fn (ProductEnrichmentBatch $batch): array => $this->batchPayload($batch, $ctx[(int) $batch->id] ?? null))
                ->values()
                ->all(),
            'recent' => $recent
                ->map(fn (ProductEnrichmentBatch $batch): array => $this->batchPayload($batch, $recentCtx[(int) $batch->id] ?? null))
                ->values()
                ->all(),
            ...$this->enrichment->enrichmentProductCounts(),
        ]);
    }

    public function historyBatches(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'nullable', 'in:done,failed,cancelled'],
        ]);

        $query = ProductEnrichmentBatch::query()
            ->with('creator:id,name')
            ->whereIn('status', [
                ProductEnrichmentBatch::STATUS_DONE,
                ProductEnrichmentBatch::STATUS_FAILED,
                ProductEnrichmentBatch::STATUS_CANCELLED,
            ])
            ->orderByDesc('id');

        $status = isset($data['status']) && is_string($data['status']) && $data['status'] !== ''
            ? $data['status']
            : null;
        if ($status !== null) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate((int) ($data['per_page'] ?? 100));
        $ctx = $this->batchLinkContext($paginator->getCollection());

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(function (ProductEnrichmentBatch $batch) use ($ctx): array {
                    $payload = $this->batchPayload($batch, $ctx[(int) $batch->id] ?? null);
                    $payload['created_by_name'] = $batch->creator?->name;

                    return $payload;
                })
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function stopAll(): JsonResponse
    {
        $result = $this->enrichment->stopAllEnrichment();

        return response()->json([
            'message' => 'Zatrzymano pobieranie opisów.',
            ...$result,
            ...$this->enrichment->enrichmentProductCounts(),
        ]);
    }

    public function showBatch(ProductEnrichmentBatch $batch): JsonResponse
    {
        return response()->json($this->batchPayload($batch));
    }

    public function batchItems(Request $request, ProductEnrichmentBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'sort' => ['sometimes', 'in:status,updated'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        $log = $this->enrichment->batchItemLog(
            $batch,
            (string) ($data['sort'] ?? 'status'),
            isset($data['status']) && $data['status'] !== '' ? (string) $data['status'] : null,
        );

        return response()->json([
            'batch' => $this->batchPayload($batch),
            ...$log,
        ]);
    }

    public function processBatchItem(ProductEnrichmentBatch $batch, Product $product): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            $job = new EnrichProductJob($product->id, $batch->id, (bool) $batch->force);
            $job->handle($this->enrichment, $this->aiSettings, $this->slots);
        } catch (EnrichmentCancelledException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'batch' => $this->batchPayload($batch->fresh() ?? $batch),
            ], 409);
        } catch (RuntimeException|Throwable $e) {
            $fresh = $batch->fresh() ?? $batch;
            if (! $fresh->isCancelled() && ($fresh->done + $fresh->failed) < $fresh->total) {
                $this->enrichment->markBatchItem($fresh, false);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'batch' => $this->batchPayload($fresh->fresh() ?? $fresh),
            ], 422);
        }

        return response()->json([
            'batch' => $this->batchPayload($batch->fresh() ?? $batch),
        ]);
    }

    public function cancelBatch(ProductEnrichmentBatch $batch): JsonResponse
    {
        try {
            $result = $this->enrichment->cancelBatch($batch);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Batch zatrzymany.',
            'batch' => $this->batchPayload($result['batch']),
            'removed_jobs' => $result['removed_jobs'],
            'marked_products' => $result['marked_products'],
        ]);
    }

    /**
     * @param  array{manufacturer: ?string, current_product_id: ?int, price_list_id: ?int}|null  $ctx
     * @return array<string, mixed>
     */
    private function batchPayload(ProductEnrichmentBatch $batch, ?array $ctx = null): array
    {
        $processed = $batch->done + $batch->failed;
        $pct = $batch->total > 0 ? round(100 * $processed / $batch->total, 1) : 0.0;
        $ctx ??= $this->batchLinkContext([$batch])[(int) $batch->id] ?? [
            'manufacturer' => null,
            'current_product_id' => null,
            'price_list_id' => null,
        ];

        return [
            'id' => $batch->id,
            'scope' => $batch->scope,
            'scope_id' => $batch->scope_id,
            'total' => $batch->total,
            'done' => $batch->done,
            'failed' => $batch->failed,
            'status' => $batch->status,
            'force' => $batch->force,
            'progress_percent' => $pct,
            'current_sku' => $batch->current_sku,
            'current_name' => $batch->current_name,
            'message' => $batch->message,
            'manufacturer' => $ctx['manufacturer'],
            'current_product_id' => $ctx['current_product_id'],
            'price_list_id' => $ctx['price_list_id'],
            'created_at' => $batch->created_at?->toIso8601String(),
            'updated_at' => $batch->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<int, ProductEnrichmentBatch>  $batches
     * @return array<int, array{manufacturer: ?string, current_product_id: ?int, price_list_id: ?int}>
     */
    private function batchLinkContext(iterable $batches): array
    {
        $list = collect($batches);
        $priceListIds = $list
            ->where('scope', ProductEnrichmentBatch::SCOPE_PRICE_LIST)
            ->pluck('scope_id')
            ->filter()
            ->unique()
            ->values();
        $priceLists = $priceListIds->isEmpty()
            ? collect()
            : PriceList::query()->whereIn('id', $priceListIds)->get(['id', 'manufacturer'])->keyBy('id');

        $skus = $list->pluck('current_sku')->filter()->unique()->values();
        $productsBySku = $skus->isEmpty()
            ? collect()
            : Product::query()->whereIn('sku', $skus)->get(['id', 'sku', 'manufacturer'])->groupBy('sku');

        $productScopeIds = $list
            ->where('scope', ProductEnrichmentBatch::SCOPE_PRODUCT)
            ->pluck('scope_id')
            ->filter()
            ->unique()
            ->values();
        $productsById = $productScopeIds->isEmpty()
            ? collect()
            : Product::query()->whereIn('id', $productScopeIds)->get(['id', 'manufacturer'])->keyBy('id');

        $out = [];
        foreach ($list as $batch) {
            $manufacturer = null;
            $priceListId = null;
            $currentProductId = null;

            if ($batch->scope === ProductEnrichmentBatch::SCOPE_PRICE_LIST) {
                $priceListId = (int) $batch->scope_id;
                $manufacturer = $priceLists->get($priceListId)?->manufacturer;
            }

            if (is_string($batch->current_sku) && $batch->current_sku !== '') {
                $candidates = $productsBySku->get($batch->current_sku, collect());
                $product = is_string($manufacturer) && $manufacturer !== ''
                    ? ($candidates->firstWhere('manufacturer', $manufacturer) ?? $candidates->first())
                    : $candidates->first();
                if ($product !== null) {
                    $currentProductId = (int) $product->id;
                    $manufacturer = $manufacturer ?: $product->manufacturer;
                }
            }

            if ($manufacturer === null && $batch->scope === ProductEnrichmentBatch::SCOPE_PRODUCT) {
                $product = $productsById->get((int) $batch->scope_id);
                if ($product !== null) {
                    $manufacturer = $product->manufacturer;
                    $currentProductId ??= (int) $product->id;
                }
            }

            $out[(int) $batch->id] = [
                'manufacturer' => is_string($manufacturer) && $manufacturer !== '' ? $manufacturer : null,
                'current_product_id' => $currentProductId,
                'price_list_id' => $priceListId,
            ];
        }

        return $out;
    }
}
