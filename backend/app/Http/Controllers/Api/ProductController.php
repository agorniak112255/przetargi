<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyProductsRequest;
use App\Http\Requests\UpdateProductCategoryRequest;
use App\Http\Requests\UpdateProductManualSpecsRequest;
use App\Http\Requests\UpdateProductShopSourceRequest;
use App\Models\B2bAccount;
use App\Models\B2bDescriptionSupplementAttempt;
use App\Models\B2bProductLink;
use App\Models\ErpItemLink;
use App\Models\PrestaCategory;
use App\Models\PrestaProductMatch;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\B2b\B2bDescriptionSupplement;
use App\Services\Catalog\CardSourceModels;
use App\Services\Enrichment\EnrichmentDescriptionTemplateService;
use App\Services\Erp\ErpCardStock;
use App\Services\Erp\ErpCodeSearch;
use App\Services\NbpExchangeRateService;
use App\Services\PriceListCards;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\Pricing\SourcePriceComparison;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\ProductDeletionService;
use App\Services\ProductKitService;
use App\Services\Search\ProductIdentifierSearch;
use App\Services\Search\ProductListTextSearch;
use App\Support\BhpAttributeNormalizer;
use App\Support\ManufacturerNormFacts;
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
        private readonly ProductListTextSearch $textSearch,
        private readonly EnrichmentDescriptionTemplateService $descriptionTemplates,
        private readonly ProductKitService $kit,
        private readonly ProductDeletionService $deletion,
        private readonly ProductPriceChangeResolver $priceChanges,
        private readonly ProductVariantPresenter $variants,
        private readonly ProductEffectivePrice $effectivePrice,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly SourcePriceComparison $comparison,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // widok cen specjalnych B2B: bez uprawnienia karta z ceną specjalną pokazuje cenę standardową
        $mask = SupplierSpecialMask::forUser($request->user());
        // lista „tylko ceny specjalne” zdradzałaby, które karty je mają (decyzja D5); gorsze niż standard — dozwolone
        if ($mask->hides() && (string) $request->string('supplier_special') === SupplierSpecialPrice::SPECIAL) {
            abort(403, 'Brak uprawnienia do cen specjalnych B2B.');
        }

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
        // karty wskazane kodem towaru ERP XL (id → kody XL) i numerem ze źródła ceny (id → trafione numery, np. drugi
        // kolor karty łączonej) — dochodzą do wyników obok dopasowań SKU i nazwy
        $erpCodes = [];
        $numberHits = [];
        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $searchTerm = $term;
            // words=all (okno „Połącz towar XL z kartą”): każde słowo zawęża listę; jedno słowo — jak zwykle
            $words = (string) $request->string('words') === 'all' ? ProductListTextSearch::phraseWords($term) : [];
            if (count($words) === 1) {
                // „RTEPO,” albo „a rtepo” — jedno słowo po odcięciu interpunkcji i jednoznakowych; numer pierwszy po nim
                $term = $searchTerm = $words[0];
            }
            if (count($words) > 1) {
                $erpByWord = [];
                foreach ($words as $word) {
                    $wordCodes = app(ErpCodeSearch::class)->productCodes($word);
                    $wordNumbers = app(ProductIdentifierSearch::class)->productCodes($word);
                    $erpByWord[$word] = array_values(array_unique([...array_keys($wordCodes), ...array_keys($wordNumbers)]));
                    foreach ($wordCodes as $id => $codes) {
                        $erpCodes[$id] = array_values(array_unique([...($erpCodes[$id] ?? []), ...$codes]));
                    }
                    foreach ($wordNumbers as $id => $hit) {
                        $numberHits[$id] = [
                            'codes' => array_values(array_unique([...($numberHits[$id]['codes'] ?? []), ...$hit['codes']])),
                            'exact' => ($numberHits[$id]['exact'] ?? false) || $hit['exact'],
                        ];
                    }
                }
                $this->textSearch->applyAllWords($query, $words, $erpByWord);
            } else {
                $erpCodes = app(ErpCodeSearch::class)->productCodes($term);
                $numberHits = app(ProductIdentifierSearch::class)->productCodes($term);
                $codeIds = array_values(array_unique([...array_keys($erpCodes), ...array_keys($numberHits)]));
                if ($codeIds !== []) {
                    $query->where(fn ($outer) => $outer
                        ->where(fn ($text) => $this->textSearch->applyTextSearch($text, $term))
                        ->orWhereIn('id', $codeIds));
                } else {
                    $this->textSearch->applyTextSearch($query, $term);
                }
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
        // Karty cennika z pliku — ostatni import i karty ze slotem ceny z tego cennika (PriceListCards), nie producent:
        // cennik wielomarkowy (Canis) ma karty wielu marek. Nieznany cennik = pusta lista. Liczby wpisane wprost
        // (whereIntegerInRaw) — duży cennik nie dochodzi do limitu parametrów zapytania.
        if ($request->filled('price_list')) {
            $priceList = PriceList::query()->find($request->integer('price_list'));
            // price_list_file=1 (Cenniki → „Z pliku”): tylko karty ze slotem ceny z pliku — product_ids wpisu wspólnego
            // z kontem B2B bywa listą kart konta
            $listIds = $priceList === null ? [] : ($request->boolean('price_list_file')
                ? app(PriceListCards::class)->fileSlotIds($priceList)
                : app(PriceListCards::class)->ids($priceList));
            if ($listIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIntegerInRaw('id', $listIds);
            }
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

        // „Tylko produkty XL” — karty z pewnym albo potwierdzonym powiązaniem z towarem, który jest w ERP XL
        // (karty, które pokazują „Stan XL”; propozycje do sprawdzenia się nie liczą)
        if ($request->boolean('erp_linked')) {
            $query->whereIn('id', ErpItemLink::query()
                ->whereIn('status', [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_CONFIRMED])
                ->whereNotNull('product_id')
                ->whereHas('item', static fn ($q) => $q->whereNull('removed_at'))
                ->select('product_id'));
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
        // Karta wskazana kodem ERP XL albo numerem ze źródła równym całej frazie stoi razem z dokładnym SKU.
        if ($searchTerm !== null && $searchTerm !== '') {
            $this->textSearch->orderByMatch(
                $query,
                $searchTerm,
                array_values(array_unique([...array_keys($erpCodes), ...ProductIdentifierSearch::exactIds($numberHits)])),
            );
        }

        // Sortowanie idzie po cenach zapisanych: karta z ceną specjalną stoi tam, gdzie jej cena konta, choć widz bez
        // uprawnienia widzi cenę standardową — kolejność zdradza najwyżej pozycję, nie kwotę (świadomy kompromis).
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
        // karty o tej samej nazwie w stałej kolejności — „Pokaż więcej” nie powtarza ani nie gubi kart między stronami
        $query->orderBy('id');

        $rawPerPage = strtolower(trim((string) $request->input('per_page', '100')));
        if ($rawPerPage === 'all') {
            $perPage = 25000;
        } else {
            $perPage = min(1000, max(1, (int) $rawPerPage));
        }
        $page = $query->paginate($perPage);

        // modele strony do explain() przy ocenie ceny specjalnej (niżej wiersze są już tablicami)
        $models = [];
        $page->getCollection()->transform(function (Product $product) use (&$models): array {
            $models[(int) $product->id] = $product;
            $row = $product->toArray();
            $row['images'] = $product->images->map(static fn (ProductImage $img): array => $img->panelView())->values()->all();
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
        // karty i sloty z oceną dla całej strony naraz (maska odsłaniająca nic nie czyta)
        $mask->preload($pageIds);
        $changes = $this->priceChanges->latestChanges($pageIds, $mask);
        // Wersje z cenami (karta ma cenę 0): liczba aktywnych i „od” — jedno zapytanie na stronę.
        $variantSummaries = $this->variants->listSummaries($pageIds);
        // opis z cennika B2B (status AI „Z B2B”, bez zbiorczego nadpisywania) — dwa zapytania na stronę
        $fromB2b = app(B2bDescriptionSource::class)->productIds($pageIds);
        // opis z B2B uzupełniony ze stron konta i karty w kolejce uzupełniania — dwa zapytania na stronę
        $origins = $this->descriptionOrigins($pageIds);
        // cena specjalna dostawcy przy cenie karty (lista i ProductSearchSelect) — jedno zapytanie na stronę
        $evaluable = $this->evaluableSlotsByProduct($pageIds);
        // Karta z jednym źródłem ceny nie ma czego rozstrzygać — jej slot w cenie karty jest tym obowiązującym.
        // explain() (kilka zapytań) tylko dla kart z kilkoma slotami: przy per_page=all UVEX to ~1200 kart.
        $slotCounts = $evaluable === [] ? [] : ProductSourcePrice::query()
            ->whereIn('product_id', array_keys($evaluable))
            ->selectRaw('product_id, count(*) as c')
            ->groupBy('product_id')
            ->pluck('c', 'product_id')
            ->all();
        // „taniej u …” przy cenie — informacja, cena karty bez zmian; stała liczba zapytań na stronę
        $cheaper = $this->comparison->cheaperSources(collect(array_values($models)), $mask);
        // warunek zamawiania obowiązującego źródła (UVEX „po 10 szt.”) — stała liczba zapytań na stronę
        $orderQuantities = $this->comparison->orderQuantities(collect(array_values($models)), $mask);
        // modele połączone w karcie (ELTEN red + black, kolory Portwest, Mascot…) — do czterech zapytań na stronę
        $sourceModels = app(CardSourceModels::class)->forProducts($pageIds);
        $page->getCollection()->transform(function (array $row) use ($changes, $variantSummaries, $fromB2b, $origins, $evaluable, $models, $slotCounts, $cheaper, $orderQuantities, $erpCodes, $numberHits, $sourceModels, $mask): array {
            $id = (int) $row['id'];
            $row['cheaper_source'] = $cheaper[$id] ?? null;
            $row['order_quantity'] = $orderQuantities[$id] ?? null;
            $candidates = $this->slotsAtCardPrice($row['purchase_price'] ?? null, $row['currency'] ?? null, $evaluable[$id] ?? []);
            // explain() tylko dla kart ze slotem ocenionym w cenie karty — pozostałe i tak nie mają znacznika
            $winner = match (true) {
                $candidates === [] || ! isset($models[$id]) => null,
                (int) ($slotCounts[$id] ?? 0) === 1 => $candidates[0],
                default => $this->effectivePrice->explain($models[$id])['winner'],
            };
            $row['supplier_special'] = $winner === null ? null : $this->cardSupplierSpecial($candidates, $winner);
            $row['last_price_change'] = $changes[(int) $row['id']] ?? null;
            $row['description_from_b2b'] = isset($fromB2b[(int) $row['id']]);
            $row['description_supplement'] = $origins[(int) $row['id']] ?? null;
            $summary = $variantSummaries[(int) $row['id']] ?? null;
            $row['variants_count'] = $summary['variants_count'] ?? 0;
            $row['variants_min_price'] = $summary['variants_min_price'] ?? null;
            $row['variants_currency'] = $summary['variants_currency'] ?? null;
            // kody ERP XL, po których wyszukiwarka znalazła kartę (pusta lista, gdy trafiła po SKU albo nazwie)
            $row['erp_codes'] = $erpCodes[(int) $row['id']] ?? [];
            // numery ze źródła ceny, po których wyszukiwarka znalazła kartę (bez numeru równego SKU karty)
            $row['matched_codes'] = $numberHits[(int) $row['id']]['codes'] ?? [];
            $row['source_models'] = $sourceModels[(int) $row['id']] ?? [];

            // na końcu: ocena wyżej szuka slotu w prawdziwej cenie karty; maska podmienia ceny i ocenę na standardowe
            return $mask->productRow($row);
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
        // wybór człowieka: dowód rodzaju wyrobu, a automaty (przepisanie na drzewo, import) go nie nadpisują
        $product->category_source = $category !== '' ? Product::CATEGORY_SOURCE_MANUAL : null;
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

    /**
     * Ile kart trafia każde słowo frazy osobno (te same warunki co /products?q=<słowo>, z kodem ERP XL) — okno
     * „Połącz towar XL z kartą” podpowiada słowa, gdy cała fraza nie trafia żadnej karty. Same liczby, bez wierszy
     * i cen: jedno zapytanie COUNT na słowo zamiast pełnej strony /products.
     */
    public function wordCounts(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:200']]);
        $codes = app(ErpCodeSearch::class);
        $numbers = app(ProductIdentifierSearch::class);
        $out = [];
        foreach (ProductListTextSearch::phraseWords((string) $request->string('q'), 6) as $word) {
            $count = Product::query();
            $ids = array_values(array_unique([...array_keys($codes->productCodes($word)), ...array_keys($numbers->productCodes($word))]));
            $this->textSearch->applyAllWords($count, [$word], [$word => $ids]);
            $out[] = ['word' => $word, 'count' => $count->count()];
        }

        return response()->json(['words' => $out]);
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

    public function show(Request $request, Product $product): JsonResponse
    {
        // widok cen specjalnych B2B: bez uprawnienia karta z ceną specjalną pokazuje cenę standardową
        $mask = SupplierSpecialMask::forUser($request->user());
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
        $payload['images'] = $product->images->map(static fn (ProductImage $img): array => $img->panelView())->values()->all();
        $payload['documents'] = $product->documents->map(static fn ($doc): array => [
            'id' => $doc->id,
            'url' => $doc->url(),
            'source_url' => $doc->source_url,
            'title' => $doc->title,
            'kind' => $doc->kind,
            'size_bytes' => $doc->size_bytes,
            'sort_order' => $doc->sort_order,
        ])->values()->all();

        $latest = ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->first();
        $lastChange = $this->priceChanges->latestChanges([(int) $product->id], $mask)[(int) $product->id] ?? null;
        // zmiana katalogowej z ostatniej zmiany ceny (w obrębie jednego źródła), nie z dwóch ostatnich wierszy
        // historii — te bywają z różnych źródeł i walut
        $payload['price_change_percent'] = isset($lastChange['catalog_pct']) ? round((float) $lastChange['catalog_pct'], 1) : null;
        $payload['price_history_latest_at'] = $latest?->created_at;
        $payload['last_price_change'] = $lastChange;
        $payload['variants'] = $this->variants->forProduct((int) $product->id, $mask);
        $payload['source_models'] = app(CardSourceModels::class)->forProducts([(int) $product->id])[(int) $product->id] ?? [];
        $slots = ProductSourcePrice::query()
            ->with(['account:id,connector,sites', 'priceList:id,manufacturer,version,suggested_prices'])
            ->where('product_id', $product->id)
            ->get();
        $explain = $this->effectivePrice->explain($product);
        // porównanie od najtańszej (pola Row przy każdym źródle) — kolejność elementów zostaje jak dotąd
        $comparison = $this->comparison->forCard($product, $slots, $explain, $mask);
        $payload['source_prices'] = $this->sourcePricesPayload($slots, $explain, $comparison['rows'], $mask);
        $payload['source_prices_rates'] = $comparison['rates'];
        // warunek zamawiania slotu obowiązującego (ten, od którego kupujemy); slot z $slots ma wczytane konto
        $winnerSlot = $explain['winner'] === null
            ? null
            : $slots->first(static fn (ProductSourcePrice $s): bool => $s->source_key === $explain['winner']->source_key);
        $payload['order_quantity'] = $winnerSlot === null ? null : $this->comparison->orderQuantityOf($winnerSlot, $mask);
        // ta sama reguła co na liście: ocena tylko dla slotu obowiązującego, którego cena jest ceną karty
        $payload['supplier_special'] = $this->cardSupplierSpecial(
            $this->slotsAtCardPrice($product->purchase_price, $product->currency, $slots),
            $explain['winner'],
        );
        // relacja doładowana tylko po to, by zbudować shop_fields — surowe wiersze nie mają być w odpowiedzi dwa razy
        unset($payload['shop_cards']);
        $payload['shop_fields'] = $this->shopFieldsPayload($product);
        $payload['description_from_b2b'] = app(B2bDescriptionSource::class)->has($product);
        $payload['description_supplement'] = $this->descriptionOrigins([(int) $product->id])[(int) $product->id] ?? null;
        $payload = $this->fx->appendPricePln($payload);
        // ceny karty i ocena ceny specjalnej w widoku standardowym; zamienniki mają cenę katalogową swojej karty
        $payload = $mask->productRow($payload);
        // karty zamienne hurtem (dwa zapytania), nie karta po karcie w productRow
        $mask->preload($product->substitutes->pluck('substitute_product_id')->filter()->all());
        foreach ($payload['substitutes'] ?? [] as $i => $row) {
            if (is_array($row['substitute_product'] ?? null)) {
                $payload['substitutes'][$i]['substitute_product'] = $mask->productRow($row['substitute_product']);
            }
        }
        $payload['presta_export'] = $this->prestaExportPayload($product);
        $payload['accessories'] = $this->kit->present($product);
        $payload['description_layout'] = $this->descriptionTemplates->resolvedForProduct($product);
        // stan i ostatnie zakupy z Comarch ERP XL (towary powiązane z kartą); null = brak powiązania
        $payload['erp_xl'] = $this->erpXlPayload(app(ErpCardStock::class)->forProduct((int) $product->id), (int) $product->id, $mask);
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

        $data = $request->validate([
            'skip_import' => ['sometimes', 'boolean'],
            'b2b_account' => ['sometimes', 'nullable', 'integer', 'exists:b2b_accounts,id'],
        ]);
        $skipImport = (bool) ($data['skip_import'] ?? false);
        $accountId = isset($data['b2b_account']) ? (int) $data['b2b_account'] : null;

        try {
            $result = $this->deletion->deleteMany([(int) $product->id], $user, $skipImport, $accountId);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Nie udało się usunąć produktu: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            // liczbę pomijanych pozycji (positions_excluded) okno usuwania pokazuje osobno
            'message' => $accountId !== null
                ? $this->accountScopedDeletionMessage($result, $accountId)
                : sprintf('Usunięto produkt %s.', (string) $product->sku),
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

        $skipImport = $request->boolean('skip_import');
        $accountId = $request->validated('b2b_account') !== null ? (int) $request->validated('b2b_account') : null;

        try {
            $result = $this->deletion->deleteMany($ids, $user, $skipImport, $accountId);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Nie udało się usunąć produktów: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => match (true) {
                $accountId !== null => $this->accountScopedDeletionMessage($result, $accountId),
                $result['deleted'] === 1 => 'Usunięto 1 produkt.',
                default => sprintf('Usunięto %d produktów.', $result['deleted']),
            },
            ...$result,
        ]);
    }

    /**
     * Wynik usuwania z listy kart konta dostawcy: ile kart odpięto (zostają z innymi źródłami), ile usunięto
     * (tylko z tego konta) i ile pominięto (bez pozycji konta).
     *
     * @param  array{deleted: int, detached: int, refused: list<array{id: int, sku: string, reason: string}>, skipped: int}  $result
     */
    private function accountScopedDeletionMessage(array $result, int $accountId): string
    {
        $label = app(SourcePriceComparison::class)->accountLabel(B2bAccount::query()->find($accountId));
        // „od 1 karty / od 5 kart” i „usunięto 1 kartę / 2 karty / 5 kart”
        $fromCards = static fn (int $n): string => $n.' '.($n === 1 ? 'karty' : 'kart');
        $cards = static function (int $n): string {
            $few = $n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14);

            return $n.' '.($n === 1 ? 'kartę' : ($few ? 'karty' : 'kart'));
        };
        $parts = [];
        if ($result['detached'] > 0) {
            $parts[] = sprintf(
                'Odpięto %s od %s — %s z pozostałymi źródłami.',
                $label,
                $fromCards($result['detached']),
                $result['detached'] === 1 ? 'karta zostaje' : 'karty zostają',
            );
        }
        if ($result['deleted'] > 0) {
            $parts[] = sprintf('Usunięto %s tylko z %s.', $cards($result['deleted']), $label);
        }
        if ($result['skipped'] > 0) {
            $parts[] = sprintf('Pominięto %s bez pozycji %s.', $cards($result['skipped']), $label);
        }
        if ($result['refused'] !== []) {
            $parts[] = 'Nie odpięto: '.implode('; ', array_map(
                static fn (array $row): string => $row['sku'].' — '.$row['reason'],
                $result['refused'],
            ));
        }

        return $parts === [] ? 'Nic nie zmieniono.' : implode(' ', $parts);
    }

    public function priceHistory(Request $request, Product $product): JsonResponse
    {
        return response()->json(['data' => $this->priceChanges->history((int) $product->id, 100, SupplierSpecialMask::forUser($request->user()))]);
    }

    public function variantPriceHistory(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        if ((int) $variant->product_id !== (int) $product->id) {
            abort(404);
        }

        return response()->json(['data' => $this->variants->history($variant, 100, SupplierSpecialMask::forUser($request->user()))]);
    }

    /**
     * Blok „Stan w ERP XL” — ceny zakupu z faktur XL i wartości księgowe partii (magazyny towaru: wartość / ilość
     * = cena zakupu) ukryte na karcie ze slotem konta z oceną ceny specjalnej (decyzja D3): zakup po cenie
     * specjalnej zdradzałby ją wprost. Stany, ilości i daty zostają.
     *
     * @param  array<string, mixed>|null  $erp  ErpCardStock::forProduct
     * @return array<string, mixed>|null
     */
    private function erpXlPayload(?array $erp, int $productId, SupplierSpecialMask $mask): ?array
    {
        if ($erp === null) {
            return null;
        }
        $hidden = $mask->hidesHistory($productId, null);
        if ($hidden) {
            $erp = self::withoutErpValues($erp);
            foreach ($erp['items'] ?? [] as $i => $item) {
                $erp['items'][$i] = self::withoutErpValues($item);
                foreach (['purchases', 'warehouses'] as $list) {
                    foreach (is_array($item[$list] ?? null) ? $item[$list] : [] as $j => $row) {
                        $erp['items'][$i][$list][$j] = is_array($row) ? self::withoutErpValues($row) : $row;
                    }
                }
            }
            if (is_array($erp['last_purchase'] ?? null)) {
                $erp['last_purchase'] = self::withoutErpValues($erp['last_purchase']);
            }
            foreach (is_array($erp['warehouses'] ?? null) ? $erp['warehouses'] : [] as $j => $row) {
                $erp['warehouses'][$j] = is_array($row) ? self::withoutErpValues($row) : $row;
            }
        }
        $erp['prices_hidden'] = $hidden;

        return $erp;
    }

    /**
     * Pola kwot z kosztu zakupu w wierszu bloku ERP (cena PZ, cena dokumentu, wartość partii i każda „…_value”)
     * → null; ilości i daty zostają. Po nazwie klucza, żeby nowa kolumna wartości w XL nie przeszła bokiem;
     * matched_value to kod, po którym powiązano towar, a nie kwota.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function withoutErpValues(array $row): array
    {
        foreach (array_keys($row) as $key) {
            $key = (string) $key;
            if ($key === 'matched_value') {
                continue;
            }
            if (in_array($key, ['unit_price_pln', 'document_price', 'value'], true) || str_ends_with($key, '_value')) {
                $row[$key] = null;
            }
        }

        return $row;
    }

    /**
     * Ceny karty osobno dla każdego źródła (product_source_prices). Kolejność: slot, z którego pochodzi cena karty,
     * potem konta B2B (najświeżej sprawdzone wyżej) i cennik z pliku, na końcu sloty pominięte z powodem
     * (ProductEffectivePrice::explain — np. pierwszeństwo cennika producenta, cena producenta wyłączona w cenniku
     * konta). Etykieta konta bez loginu i hasła.
     *
     * @param  Collection<int, ProductSourcePrice>  $slots  sloty karty z account i priceList
     * @param  array{winner: ProductSourcePrice|null, reasons: array<string, string>}  $explain
     * @param  array<string, array<string, mixed>>  $comparisonRows  SourcePriceComparison::forCard()['rows'] po source_key
     * @param  SupplierSpecialMask  $mask  slot z ceną specjalną w cenie standardowej, z oceną tej ceny (status „standard”)
     * @return list<array<string, mixed>>
     */
    private function sourcePricesPayload(Collection $slots, array $explain, array $comparisonRows, SupplierSpecialMask $mask): array
    {
        if ($slots->isEmpty()) {
            return [];
        }
        // null, gdy karta ma aktywne wersje albo cena karty została z wyłączonego źródła — żaden slot jej nie ustala
        $effectiveKey = $explain['winner']?->source_key;
        $reasons = $explain['reasons'];
        $rank = static fn (ProductSourcePrice $slot): array => [
            $slot->source_key === $effectiveKey ? 0 : 1,
            isset($reasons[(string) $slot->source_key]) ? 1 : 0,
            $slot->isB2b() ? 0 : 1,
            -($slot->checked_at?->getTimestamp() ?? 0),
        ];

        return $slots
            ->sort(static fn (ProductSourcePrice $a, ProductSourcePrice $b): int => $rank($a) <=> $rank($b))
            // kolejność i powody z prawdziwych slotów, ceny i ocena z widoku widza (ta sama instancja bez maski)
            ->map(static fn (ProductSourcePrice $slot): ProductSourcePrice => $mask->maskSlot($slot))
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
                // warunek zamawiania dosłownie ze slotu (tylko B2B); varies = rozmiary karty mają różne warunki
                'order_min_qty' => $slot->order_min_qty,
                'order_step_qty' => $slot->order_step_qty,
                'order_unit' => $slot->order_unit,
                'order_varies' => (bool) $slot->order_varies,
                // warunek ceny dosłownie ze slotu (Delta Plus: cena za pełny karton); null = źródło go nie podaje
                'price_note' => $slot->price_note,
                'price_carton_qty' => $slot->price_carton_qty,
                // druga cena konta: niższa przy pełnym kartonie (BIG); null = źródło jej nie podaje
                'carton_price_net' => $slot->carton_price_net,
                'carton_qty' => $slot->carton_qty,
                'checked_at' => $slot->checked_at?->toISOString(),
                'migrated' => (bool) $slot->migrated,
                'is_effective' => $effectiveKey !== null && $slot->source_key === $effectiveKey,
                // dlaczego ta cena nie obowiązuje (null = explain nie podaje powodu)
                'ignored_reason' => $reasons[(string) $slot->source_key] ?? null,
                // porównanie cen zakupu w PLN (price_rank, is_cheapest, różnica do ceny obowiązującej, powód pominięcia)
                ...($comparisonRows[(string) $slot->source_key] ?? [
                    'purchase_price_pln' => null,
                    'comparable' => false,
                    'not_comparable_reason' => null,
                    'price_rank' => null,
                    'is_cheapest' => false,
                    'diff_to_effective_pct' => null,
                ]),
            ])
            ->values()
            ->all();
    }

    /**
     * Ocena ceny specjalnej dla ceny widocznej na karcie: slot obowiązujący (ProductEffectivePrice::explain), o ile
     * jest slotem B2B z oceną, a jego cena zakupu i waluta są dokładnie ceną karty. Gdy cenę karty ustala inne
     * źródło (plik producenta, inne konto), wersje albo cena karty została z wyłączonego źródła, znacznik byłby
     * nieprawdą o cenie, której użytkownik nie widzi — wtedy null. Slot innego konta o tej samej cenie też nie:
     * jego ocena mówi o rabacie tamtego konta, a nie o cenie karty.
     *
     * @param  list<ProductSourcePrice>  $candidates  sloty z oceną w cenie karty (slotsAtCardPrice)
     * @return array{status: string, standard_price: float, actual_discount_percent: float, saving_net: float, base_price: float, standard_discount_percent: float, category: string|null}|null
     */
    private function cardSupplierSpecial(array $candidates, ?ProductSourcePrice $winner): ?array
    {
        if ($winner === null) {
            return null;
        }
        $best = null;
        foreach ($candidates as $slot) {
            if ($slot->source_key === $winner->source_key) {
                $best = $slot;
            }
        }

        $evaluation = $best !== null ? SupplierSpecialPrice::forSlot($best) : null;
        if ($evaluation === null) {
            return null;
        }

        // kategoria cennika bazowego (UVEX: arkusz) — „cena normalna w kategorii …” przy znaczniku
        return [...$evaluation, 'category' => $best->base_price_category];
    }

    /**
     * Sloty B2B z oceną (cena bazowa i rabat standardowy), których cena zakupu i waluta są dokładnie ceną karty —
     * ta sama reguła na liście (evaluableSlotsByProduct) i w szczegółach.
     *
     * @param  iterable<ProductSourcePrice>  $slots  sloty karty (dowolne — filtr tutaj)
     * @return list<ProductSourcePrice>
     */
    private function slotsAtCardPrice(mixed $cardPurchase, mixed $cardCurrency, iterable $slots): array
    {
        if ($cardPurchase === null || $cardPurchase === '') {
            return [];
        }
        $price = round((float) $cardPurchase, 2);
        $currency = strtoupper(trim((string) $cardCurrency));

        $out = [];
        foreach ($slots as $slot) {
            if (! $slot->isB2b() || $slot->purchase_price === null || $slot->base_price_net === null || $slot->standard_discount_percent === null) {
                continue;
            }
            // slot bez waluty dziedziczy walutę karty (ProductEffectivePrice::resolve)
            $slotCurrency = $slot->currency !== null ? strtoupper(trim((string) $slot->currency)) : $currency;
            if ($slotCurrency === $currency && round((float) $slot->purchase_price, 2) === $price) {
                $out[] = $slot;
            }
        }

        return $out;
    }

    /**
     * Stan uzupełniania opisu B2B ze stron konta (SupplementB2bDescriptionJob) — znacznik na liście i karcie
     * (prośba użytkownika 28.09.2026: po „Uzupełnij krótkie opisy” każda karta dalej pokazywała „Z B2B” i nie było
     * widać, że coś się dzieje):
     * - `queued` — karta czeka w kolejce uzupełniania,
     * - `running` — job właśnie nad nią pracuje (`stage` — bieżący etap),
     * - `waiting_search` — czeka w kolejce na wyszukiwarkę (przerwa po blokadzie), `retry_at` — ponowienie (UTC),
     * - `cancelled` — uzupełnianie zatrzymane przyciskiem (tylko gdy karta nie ma opisu z uzupełnienia),
     * - `supplemented` — obecny opis napisało uzupełnianie (B2bDescriptionSupplement::isSupplementResult), `hosts` to
     *   strony z internetu, z których wziął tekst.
     * Karta w toku (queued, running, waiting_search) pokazuje stan w toku, nawet gdy ma już opis z uzupełnienia.
     * Karta bez żadnego z tych stanów nie ma wpisu. Dwa zapytania na 1000 kart; payload tylko kart ze śladem.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{state: 'queued'|'running'|'waiting_search'|'cancelled'|'supplemented', hosts: list<string>, described_at: string|null, stage: string|null, retry_at: string|null}>
     */
    private function descriptionOrigins(array $productIds): array
    {
        $out = [];
        foreach (array_chunk($productIds, 1000) as $chunk) {
            $rows = Product::query()
                ->whereIn('id', $chunk)
                ->where('enrichment_payload', 'like', '%b2b_supplement%')
                ->toBase()
                ->get(['id', 'description', 'enrichment_payload']);
            foreach ($rows as $row) {
                $payload = json_decode((string) $row->enrichment_payload, true);
                if (! B2bDescriptionSupplement::isSupplementResult((string) $row->description, $payload)) {
                    continue;
                }
                $trace = $payload['b2b_supplement'];
                $hosts = [];
                foreach ((array) ($trace['web_source_urls'] ?? []) as $url) {
                    $host = preg_replace('/^www\./', '', mb_strtolower((string) parse_url((string) $url, PHP_URL_HOST)));
                    if (is_string($host) && $host !== '' && ! in_array($host, $hosts, true)) {
                        $hosts[] = $host;
                    }
                }
                $out[(int) $row->id] = [
                    'state' => 'supplemented',
                    'hosts' => $hosts,
                    'described_at' => is_string($trace['described_at'] ?? null) ? $trace['described_at'] : null,
                    'stage' => null,
                    'retry_at' => null,
                ];
            }
            $attempts = B2bDescriptionSupplementAttempt::query()
                ->whereIn('product_id', $chunk)
                ->whereIn('status', [...B2bDescriptionSupplementAttempt::PENDING_STATUSES, B2bDescriptionSupplementAttempt::STATUS_CANCELLED])
                ->get(['product_id', 'status', 'stage', 'retry_at']);
            foreach ($attempts as $attempt) {
                $id = (int) $attempt->product_id;
                $state = match (true) {
                    $attempt->status === B2bDescriptionSupplementAttempt::STATUS_RUNNING => 'running',
                    $attempt->status === B2bDescriptionSupplementAttempt::STATUS_CANCELLED => 'cancelled',
                    $attempt->retry_at !== null && $attempt->retry_at->isFuture() => 'waiting_search',
                    default => 'queued',
                };
                // zatrzymane przyciskiem nie zasłania opisu, który karta już ma z uzupełnienia
                if ($state === 'cancelled' && isset($out[$id])) {
                    continue;
                }
                $out[$id] = [
                    'state' => $state,
                    'hosts' => [],
                    'described_at' => null,
                    'stage' => $state === 'running' ? $attempt->stage : null,
                    'retry_at' => $state === 'waiting_search' ? $attempt->retry_at?->toIso8601String() : null,
                ];
            }
        }

        return $out;
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
                ->get(['id', 'product_id', 'source_key', 'purchase_price', 'currency', 'base_price_net', 'base_price_category', 'standard_discount_percent', 'checked_at']);
            foreach ($slots as $slot) {
                $out[(int) $slot->product_id][] = $slot;
            }
        }

        return $out;
    }

    /**
     * Etykieta źródła — wspólna z „taniej u …” na liście i w przetargu (SourcePriceComparison::sourceLabel).
     */
    private function sourcePriceLabel(ProductSourcePrice $slot): string
    {
        return $this->comparison->sourceLabel($slot);
    }

    /**
     * Etykieta konta B2B wspólna dla cen ze źródeł i danych ze sklepu dostawcy — ta sama nazwa konta ma się
     * pokazywać w obu miejscach karty.
     */
    private function b2bAccountLabel(?B2bAccount $account): string
    {
        return $this->comparison->accountLabel($account);
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
