<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyProductsRequest;
use App\Http\Requests\UpdateProductCategoryRequest;
use App\Http\Requests\UpdateProductManualSpecsRequest;
use App\Http\Requests\UpdateProductShopSourceRequest;
use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PrestaCategory;
use App\Models\PrestaProductMatch;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\Enrichment\EnrichmentDescriptionTemplateService;
use App\Services\NbpExchangeRateService;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\ProductDeletionService;
use App\Services\ProductKitService;
use App\Support\BhpAttributeNormalizer;
use App\Support\ManufacturerNormFacts;
use App\Support\ProductModelFuzzy;
use App\Support\ProductPriceChangeResolver;
use App\Support\ProductVariantPresenter;
use App\Support\SupplierSpecialPrice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ProductController extends Controller
{
    public function __construct(
        private readonly NbpExchangeRateService $fx,
        private readonly ProductModelFuzzy $modelFuzzy,
        private readonly EnrichmentDescriptionTemplateService $descriptionTemplates,
        private readonly ProductKitService $kit,
        private readonly ProductDeletionService $deletion,
        private readonly ProductPriceChangeResolver $priceChanges,
        private readonly ProductVariantPresenter $variants,
        private readonly ProductEffectivePrice $effectivePrice,
        private readonly B2bConnectorRegistry $connectors,
        private readonly BhpAttributeNormalizer $bhpAttributes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->select([
                'id',
                'sku',
                'name',
                'model_name',
                'manufacturer',
                'category',
                'catalog_price_net',
                'discount_percent',
                'purchase_price',
                'currency',
                'pack_qty',
                'packaging',
                'stock',
                'description',
                'enrichment_status',
                'enriched_at',
                'enrichment_error',
            ])
            ->withCount(['substitutes', 'images', 'documents'])
            // Karta bez opisu może mieć wiersze ze sklepu dostawcy — lista ma to pokazać zamiast samego „—”.
            // Sama flaga, bez treści: wiersze idą dopiero w /products/{id} (shop_fields).
            ->withExists('shopCards')
            ->with([
                'images' => static fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'documents' => static fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'prestaExport',
            ]);

        $searchTerm = null;
        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $searchTerm = $term;
            $brands = $this->modelFuzzy->catalogBrands($term);
            $modelNeedles = $this->modelFuzzy->catalogModelNeedles($term);

            if ($modelNeedles !== []) {
                $wordDigitPairs = $this->modelFuzzy->catalogModelWordDigitPairs($term);
                $query->where(function ($builder) use ($modelNeedles, $wordDigitPairs) {
                    foreach ($modelNeedles as $needle) {
                        $esc = '%'.addcslashes($needle, '%_\\').'%';
                        $builder->orWhere('sku', 'like', $esc)
                            ->orWhere('name', 'like', $esc)
                            ->orWhere('search_blob', 'like', $esc);
                    }
                    foreach ($wordDigitPairs as [$word, $num]) {
                        $w = '%'.addcslashes($word, '%_\\').'%';
                        $n = '%'.addcslashes($num, '%_\\').'%';
                        $builder->orWhere(function ($q) use ($w, $n) {
                            foreach (['sku', 'name', 'search_blob'] as $col) {
                                $q->orWhere(function ($q2) use ($col, $w, $n) {
                                    $q2->where($col, 'like', $w)->where($col, 'like', $n);
                                });
                            }
                        });
                    }
                });
            } else {
                $like = '%'.$term.'%';
                $codes = $this->modelFuzzy->shortCodes($term);
                $tokens = $brands === [] ? [] : $this->queryTokens($term, $brands);
                $query->where(function ($builder) use ($like, $term, $codes, $brands, $tokens) {
                    $builder->where('sku', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('manufacturer', 'like', $like);
                    if ($term !== '') {
                        $builder->orWhere('sku', $term);
                    }
                    foreach ($codes as $code) {
                        $esc = '%'.addcslashes($code, '%_\\').'%';
                        $builder->orWhere('sku', 'like', $esc)
                            ->orWhere('name', 'like', $esc);
                    }
                    foreach ($tokens as $token) {
                        $esc = '%'.addcslashes($token, '%_\\').'%';
                        $builder->orWhere('sku', 'like', $esc)
                            ->orWhere('name', 'like', $esc);
                    }
                    if ($brands !== [] && $codes === [] && $tokens === []) {
                        foreach ($brands as $brand) {
                            $esc = '%'.addcslashes($brand, '%_\\').'%';
                            $builder->orWhere('manufacturer', 'like', $esc)
                                ->orWhere('name', 'like', $esc);
                        }
                    }
                });
            }
            if ($brands !== []) {
                $query->where(function ($builder) use ($brands) {
                    foreach ($brands as $brand) {
                        $esc = '%'.addcslashes($brand, '%_\\').'%';
                        $builder->orWhere('manufacturer', 'like', $esc)
                            ->orWhere('name', 'like', $esc);
                    }
                });
            }
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        if ($request->filled('manufacturer')) {
            $query->where('manufacturer', (string) $request->string('manufacturer'));
        }
        // Karty cennika konta B2B — po powiązaniach, nie po producencie: dystrybutor (Tegro) sprzedaje cudze marki,
        // więc „producent = nazwa dostawcy” dawał pustą listę.
        if ($request->filled('b2b_account')) {
            $query->whereIn('id', B2bProductLink::query()
                ->where('b2b_account_id', $request->integer('b2b_account'))
                ->select('product_id'));
        }

        $status = trim((string) $request->string('enrichment_status'));
        $allowedStatus = [
            Product::ENRICHMENT_NONE,
            Product::ENRICHMENT_QUEUED,
            Product::ENRICHMENT_RUNNING,
            Product::ENRICHMENT_DONE,
            Product::ENRICHMENT_FAILED,
            Product::ENRICHMENT_MANUAL,
        ];
        if ($status !== '' && in_array($status, $allowedStatus, true)) {
            $query->where('enrichment_status', $status);
        }

        if ($request->boolean('has_accessories')) {
            $query->whereHas('accessories');
        }

        // „Tylko ceny specjalne B2B” (albo ceny powyżej rabatu standardowego) — łączy się z filtrem cennika konta
        if ($request->filled('supplier_special')) {
            SupplierSpecialPrice::whereCardStatus($query, (string) $request->string('supplier_special'));
        }

        $allowedSort = [
            'sku' => 'sku',
            'name' => 'name',
            'manufacturer' => 'manufacturer',
            'catalog_price_net' => 'catalog_price_net',
            'currency' => 'currency',
            'discount_percent' => 'discount_percent',
            'description' => 'description',
            'images_count' => 'images_count',
            'enrichment_status' => 'enrichment_status',
            'stock' => 'stock',
            'substitutes_count' => 'substitutes_count',
        ];
        $sortKey = (string) $request->string('sort', 'name');
        $sortCol = $allowedSort[$sortKey] ?? 'name';
        $dir = strtolower((string) $request->string('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // Wpisany numer katalogowy wychodzi pierwszy. Zapytanie o numer jest rozbijane na kawałki
        // („BW200/LB202FLR/AZ003/2AZ029” → „w200”, „lb202flr”, „az003”…) i zwraca całą rodzinę wyrobu, więc
        // szukana karta stała dotąd w środku listy ułożonej alfabetycznie — na siódmej stronie wyników.
        // Zbioru wyników to nie zawęża: zmienia się tylko kolejność, wybrane sortowanie zostaje kluczem dalszym.
        if ($searchTerm !== null && $searchTerm !== '') {
            $query->orderByRaw(
                'CASE WHEN sku = ? THEN 0 WHEN sku LIKE ? THEN 1 ELSE 2 END ASC',
                [$searchTerm, '%'.addcslashes($searchTerm, '%_\\').'%'],
            );
        }

        if ($sortCol === 'catalog_price_net') {
            $query->orderByRaw($this->fx->priceOrderSql('catalog_price_net', 'currency').' '.$dir);
        } elseif ($sortCol === 'description') {
            // najpierw z opisem / bez, potem alfabetycznie po treści
            $query->orderByRaw(
                $dir === 'asc'
                    ? "(CASE WHEN description IS NULL OR TRIM(description) = '' THEN 1 ELSE 0 END) ASC, description ASC"
                    : "(CASE WHEN description IS NULL OR TRIM(description) = '' THEN 1 ELSE 0 END) DESC, description DESC"
            );
        } else {
            $query->orderBy($sortCol, $dir);
        }
        if ($sortCol !== 'name') {
            $query->orderBy('name', 'asc');
        }

        $rawPerPage = strtolower(trim((string) $request->input('per_page', '100')));
        if ($rawPerPage === 'all') {
            $perPage = 25000;
        } else {
            $perPage = min(1000, max(1, (int) $rawPerPage));
        }
        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function (Product $product): array {
            $row = $product->toArray();
            $row['images'] = $product->images->map(static fn ($img): array => [
                'id' => $img->id,
                'url' => $img->url(),
                'thumb_url' => $img->thumbUrl(),
                'source_url' => $img->source_url,
                'is_primary' => $img->is_primary,
                'sort_order' => $img->sort_order,
            ])->values()->all();
            $row['documents'] = $product->documents->map(static fn ($doc): array => [
                'id' => $doc->id,
                'url' => $doc->url(),
                'source_url' => $doc->source_url,
                'title' => $doc->title,
                'kind' => $doc->kind,
                'size_bytes' => $doc->size_bytes,
                'sort_order' => $doc->sort_order,
            ])->values()->all();
            $row['presta_export'] = $this->prestaExportPayload($product);
            $row['has_shop_fields'] = (bool) $product->getAttribute('shop_cards_exists');
            unset($row['shop_cards_exists']);

            return $this->fx->appendPricePln($row);
        });

        // Ostatnia zmiana ceny tylko dla zwróconej strony — kilka zapytań na całą stronę.
        $pageIds = $page->getCollection()->map(static fn (array $row): int => (int) $row['id'])->all();
        $changes = $this->priceChanges->latestChanges($pageIds);
        // Wersje z cenami (karta ma cenę 0): liczba aktywnych i „od” — jedno zapytanie na stronę.
        $variantSummaries = $this->variants->listSummaries($pageIds);
        // opis z cennika B2B (status AI „Z B2B”, bez zbiorczego nadpisywania) — dwa zapytania na stronę
        $fromB2b = app(B2bDescriptionSource::class)->productIds($pageIds);
        // cena specjalna dostawcy przy cenie karty (lista i ProductSearchSelect) — jedno zapytanie na stronę
        $evaluable = $this->evaluableSlotsByProduct($pageIds);
        $page->getCollection()->transform(function (array $row) use ($changes, $variantSummaries, $fromB2b, $evaluable): array {
            $row['supplier_special'] = $this->cardSupplierSpecial(
                $row['purchase_price'] ?? null,
                $row['currency'] ?? null,
                $evaluable[(int) $row['id']] ?? [],
            );
            $row['last_price_change'] = $changes[(int) $row['id']] ?? null;
            $row['description_from_b2b'] = isset($fromB2b[(int) $row['id']]);
            $summary = $variantSummaries[(int) $row['id']] ?? null;
            $row['variants_count'] = $summary['variants_count'] ?? 0;
            $row['variants_min_price'] = $summary['variants_min_price'] ?? null;
            $row['variants_currency'] = $summary['variants_currency'] ?? null;

            return $row;
        });

        return response()->json($page);
    }

    public function categoryOptions(): JsonResponse
    {
        $options = PrestaCategory::query()
            ->where('active', true)
            ->orderBy('path')
            ->orderBy('name')
            ->get()
            ->map(static function (PrestaCategory $row): array {
                $path = trim((string) ($row->path !== '' ? $row->path : $row->name));

                return [
                    'value' => $path,
                    'label' => $path !== '' ? $path : (string) $row->name,
                ];
            })
            ->filter(static fn (array $row): bool => $row['value'] !== '' && mb_strlen($row['value']) <= 255)
            ->unique('value')
            ->values()
            ->all();

        return response()->json(['data' => $options]);
    }

    public function updateCategory(UpdateProductCategoryRequest $request, Product $product): JsonResponse
    {
        $category = trim((string) $request->validated('category'));
        $product->category = $category !== '' ? $category : null;
        $product->save();

        return response()->json([
            'category' => $product->category,
        ]);
    }

    /**
     * Parametry wpisane ręcznie — jedyne dane karty, których nie rusza żadna automatyka.
     * Zapis zastępuje cały zestaw wierszy: panel przysyła tabelkę w całości.
     */
    public function updateManualSpecs(UpdateProductManualSpecsRequest $request, Product $product): JsonResponse
    {
        $rows = Product::manualSpecRows($request->validated('specs'));
        $product->manual_specs = $rows !== [] ? $rows : null;
        $product->save();

        return response()->json([
            'manual_specs' => $product->manual_specs,
        ]);
    }

    public function updateShopSource(UpdateProductShopSourceRequest $request, Product $product): JsonResponse
    {
        $url = trim((string) ($request->validated('shop_source_url') ?? ''));
        $product->shop_source_url = $url !== '' ? mb_substr($url, 0, 2000) : null;
        $product->save();

        return response()->json([
            'shop_source_url' => $product->shop_source_url,
        ]);
    }

    public function manufacturers(): JsonResponse
    {
        $list = Product::query()
            ->whereNotNull('manufacturer')
            ->where('manufacturer', '!=', '')
            ->distinct()
            ->orderBy('manufacturer')
            ->pluck('manufacturer')
            ->values()
            ->all();

        return response()->json(['data' => $list]);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load([
            'substitutes.substituteProduct:id,sku,name,manufacturer,catalog_price_net',
            'substitutes.approver:id,name',
            'images',
            'documents',
            'specialPrices.client:id,name',
            'prestaExport',
            'accessories.relatedProduct.images',
            'shopCards.account:id,connector,sites',
        ]);

        $payload = $this->withManufacturerNorms($product->toArray(), $product);
        $payload['images'] = $product->images->map(static fn ($img): array => [
            'id' => $img->id,
            'url' => $img->url(),
            'thumb_url' => $img->thumbUrl(),
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

        $history = ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->limit(2)
            ->get();
        $latest = $history->first();
        $previous = $history->skip(1)->first();
        $catalogChangePct = null;
        if (
            $latest !== null
            && $previous !== null
            && $previous->catalog_price_net !== null
            && (float) $previous->catalog_price_net > 0
            && $latest->catalog_price_net !== null
        ) {
            $catalogChangePct = round(
                (((float) $latest->catalog_price_net - (float) $previous->catalog_price_net)
                    / (float) $previous->catalog_price_net) * 100,
                1
            );
        }
        $payload['price_change_percent'] = $catalogChangePct;
        $payload['price_history_latest_at'] = $latest?->created_at;
        $payload['last_price_change'] = $this->priceChanges->latestChanges([(int) $product->id])[(int) $product->id] ?? null;
        $payload['variants'] = $this->variants->forProduct((int) $product->id);
        $slots = ProductSourcePrice::query()
            ->with(['account:id,connector,sites', 'priceList:id,manufacturer,version'])
            ->where('product_id', $product->id)
            ->get();
        $payload['source_prices'] = $this->sourcePricesPayload($product, $slots);
        // ta sama reguła co na liście: ocena tylko dla slotu, którego cena jest ceną karty
        $payload['supplier_special'] = $this->cardSupplierSpecial($product->purchase_price, $product->currency, $slots);
        // relacja doładowana tylko po to, by zbudować shop_fields — surowe wiersze nie mają być w odpowiedzi dwa razy
        unset($payload['shop_cards']);
        $payload['shop_fields'] = $this->shopFieldsPayload($product);
        $payload['description_from_b2b'] = app(B2bDescriptionSource::class)->has($product);
        $payload = $this->fx->appendPricePln($payload);
        $payload['presta_export'] = $this->prestaExportPayload($product);
        $payload['accessories'] = $this->kit->present($product);
        $payload['description_layout'] = $this->descriptionTemplates->resolvedForProduct($product);
        $payload['special_prices'] = $product->specialPrices->map(static fn ($row): array => [
            'id' => $row->id,
            'client_id' => $row->client_id,
            'client_name' => $row->client_name,
            'price' => $row->price,
            'currency' => $row->currency,
            'valid_from' => $row->valid_from?->format('Y-m-d'),
            'contract_ref' => $row->contract_ref !== '' ? $row->contract_ref : null,
        ])->values()->all();

        return response()->json($payload);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Brak autoryzacji.'], 401);
        }

        try {
            $result = $this->deletion->deleteMany([(int) $product->id], $user);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Nie udało się usunąć produktu: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => sprintf('Usunięto produkt %s.', (string) $product->sku),
            ...$result,
        ]);
    }

    public function destroyMany(DestroyProductsRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Brak autoryzacji.'], 401);
        }

        $ids = array_map(
            static fn (mixed $id): int => (int) $id,
            $request->validated('product_ids')
        );

        try {
            $result = $this->deletion->deleteMany($ids, $user);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Nie udało się usunąć produktów: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => $result['deleted'] === 1
                ? 'Usunięto 1 produkt.'
                : sprintf('Usunięto %d produktów.', $result['deleted']),
            ...$result,
        ]);
    }

    public function priceHistory(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->priceChanges->history((int) $product->id, 100)]);
    }

    public function variantPriceHistory(Product $product, ProductVariant $variant): JsonResponse
    {
        if ((int) $variant->product_id !== (int) $product->id) {
            abort(404);
        }

        return response()->json(['data' => $this->variants->history((int) $variant->id, 100)]);
    }

    /**
     * Ceny karty osobno dla każdego źródła (product_source_prices). Kolejność: slot, z którego pochodzi cena karty,
     * potem konta B2B (najświeżej sprawdzone wyżej), na końcu cennik z pliku. Etykieta konta bez loginu i hasła.
     *
     * @param  Collection<int, ProductSourcePrice>  $slots  sloty karty z account i priceList
     * @return list<array<string, mixed>>
     */
    private function sourcePricesPayload(Product $product, Collection $slots): array
    {
        if ($slots->isEmpty()) {
            return [];
        }
        // null, gdy karta ma aktywne wersje — wtedy żaden slot nie ustala ceny karty
        $effectiveKey = $this->effectivePrice->resolve($product)['source_key'] ?? null;
        $rank = static fn (ProductSourcePrice $slot): array => [
            $slot->source_key === $effectiveKey ? 0 : 1,
            $slot->isB2b() ? 0 : 1,
            -($slot->checked_at?->getTimestamp() ?? 0),
        ];

        return $slots
            ->sort(static fn (ProductSourcePrice $a, ProductSourcePrice $b): int => $rank($a) <=> $rank($b))
            ->map(fn (ProductSourcePrice $slot): array => [
                'source_key' => $slot->source_key,
                'source_label' => $this->sourcePriceLabel($slot),
                'catalog_price_net' => $slot->catalog_price_net,
                'purchase_price' => $slot->purchase_price,
                'discount_percent' => $slot->discount_percent,
                // cennik bazowy dostawcy (UVEX) dosłownie ze slotu — podstawa oceny supplier_special
                'base_price_net' => $slot->base_price_net,
                'base_price_category' => $slot->base_price_category,
                'base_price_code' => $slot->base_price_code,
                'base_price_source' => $slot->base_price_source,
                'standard_discount_percent' => $slot->standard_discount_percent,
                // cena specjalna dostawcy (wniosek z porównania); null = brak ceny bazowej albo reguły rabatu.
                // Nie mylić z special_prices — to ceny kontraktowe klientów.
                'supplier_special' => SupplierSpecialPrice::forSlot($slot),
                'currency' => $slot->currency,
                'availability' => $slot->availability,
                'checked_at' => $slot->checked_at?->toISOString(),
                'migrated' => (bool) $slot->migrated,
                'is_effective' => $effectiveKey !== null && $slot->source_key === $effectiveKey,
            ])
            ->values()
            ->all();
    }

    /**
     * Ocena ceny specjalnej dla ceny widocznej na karcie: slot B2B z oceną, którego cena zakupu i waluta są
     * dokładnie ceną karty. Gdy cenę karty ustala inne źródło (plik, inne konto) albo wersje, znacznik przy cenie
     * karty byłby nieprawdą o cenie, której użytkownik nie widzi — wtedy null. Przy kilku pasujących slotach
     * wygrywa najświeżej sprawdzony, jak w ProductEffectivePrice.
     *
     * @param  iterable<ProductSourcePrice>  $slots  sloty karty (dowolne — filtr tutaj)
     * @return array{status: string, standard_price: float, actual_discount_percent: float, saving_net: float}|null
     */
    private function cardSupplierSpecial(mixed $cardPurchase, mixed $cardCurrency, iterable $slots): ?array
    {
        if ($cardPurchase === null || $cardPurchase === '') {
            return null;
        }
        $price = round((float) $cardPurchase, 2);
        $currency = strtoupper(trim((string) $cardCurrency));

        $best = null;
        foreach ($slots as $slot) {
            // tylko sloty z oceną — jak na liście (evaluableSlotsByProduct); inaczej świeższy slot innego konta
            // o tej samej cenie zasłaniałby w szczegółach ocenę, którą lista pokazuje
            if (! $slot->isB2b() || $slot->purchase_price === null || $slot->base_price_net === null || $slot->standard_discount_percent === null) {
                continue;
            }
            // slot bez waluty dziedziczy walutę karty (ProductEffectivePrice::resolve)
            $slotCurrency = $slot->currency !== null ? strtoupper(trim((string) $slot->currency)) : $currency;
            if ($slotCurrency !== $currency || round((float) $slot->purchase_price, 2) !== $price) {
                continue;
            }
            if ($best === null || ($slot->checked_at?->getTimestamp() ?? 0) > ($best->checked_at?->getTimestamp() ?? 0)) {
                $best = $slot;
            }
        }

        return $best !== null ? SupplierSpecialPrice::forSlot($best) : null;
    }

    /**
     * supplier_special dla strony listy — jedno zapytanie na 1000 kart, tylko sloty z oceną (cena bazowa
     * i rabat standardowy), więc przy per_page=all nie ciągniemy wszystkich slotów katalogu.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<ProductSourcePrice>> product_id => sloty
     */
    private function evaluableSlotsByProduct(array $productIds): array
    {
        $out = [];
        foreach (array_chunk($productIds, 1000) as $chunk) {
            $slots = ProductSourcePrice::query()
                ->whereIn('product_id', $chunk)
                ->where('source_key', 'like', 'b2b:%')
                ->whereNotNull('base_price_net')
                ->whereNotNull('standard_discount_percent')
                ->get(['id', 'product_id', 'source_key', 'purchase_price', 'currency', 'base_price_net', 'standard_discount_percent', 'checked_at']);
            foreach ($slots as $slot) {
                $out[(int) $slot->product_id][] = $slot;
            }
        }

        return $out;
    }

    private function sourcePriceLabel(ProductSourcePrice $slot): string
    {
        if ($slot->source_key === ProductSourcePrice::SOURCE_FILE) {
            $list = $slot->priceList;
            $listLabel = $list !== null ? trim(trim((string) $list->manufacturer).' '.trim((string) $list->version)) : '';

            return $listLabel !== '' ? 'Cennik z pliku · '.$listLabel : 'Cennik z pliku';
        }
        if (! $slot->isB2b()) {
            return (string) $slot->source_key;
        }

        return $this->b2bAccountLabel($slot->account);
    }

    /**
     * Etykieta konta B2B wspólna dla cen ze źródeł i danych ze sklepu dostawcy — ta sama nazwa konta ma się
     * pokazywać w obu miejscach karty.
     */
    private function b2bAccountLabel(?B2bAccount $account): string
    {
        if ($account === null) {
            return 'B2B (usunięte konto)';
        }
        // b2b_accounts nie ma nazwy — bez łącznika pierwsza witryna konta (nigdy login ani notatka)
        $name = $this->connectors->label($account->connector)
            ?? (is_array($account->sites) && isset($account->sites[0]) ? trim((string) $account->sites[0]) : '');

        return $name !== '' ? 'B2B '.$name : 'B2B konto #'.$account->id;
    }

    /**
     * Wiersze z kart wyrobu u dostawców (product_shop_cards) — osobno dla każdego konta B2B, treść dosłownie
     * z kolumny fields. To nie jest opis wyrobu: dane idą tylko na kartę, nie do wyszukiwania ani embeddingu.
     * Kolejność: najświeżej pobrane wyżej, przy remisie po numerze konta.
     *
     * @return list<array<string, mixed>>
     */
    private function shopFieldsPayload(Product $product): array
    {
        $rank = static fn (ProductShopCard $card): array => [
            -($card->synced_at?->getTimestamp() ?? 0),
            (int) $card->b2b_account_id,
        ];

        return $product->shopCards
            ->sort(static fn (ProductShopCard $a, ProductShopCard $b): int => $rank($a) <=> $rank($b))
            ->map(fn (ProductShopCard $card): array => [
                'source_key' => ProductSourcePrice::b2bKey((int) $card->b2b_account_id),
                'source_label' => $this->b2bAccountLabel($card->account),
                'b2b_account_id' => (int) $card->b2b_account_id,
                'source_url' => $card->source_url,
                'synced_at' => $card->synced_at?->toISOString(),
                'sections' => $this->shopCardSections($card),
            ])
            ->values()
            ->all();
    }

    /**
     * Sekcje karty dostawcy bez przetwarzania treści; nieoczekiwany kształt kolumny fields (brak wierszy)
     * odpada, żeby front nie dostał pustej tabelki.
     *
     * @return list<array<string, mixed>>
     */
    private function shopCardSections(ProductShopCard $card): array
    {
        $fields = $card->fields;
        if (! is_array($fields)) {
            return [];
        }

        $out = [];
        foreach ($fields as $section) {
            if (! is_array($section) || ! isset($section['rows']) || ! is_array($section['rows']) || $section['rows'] === []) {
                continue;
            }
            $out[] = [
                'section' => (string) ($section['section'] ?? ''),
                'rows' => array_values($section['rows']),
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $brands
     * @return list<string>
     */
    private function queryTokens(string $term, array $brands): array
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];
        $norm = strtr(mb_strtolower($term), $map);
        $out = [];
        foreach (preg_split('/[\s,;:·•\/|+]+/u', $norm) ?: [] as $token) {
            $c = preg_replace('/[^a-z0-9]/', '', $token) ?? '';
            if (mb_strlen($c) < 4 || in_array($c, $brands, true)) {
                continue;
            }
            $out[] = $c;
        }

        return array_values(array_unique($out));
    }

    /**
     * Karta pokazuje pola z hierarchią źródeł przeliczone (BhpAttributeNormalizer::forDisplay), a nie zapisane.
     * Zapisane pochodzą z opisu wzbogaconego ze sklepów: przyniosły nieaktualne poziomy EN 388 (25 z 54 kart
     * ATG, sprawdzone 20.09.2026) i klasy cudzych wariantów (ARTRA „ARMEN 900 6060 O1 FO” z „S2 CI SRC”
     * z empiku, 22.09.2026). Przeliczone znają hierarchię źródeł — producent, cennik, nazwa i tabelka dostawcy
     * biją opis — więc karta pokazuje to, czym dopasowanie do wymagania przetargu naprawdę się liczy.
     *
     * Payloadu w bazie nie ruszamy: pozostaje zapisem tego, co przyniosło wzbogacanie. Karta bez atrybutów
     * i bez norm producenta zostaje bez zmian — nie udajemy wzbogacenia, którego nie było.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withManufacturerNorms(array $row, Product $product): array
    {
        $stored = is_array($row['enrichment_payload'] ?? null) ? $row['enrichment_payload'] : [];
        if (! is_array($stored['attributes'] ?? null) && ManufacturerNormFacts::norms($product->manufacturer_norms) === []) {
            return $row;
        }

        // Z przeliczenia tylko pola z hierarchią źródeł, a normy nie z prozy — przeliczone normy_en mają też
        // normy ze zdania „nie podają zgodności z EN 407”; brak informacji wyszedłby jako fakt (forDisplay).
        $display = $this->bhpAttributes->forDisplay($product);
        $payload = $stored;
        $payload['attributes'] = $display['attributes'];
        $payload['norms'] = $display['norms'];
        $row['enrichment_payload'] = $payload;

        return $row;
    }

    /**
     * @return array{presta_id: int, url: string, status: string}|null
     */
    private function prestaExportPayload(Product $product): ?array
    {
        $match = $product->prestaExport;
        if (! $match instanceof PrestaProductMatch || (int) $match->presta_id <= 0) {
            return null;
        }

        return [
            'presta_id' => (int) $match->presta_id,
            'url' => (string) ($match->presta_url ?? ''),
            'status' => (string) $match->status,
        ];
    }
}
