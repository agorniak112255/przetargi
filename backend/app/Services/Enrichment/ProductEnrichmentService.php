<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Exceptions\EnrichmentCancelledException;
use App\Exceptions\ManufacturerPageMissingException;
use App\Exceptions\ProductSourcesNotFoundException;
use App\Jobs\ApplyModelDescriptionJob;
use App\Jobs\DescribeB2bProductFromDatasheetJob;
use App\Jobs\EnrichProductJob;
use App\Jobs\PrefetchProductSourcesJob;
use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\CatalogHostPriority;
use App\Models\CatalogSearchSite;
use App\Models\ManufacturerSite;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\ProductSourceDocument;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\B2b\B2bDescriptionSupplement;
use App\Services\B2b\B2bDocumentText;
use App\Services\B2b\B2bSupplementContext;
use App\Services\Presta\PrestaCategoryRewriteService;
use App\Services\PriceListCards;
use App\Services\ProductAccessorySyncService;
use App\Support\BhpAttributeNormalizer;
use App\Support\BlockedSourceHost;
use App\Support\CertificateLabels;
use App\Support\ColourWords;
use App\Support\EnrichmentDescriptionTemplates;
use App\Support\ManufacturerNormFacts;
use App\Support\NormCode;
use App\Support\PpeAssortment;
use App\Support\ProductCodeMatch;
use App\Support\ProductDescriptionText;
use App\Support\ProductNormsColumn;
use App\Support\ProductSizeVariant;
use App\Support\PromptEcho;
use App\Support\RequirementCheck\En388Code;
use App\Support\SafetyFeatures;
use App\Support\ShopEntryId;
use App\Support\Utf8Trim;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ProductEnrichmentService
{
    private const PACKAGING_MAX_LENGTH = 120;

    /** Słowa, które pasują do połowy katalogu BHP — same nie potwierdzają modelu. */
    /** Ile razy prosimy indeks o kolejną partię kart, zanim pójdziemy do wyszukiwarki. */
    private const CATALOG_EXTRA_ROUNDS = 3;

    /** Krótszy fragment z wyszukiwarki nie wystarczy na opis — nie traktujemy go jak karty. */
    private const SNIPPET_CARD_MIN_CHARS = 500;

    /** Tyle treści musi mieć karta producenta, żeby wejść przed karty sklepów. */
    private const MFR_CARD_MIN_CHARS = 400;

    /**
     * Ile plików PDF zostaje przy karcie. Przetarg pyta o kartę produktu, deklarację zgodności,
     * instrukcję, kartę gwarancyjną i tabelę rozmiarów — przy trzech część z nich nie mieściła się
     * w limicie. Limit steruje też budżetem czytania PDF-ów, więc nie podnosimy go wyżej.
     */
    private const MAX_PRODUCT_DOCUMENTS = 5;

    /** Ile ostatnich kroków przebiegu zostaje przy karcie, gdy wzbogacanie się udało. */
    private const TRACE_STEPS_ON_SUCCESS = 12;

    /**
     * Job wzbogacania trwa do ~7 min (timeout 420 s + slot). Po tym czasie produkt
     * wciąż w „running”, do którego nie ma joba, znaczy że proces padł.
     */
    private const STALE_RUNNING_AFTER_MINUTES = 15;

    /** Stany karty, które anulowanie partii przywraca (pozycja partii: previous_status) — kolejka i przebieg to nie stan. */
    private const RESTORABLE_STATUSES = [
        Product::ENRICHMENT_NONE,
        Product::ENRICHMENT_DONE,
        Product::ENRICHMENT_MANUAL,
        Product::ENRICHMENT_FAILED,
    ];

    /** Początek informacji o propozycji w komunikacie karty (keepAsProposal) — przy kolejnej propozycji zastępowana. */
    private const PROPOSAL_NOTE_PREFIX = 'Nowy opis czeka w „Do przeglądu”';

    /** Tyle roznych norm wyciagnietych z surowego tekstu strony to slowniczek sklepu, nie karta. */
    private const NORMS_GLOSSARY_THRESHOLD = 5;

    /** Na tylu pierwszych stronach cennika z pliku pytamy wyszukiwarkę (site:), gdy indeks nie ma karty z kodem. */
    private const MAX_SITE_HOSTS = 4;

    /**
     * Strony z opisami cennika z pliku, z którego karta ma slot ceny (PriceListCards::sourceSettingsFor) — na czas
     * jednego enrichProduct; null = karta bez takich ustawień i przebieg idzie jak dotąd. Worker kolejki trzyma serwis
     * między zadaniami, więc enrichProduct zeruje to w finally.
     */
    private ?PriceListSourceSettings $listSources = null;

    /**
     * Domeny producenta przypisane świadomie (assignedDomainsFor: konfiguracja, manufacturer_sites „manual”/„config”).
     * Host cennika spoza nich to sklep, nawet gdy wykrywanie uzna go po nazwie za domenę marki („portwest-sklep.pl”) —
     * także gdy zapisało go wcześniej jako „discovered”.
     *
     * @var list<string>
     */
    private array $listSourcesManufacturerDomains = [];

    /**
     * Strony źródła opisów odrzuconych przez handlowca w przeglądzie (DescriptionVersionStore::rejectedUrls) — na czas
     * jednego enrichProduct, zerowane w finally (worker trzyma serwis). Klucz = adres bez schematu, z hostem małymi
     * literami, bez ukośnika końcowego i bez parametrów śledzących; wartość = adres porównawczy bez zapytania.
     * Bez adresu wskazanego przez człowieka (trustedShopUrl) — ten wygrywa z odrzuceniem.
     *
     * @var array<string, string>
     */
    private array $rejectedSourceKeys = [];

    /** Komunikat o propozycji z ostatniego enrichProduct (opis nie wszedł na kartę, czeka w „Do przeglądu”); null = brak. */
    private ?string $lastProposalNote = null;

    /**
     * Decyzja z ostatniego publishDescription (pod blokadą karty); null = zapis porzucony przed decyzją.
     *
     * @var array{action: string, review_reason: string|null, reason: string}|null
     */
    private ?array $lastRunDecision = null;

    /**
     * Wersja opisu z ostatniego enrichProduct / applyModelDescription — zapisana (published) albo propozycja
     * (proposed), także z pamięci SKU; null = przebieg bez wersji (brak źródeł, wyjątek, opis odrzucony).
     * EnrichProductJob po przebiegu lidera modelu daje ją członkom (ApplyModelDescriptionJob) albo zarządza sztafetę.
     */
    private ?ProductDescriptionVersion $lastRunVersion = null;

    /**
     * Nota modelu dla lidera grupy w partii (ModelGroupPlanner::contextFor: klucz, rdzeń nazwy, karty modelu w partii)
     * — na czas jednego enrichProduct, zerowana w finally; null = karta bez grupy albo poniżej min_members profilu.
     *
     * @var array{key: string, stem: string, members: list<array{id: int, sku: string, name: string}>}|null
     */
    private ?array $modelContext = null;

    /**
     * Etap 3 (W3/W4): komunikat awarii wyszukiwarki z drugiej próby na hostach producenta (searchOnHosts) — wtedy brak
     * strony producenta nie jest pewny i przebieg kończy się w „failed”, nie w manufacturer_missing. Na czas jednego
     * enrichProduct, zerowany w finally.
     */
    private ?string $manufacturerRetryOutage = null;

    /** Tyle kart modelu wymienia nota modelu w poleceniu; reszta jako liczba (budżet polecenia). */
    private const MODEL_NOTE_MAX_MEMBERS = 30;

    private const GENERIC_NAME_TOKENS = [
        'rekawice', 'rękawice', 'rekawiczki', 'spodnie', 'kurtka', 'bluza', 'koszulka', 'kamizelka',
        'ubranie', 'odziez', 'odzież', 'buty', 'obuwie', 'trzewiki', 'polbuty', 'półbuty', 'sandaly',
        'robocze', 'robocza', 'roboczy', 'ochronne', 'ochronna', 'ochronny', 'ochrona', 'bezpieczne',
        'damskie', 'meskie', 'męskie', 'meska', 'męska', 'krotka', 'krótka', 'odblaskowa',
        'czarne', 'czarny', 'biale', 'białe', 'zolte', 'żółte',
        'granatowe', 'szare', 'zielone', 'niebieskie', 'pomaranczowe', 'pomarańczowe', 'czerwone',
        'rozmiar', 'komplet', 'zestaw', 'para', 'sztuka', 'sztuk', 'model', 'seria', 'linia', 'wersja',
        'guma', 'gumowe', 'skora', 'skóra', 'skorzane', 'skórzane', 'lateks', 'nitryl', 'bawelna',
        'bawełna', 'poliester', 'pary',
        // cennik Ansella pisze po angielsku: „GREY PES BLT & YKK BCKL LENGTH 150CM”
        'length', 'width', 'size', 'pair', 'pairs', 'pack', 'grey', 'gray', 'black', 'white',
        'blue', 'green', 'yellow', 'orange', 'red',
    ];

    public function __construct(
        private readonly HybridWebSearchService $search,
        private readonly ProductImageDownloader $images,
        private readonly ProductDocumentDownloader $documents,
        private readonly ProductPageFetcher $pages,
        private readonly ManufacturerDomainResolver $manufacturers,
        private readonly OpenAiCompatibleClient $llm,
        private readonly AiSettingsService $aiSettings,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly ProductSearchIdentity $identity,
        private readonly ProductImageCandidateVerifier $imageVerifier,
        private readonly PpeAssortment $assortment,
        private readonly ?EnrichmentAttemptLog $attemptLog = null,
        private readonly ?ProductAccessorySyncService $accessories = null,
        private readonly ?ManufacturerCatalogPdf $catalogPdf = null,
    ) {}

    private function attemptLog(): EnrichmentAttemptLog
    {
        return $this->attemptLog ?? app(EnrichmentAttemptLog::class);
    }

    private function liveProgress(): EnrichmentLiveProgress
    {
        return app(EnrichmentLiveProgress::class);
    }

    private function accessories(): ProductAccessorySyncService
    {
        return $this->accessories ?? app(ProductAccessorySyncService::class);
    }

    private function catalogPdf(): ManufacturerCatalogPdf
    {
        return $this->catalogPdf ?? app(ManufacturerCatalogPdf::class);
    }

    private function descriptionTemplates(): EnrichmentDescriptionTemplateService
    {
        return app(EnrichmentDescriptionTemplateService::class);
    }

    /*
     * Etap 1 opisów z cenników (08.10.2026): wersje opisu, werdykt tożsamości źródła, dowody i zapisane źródła — przez
     * kontener, bo testy budują serwis konstruktorem z jedenastoma argumentami.
     */
    private function versions(): DescriptionVersionStore
    {
        return app(DescriptionVersionStore::class);
    }

    private function sourceIdentity(): SourceIdentity
    {
        return app(SourceIdentity::class);
    }

    private function evidence(): EvidenceExtractor
    {
        return app(EvidenceExtractor::class);
    }

    private function sources(): SourceDocumentStore
    {
        return app(SourceDocumentStore::class);
    }

    private function profiles(): ManufacturerProfiles
    {
        return app(ManufacturerProfiles::class);
    }

    /*
     * Etap 2 opisów z cenników (opis wspólny dla modelu): plan partii po modelach, klucz modelu, fakty z cennika,
     * kopie plików lidera i dobór zdjęcia członka — także przez kontener (konstruktor bez zmian).
     */
    private function planner(): ModelGroupPlanner
    {
        return app(ModelGroupPlanner::class);
    }

    private function modelKeys(): ProductModelKey
    {
        return app(ProductModelKey::class);
    }

    private function cardFacts(): PriceListCardFacts
    {
        return app(PriceListCardFacts::class);
    }

    private function copier(): ProductWebFileCopier
    {
        return app(ProductWebFileCopier::class);
    }

    private function imagePicker(): ModelImagePicker
    {
        return app(ModelImagePicker::class);
    }

    /** Marka z opisem wspólnym dla modelu (profil `model.group`) — pamięć SKU nie jest dla niej czytana ani pisana. */
    private function sharesModelDescription(Product $product): bool
    {
        return $this->profiles()->for($product)?->modelGroup !== null;
    }

    public function enqueueProduct(Product $product, User $user, bool $force = false, bool $overwriteB2bDescription = false): ProductEnrichmentBatch
    {
        if (! $force && $product->enrichment_status === Product::ENRICHMENT_DONE) {
            throw new RuntimeException('Produkt ma już pobrane dane. Użyj force=true, aby pobrać ponownie.');
        }
        app(B2bDescriptionSource::class)->assertMayOverwrite($product, $overwriteB2bDescription);

        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCT,
            'scope_id' => $product->id,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED,
            'created_by' => $user->id,
            'force' => $force,
        ]);

        // pozycja partii zapamiętuje stan sprzed kolejki (restoreAfterCancel) — dlatego przed zmianą statusu
        $this->seedBatchItems($batch, [$product], $this->singleCardGroups($product));
        // Ślad (enrichment_trace) zostaje: to pochodzenie obecnego opisu, a anulowana partia nie daje nowego.
        // Nowy przebieg i tak go zastępuje (zapis opisu albo błędu).
        $product->update([
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
            'enrichment_error' => null,
        ]);

        PrefetchProductSourcesJob::dispatch($product->id, $batch->id, $force);

        return $batch;
    }

    /**
     * Plan partii z jedną kartą (enqueueProduct, enrichProductSync): grupa jednoelementowa z kluczem modelu karty
     * (marka z grupowaniem) albo bez klucza; lider = ta karta. Jedna karta modelu w partii to mniej niż min_members —
     * przebieg idzie jak dotąd, bez noty modelu.
     *
     * @return list<ModelGroup>
     */
    private function singleCardGroups(Product $product): array
    {
        $key = $this->modelKeys()->for($product);

        return [new ModelGroup($key?->key ?? '', $key?->stem ?? '', (int) $product->id, [(int) $product->id])];
    }

    /**
     * @return array{batch: ProductEnrichmentBatch, product_ids: list<int>}
     */
    public function enqueuePriceList(
        PriceList $priceList,
        User $user,
        bool $force = false,
        bool $dispatchJobs = true,
    ): array {
        // product_ids to karty ostatniego importu — karty z wcześniejszych aktualizacji (slot ceny z tego pliku) też
        $ids = app(PriceListCards::class)->ids($priceList);
        if ($ids === []) {
            throw new RuntimeException('Ten cennik nie ma zapisanych produktów do wzbogacenia (stary import?).');
        }

        return $this->enqueueProductIds(
            $ids,
            $user,
            $force,
            ProductEnrichmentBatch::SCOPE_PRICE_LIST,
            (int) $priceList->id,
            $dispatchJobs,
        );
    }

    /**
     * Karty z opisem z cennika B2B (B2bDescriptionSource) są pomijane zawsze, także z force — zbiorczo AI nie nadpisuje
     * opisu ze sklepu dostawcy (decyzja użytkownika 15.09.2026); skipped_b2b = ile takich kart pominięto.
     * $overwriteB2bDescription to to samo potwierdzenie, co przy pojedynczej karcie z panelu (enqueueProduct,
     * enrichProductSync): przepuszcza takie karty do kolejki. Używa go b2b:queue-table-descriptions dla kart,
     * w których opis ze sklepu jest w praktyce samą tabelką.
     *
     * Partia planowana po modelach (etap 2, ModelGroupPlanner): models = liczba grup modeli w partii (karta marki bez
     * grupowania = jedna grupa); zadanie prefetchu idzie tylko do lidera każdej grupy.
     *
     * @param  list<int>  $ids
     * @return array{batch: ProductEnrichmentBatch, product_ids: list<int>, skipped_b2b: int, models: int}
     */
    public function enqueueProductIds(
        array $ids,
        User $user,
        bool $force = false,
        string $scope = ProductEnrichmentBatch::SCOPE_PRODUCTS,
        int $scopeId = 0,
        bool $dispatchJobs = true,
        bool $overwriteB2bDescription = false,
    ): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw new RuntimeException('Brak produktów do wzbogacenia.');
        }

        $query = Product::query()->whereIn('id', $ids);
        if (! $force) {
            $query->whereNotIn('enrichment_status', [
                Product::ENRICHMENT_DONE,
                Product::ENRICHMENT_MANUAL,
            ]);
        }
        // zachowaj kolejność z $ids
        $eligible = $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $eligibleSet = array_fill_keys($eligible, true);
        $fromB2b = $overwriteB2bDescription ? [] : app(B2bDescriptionSource::class)->productIds($eligible);
        $productIds = [];
        $skippedB2b = 0;
        foreach ($ids as $id) {
            if (! isset($eligibleSet[$id])) {
                continue;
            }
            if (isset($fromB2b[$id])) {
                $skippedB2b++;

                continue;
            }
            $productIds[] = $id;
        }

        if ($productIds === []) {
            throw new RuntimeException($skippedB2b > 0
                ? 'Brak produktów do wzbogacenia — '.$skippedB2b.' kart ma opis z cennika B2B (ze sklepu dostawcy), którego AI nie nadpisuje. '
                    .'Pojedynczą kartę można nadpisać przyciskiem „Pobierz” po potwierdzeniu.'
                : 'Brak produktów do wzbogacenia (wszystkie już mają dane).');
        }

        $limit = $this->aiSettings->enrichmentBatchLimit();
        $requested = count($productIds);
        // Plan po modelach (etap 2): karty jednego modelu marki z grupowaniem idą razem, zadanie dostaje tylko lider;
        // karta marki bez grupowania to grupa jednoelementowa, więc kolejność i cięcie limitem są jak dotąd.
        // Z force karty „done” nie odpadają, więc każda partia brała te same pierwsze karty listy — przy limicie
        // najpierw modele z kartą bez opisu i z najstarszym opisem, a świeżo opisane trafiają na koniec i kolejna
        // partia idzie dalej. Limit tnie całymi modelami (pierwszy model większy niż limit wchodzi cały).
        $plan = $this->planner()->sliceByLimit(
            $this->planner()->groups($productIds),
            $limit,
            oldestFirst: $force && $requested > $limit,
        );
        $groups = $plan['groups'];
        $productIds = $plan['product_ids'];

        $queued = count($productIds);
        $models = count($groups);
        $message = ($requested > $limit
            ? "W kolejce: {$queued}/{$requested} (limit {$limit} — Ustawienia AI)"
            : 'W kolejce: '.$queued.' produktów')
            .($models < $queued ? ' ('.self::modelsLabel($models).')' : '')
            .($skippedB2b > 0 ? ' · pominięto '.$skippedB2b.' z opisem z cennika B2B' : '');

        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => $scope,
            'scope_id' => $scopeId > 0 ? $scopeId : ($user->id ?: 0),
            'total' => $queued,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED,
            'created_by' => $user->id,
            'force' => $force,
            'message' => $message,
        ]);

        // pozycje partii zapamiętują stan sprzed kolejki (restoreAfterCancel) — dlatego przed zmianą statusu;
        // ślad zostaje, jak w enqueueProduct; klucz modelu i lider zamrożone w pozycjach (contextFor/membersOf)
        $this->seedBatchItems(
            $batch,
            Product::query()->whereIn('id', $productIds)->get(['id', 'sku', 'name', 'enrichment_status', 'enrichment_error']),
            $groups,
        );
        Product::query()->whereIn('id', $productIds)->update([
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
            'enrichment_error' => null,
        ]);

        if ($dispatchJobs) {
            // zadanie dostaje tylko lider grupy — członkowie dostaną jego opis (ApplyModelDescriptionJob);
            // karta bez klucza jest własnym liderem
            foreach ($groups as $group) {
                PrefetchProductSourcesJob::dispatch($group->leaderId, $batch->id, $force);
            }
        }

        return [
            'batch' => $batch,
            'product_ids' => $productIds,
            'skipped_b2b' => $skippedB2b,
            'models' => $models,
        ];
    }

    /** „1 model”, „3 modele”, „12 modeli” — do komunikatu partii. */
    private static function modelsLabel(int $count): string
    {
        $last = $count % 10;
        $tens = $count % 100;
        $word = match (true) {
            $count === 1 => 'model',
            $last >= 2 && $last <= 4 && ($tens < 12 || $tens > 14) => 'modele',
            default => 'modeli',
        };

        return $count.' '.$word;
    }

    /**
     * Szukanie + pobranie HTML do cache. Bez LLM — EnrichProductJob korzysta z ciepłego cache.
     * $onSearchReady wołane zaraz po zapisie packa, żeby model nie czekał na HTML.
     */
    public function prefetchProductSources(Product $product, bool $force = false, ?int $batchId = null, ?callable $onSearchReady = null): void
    {
        $started = microtime(true);
        $this->assertBatchNotCancelled($batchId);

        // Marka z opisem wspólnym dla modelu (etap 2): pamięć SKU nie jest czytana — pochodzenie niesie wersja opisu,
        // a wpis pamięci SKU (inna karta tego kodu, dawny przebieg) nie zna modelu; przebieg idzie pełną ścieżką.
        if (! $force && ! $this->sharesModelDescription($product) && $this->hasSkuCacheRow($product)) {
            Log::info('Product source prefetch', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'skipped' => 'sku_cache',
                'total_ms' => $this->elapsedMs($started),
            ]);
            $onSearchReady !== null && $onSearchReady();

            return;
        }

        if ($batchId !== null) {
            $this->liveProgress()->bind($batchId, $product);
            $this->liveProgress()->step('Prefetch · wyszukiwarka');
        }
        $this->pages->bypassCache($force);
        try {
            if ($force) {
                $this->search->forgetProductCache($product);
            }

            $t = microtime(true);
            $searchPack = $this->search->searchBothPhases($product, true);
            // kroki wyszukiwania z prefetchu — bez nich przebieg produktu miał tylko 4 pozycje
            // i nie było widać, czego i gdzie szukano
            $searchPack['steps'] = $this->attemptLog()->snapshot($product)['steps'] ?? [];
            $this->rememberPrefetchPack($product, $searchPack);
            $onSearchReady !== null && $onSearchReady();
            $searchMs = $this->elapsedMs($t);
            $results = $searchPack['results'] ?? [];

            $fetchMs = 0;
            if ($results !== []) {
                if ($this->manufacturers->domainsFor($product) === []) {
                    $this->manufacturers->discoverOfficialDomains($product);
                }
                $mfrDomains = $this->manufacturers->discoverFromResults(
                    $product,
                    array_column($results, 'url')
                );
                $descResults = $this->rankResultsForDescription($results, $product, $mfrDomains);
                $t = microtime(true);
                $this->pages->fetch($descResults, (string) $product->sku, 3, [], $product);
                $mfrResults = $this->manufacturerSearchResults($results, $product, $mfrDomains);
                if ($mfrResults !== []) {
                    $this->pages->fetch($mfrResults, (string) $product->sku, 3, $mfrDomains, $product);
                }
                $fetchMs = $this->elapsedMs($t);
            }

            Log::info('Product source prefetch', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'search_ms' => $searchMs,
                'fetch_ms' => $fetchMs,
                'urls' => count($results),
                'total_ms' => $this->elapsedMs($started),
            ]);
        } finally {
            $this->pages->bypassCache(false);
            $this->liveProgress()->clear();
        }
    }

    /**
     * Wyniki z prefetchu — bez drugiego round-tripu do SearXNG/Tavily.
     *
     * @return array{results: list<array<string, mixed>>, errors?: list<string>, images?: list<string>}
     */
    private function searchPackForEnrichment(Product $product): array
    {
        $pack = $this->prefetchPack($product);
        if (is_array($pack)) {
            $this->replayPrefetchSteps($pack['steps'] ?? null);
            $pack['results'] = $this->search->dropListingResults(
                is_array($pack['results'] ?? null) ? $pack['results'] : [],
                $product
            );
        }
        if (! is_array($pack) || ($pack['results'] ?? []) === []) {
            $pack = $this->search->searchBothPhases($product);
            $pack['results'] = $this->search->dropListingResults(
                is_array($pack['results'] ?? null) ? $pack['results'] : [],
                $product
            );
        }
        if (($pack['results'] ?? []) === []) {
            $pack['results'] = $this->search->searchMappedRetailers($product);
            $pack['errors'] = $pack['errors'] ?? [];
        }
        $pack['results'] = $this->dropBlockedSourceHosts(
            is_array($pack['results'] ?? null) ? $pack['results'] : []
        );

        return $this->withHintedShopResult($product, $pack);
    }

    /**
     * Hosty wykluczone jako źródło (config `enrichment.blocked_source_hosts`) — własny sklep
     * i jego środowisko migracyjne. Host naszego sklepu z `prestashop.shop_url` dokładamy
     * zawsze, także gdy lista w konfiguracji go nie ma: eksport wysyła tam nasze opisy, więc
     * opis „ze sklepu” byłby naszym własnym tekstem. Ręcznie wskazany adres sklepu dopisujemy
     * później i on wykluczeniu nie podlega: to świadoma decyzja człowieka.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function dropBlockedSourceHosts(array $results, ?Product $product = null): array
    {
        $dropped = [];
        $out = [];
        foreach ($results as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($this->isBlockedSourceUrl($url) && ! ($product?->isTrustedShopUrl($url) ?? false)) {
                $dropped[] = ['url' => $url, 'reason' => CandidateRejection::BLOCKED_HOST];

                continue;
            }
            $out[] = $row;
        }
        if ($dropped !== []) {
            $this->attemptLog()->addRejections('host wykluczony', $dropped);
        }

        // Te same ścieżki kandydatów (wyniki wyszukiwania, indeks, zmapowane sklepy, otwarty internet, uzupełnienie,
        // strony cennika, zdjęcia z innych kart) pomijają strony odrzucone przez handlowca — jak host wykluczony.
        return $this->withoutRejectedSources($out, $product);
    }

    /**
     * Klucze stron odrzuconych przez handlowca dla tej karty (DescriptionVersionStore::rejectedUrls) bez adresu
     * wskazanego przez człowieka (trustedShopUrl) — ręczny adres wygrywa z odrzuceniem.
     *
     * @return array<string, string>
     */
    private function rejectedSourceKeysFor(Product $product): array
    {
        $keys = [];
        foreach ($this->versions()->rejectedUrls($product) as $url) {
            if ($product->isTrustedShopUrl($url)) {
                continue;
            }
            [$key, $path] = self::rejectedSourceKey($url);
            if ($key !== '') {
                $keys[$key] = $path;
            }
        }

        return $keys;
    }

    /**
     * Kandydaci na stronę opisu bez stron odrzuconych przez handlowca (po adresie i po adresie po przekierowaniach).
     * Ręcznie wskazany adres zostaje. Wpis w śladzie przebiegu — widać, że strona była, ale świadomie ją pominięto.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutRejectedSources(array $rows, ?Product $product = null): array
    {
        if ($this->rejectedSourceKeys === []) {
            return $rows;
        }
        $out = [];
        $dropped = [];
        foreach ($rows as $row) {
            $url = is_array($row) ? trim((string) ($row['url'] ?? '')) : '';
            $finalUrl = is_array($row) ? trim((string) ($row['final_url'] ?? '')) : '';
            $rejected = ($url !== '' && $this->isRejectedSourceUrl($url))
                || ($finalUrl !== '' && $this->isRejectedSourceUrl($finalUrl));
            if ($rejected && ! ($url !== '' && ($product?->isTrustedShopUrl($url) ?? false))) {
                $dropped[] = ['url' => $url !== '' ? $url : $finalUrl, 'reason' => CandidateRejection::REJECTED_IN_REVIEW];

                continue;
            }
            $out[] = $row;
        }
        if ($dropped !== []) {
            $this->attemptLog()->addRejections('pominięto stronę odrzuconą przez handlowca', $dropped);
        }

        return $out;
    }

    /**
     * Adres jest stroną odrzuconą przez handlowca: ten sam adres po normalizacji (bez schematu, ukośnika końcowego
     * i parametrów śledzących), albo ta sama ścieżka, gdy jedna ze stron nie ma zapytania („/karta” = „/karta?wariant=2”).
     * Dwa różne zapytania tej samej ścieżki to różne strony (sklepy z „index.php?product_id=…”). Wersja językowa tej
     * samej strony to inny adres — odrzucenie dotyczy dokładnie tej strony.
     */
    private function isRejectedSourceUrl(string $url): bool
    {
        [$key, $path] = self::rejectedSourceKey($url);
        if ($key === '') {
            return false;
        }
        foreach ($this->rejectedSourceKeys as $rejectedKey => $rejectedPath) {
            if ($key === $rejectedKey) {
                return true;
            }
            if ($path === $rejectedPath && ($key === $path || $rejectedKey === $rejectedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Klucz porównania adresu (DescriptionVersionStore::sourceUrlKey — host bez „www.” i „m.” — bez parametrów
     * śledzących) i sama ścieżka bez zapytania.
     *
     * @return array{0: string, 1: string}
     */
    private static function rejectedSourceKey(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['', ''];
        }
        $normalized = DescriptionVersionStore::sourceUrlKey($url);
        $at = strpos($normalized, '?');
        if ($at === false) {
            return [$normalized, $normalized];
        }
        $path = substr($normalized, 0, $at);
        $params = array_values(array_filter(
            explode('&', substr($normalized, $at + 1)),
            static fn (string $pair): bool => $pair !== ''
                && preg_match('/^(?:utm_[^=]*|gclid|gbraid|wbraid|fbclid|msclkid|srsltid|gad_source|_ga|mc_cid|mc_eid)(?:=|$)/i', $pair) !== 1
        ));

        return [$params === [] ? $path : $path.'?'.implode('&', $params), $path];
    }

    /**
     * Host (albo jego subdomena) z `enrichment.blocked_source_hosts` lub host naszego sklepu. Sprawdzane na wynikach
     * wyszukiwania i przed każdym kolejnym pobraniem kart (indeks, zmapowane sklepy, otwarty internet, uzupełnienie,
     * zdjęcia z innych kart) — te ścieżki omijają wyniki wyszukiwania, a outlet.pros.pl wracał nimi do puli.
     * Celowo nie w keepConfirmedCardPages: tam jest bramka tożsamości (miara EnrichmentTester51Test), a wykluczenie
     * hosta to decyzja o źródłach, nie o tożsamości strony.
     */
    private function isBlockedSourceUrl(string $url): bool
    {
        return BlockedSourceHost::matches($url);
    }

    /**
     * Ręcznie wskazany URL sklepu idzie pierwszy — nawet gdy SERP nic nie znalazł.
     *
     * @param  array{results?: list<array<string, mixed>>, errors?: list<string>}  $pack
     * @return array{results: list<array<string, mixed>>, errors?: list<string>}
     */
    private function withHintedShopResult(Product $product, array $pack): array
    {
        $hint = $product->hintedShopUrl();
        $results = is_array($pack['results'] ?? null) ? $pack['results'] : [];
        if ($hint === null) {
            $pack['results'] = $results;

            return $pack;
        }

        foreach ($results as $row) {
            if ($product->isHintedShopUrl((string) ($row['url'] ?? ''))) {
                $pack['results'] = $results;

                return $pack;
            }
        }

        array_unshift($results, [
            'url' => $hint,
            'title' => 'Wskazana karta sklepu',
            // Nazwa z cennika w snippecie to nie treść strony — przy linku z synchronizacji B2B, gdy strona nie
            // odpowie, taki snippet szedł do stron zapasowych jak tekst karty.
            'snippet' => $product->trustedShopUrl() !== null ? trim((string) $product->name.' '.(string) $product->sku) : '',
            'hinted' => true,
        ]);
        $pack['results'] = $results;

        return $pack;
    }

    /**
     * Domena, która oddała kartę, ma następnym razem iść w kolejce wcześniej, a adresy
     * odcięte przez filtry liczą się jako pudło. Zapytań site: jest ograniczona liczba,
     * więc o znalezieniu produktu decyduje to, które sklepy pytamy najpierw.
     *
     * @param  list<array{url?: string, text?: string}>  $pages
     * @param  array<string, mixed>  $fetched
     */
    private function recordHostOutcomes(array $pages, array $fetched): void
    {
        $ranking = app(CatalogHostRanking::class);
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '') {
                $ranking->recordHit($url);
            }
        }
        foreach ($fetched['rejected'] ?? [] as $row) {
            $url = is_array($row) ? (string) ($row['url'] ?? '') : '';
            if ($url !== '') {
                $ranking->recordMiss($url);
            }
        }
    }

    /**
     * Opis z podanych kart: filtr AI i opis od modelu (bez opisu zapasowego z treści karty).
     * Używane przy drugim podejściu, gdy pierwsze karty nie dały opisu.
     *
     * @param  list<array{url?: string, text?: string}>  $pages
     * @return array{description: string, extracted: array<string, mixed>, pages: list<array{url?: string, text?: string}>, cut: bool, list_sites: bool}
     */
    private function describeFromPages(Product $product, array $pages): array
    {
        // Etap 3 (W1): strona innego wyrobu marki albo ze sprzeczną cechą bezpieczeństwa nie wchodzi do drugiej puli.
        $pages = $this->withoutForeignOrConflictingPages($product, $pages);
        // Druga pula (indeks, sklepy, otwarty internet) bywa kartą producenta razem ze sklepami — ta sama reguła.
        // Strony cennika z pliku (listSources) działają tu tak samo jak w pierwszej puli.
        ['pages' => $pages, 'cut' => $cut, 'list_sites' => $listSites] = $this->manufacturerOnlyPages($product, $pages);
        if ($pages === []) {
            // „tylko producent i strony cennika” bez strony z cennika — nie ma czego dać modelowi
            return ['description' => '', 'extracted' => [], 'pages' => [], 'cut' => $cut, 'list_sites' => $listSites];
        }
        $clean = $this->rememberOptionSizes(
            $this->sanitizePagesWithLlm($product, $pages),
            $this->collectOptionSizes($pages, $this->sizeCategoryHint($product))
        );
        $cardSources = [];
        foreach (array_slice($clean, 0, 4) as $page) {
            $cardSources[] = [
                'url' => (string) ($page['url'] ?? ''),
                'title' => '',
                'snippet' => mb_substr((string) ($page['text'] ?? ''), 0, 200),
            ];
        }
        // druga pula tego samego przebiegu lidera — nota modelu i sito na rdzeniu nazwy jak w pierwszej
        $extracted = $this->extractWithLlm($product, $cardSources, array_slice($clean, 0, 5), modelContext: $this->modelContext);
        $description = $this->modelText($this->modelDescription($extracted));
        if (! $this->isUsableModelDescription($description, $product, array_column($clean, 'url'))
            || $this->descriptionContradictsCard($product, $description)) {
            $description = '';
        }

        return ['description' => $description, 'extracted' => $extracted, 'pages' => $clean, 'cut' => $cut, 'list_sites' => $listSites];
    }

    /**
     * Prefetch szukał w osobnym zadaniu i jego kroki przepadały — tu wracają do przebiegu,
     * żeby przy nieudanym produkcie było widać, czego szukano i co odpadło.
     */
    private function replayPrefetchSteps(mixed $steps): void
    {
        if (! is_array($steps)) {
            return;
        }
        $seen = [];
        $added = 0;
        foreach ($steps as $step) {
            if (! is_array($step) || ! is_string($step['m'] ?? null)) {
                continue;
            }
            // dwie fazy × dziewięć zapytań z tym samym „SearXNG: silniki zablokowane” zjadały
            // 26 z 40 kroków przebiegu — powtórki nic nie mówią, a zasłaniały część na żywo
            $key = (string) ($step['t'] ?? 'search').'|'.$step['m'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (++$added > EnrichmentAttemptLog::MAX_REPLAYED_STEPS) {
                break;
            }
            $this->attemptLog()->add(
                (string) ($step['t'] ?? 'search'),
                (string) $step['m'],
                urls: is_array($step['urls'] ?? null) ? $step['urls'] : [],
                why: is_array($step['why'] ?? null) ? $step['why'] : [],
                replayed: true,
            );
        }
    }

    /** @param  array<string, mixed>  $pack */
    private function rememberPrefetchPack(Product $product, array $pack): void
    {
        Cache::put($this->prefetchPackKey($product), $pack, now()->addHours(2));
    }

    /**
     * @return array{results: list<mixed>, errors?: list<string>}|null
     */
    private function prefetchPack(Product $product): ?array
    {
        $pack = Cache::get($this->prefetchPackKey($product));
        if (! is_array($pack) || ! array_key_exists('results', $pack) || ! is_array($pack['results'])) {
            return null;
        }

        return $pack;
    }

    private function prefetchPackKey(Product $product): string
    {
        return 'enrich_prefetch_pack:v3:'.$product->id;
    }

    /**
     * Synchroniczne pobranie (1 produkt) — omija stare workery kolejki. Karta z opisem z cennika B2B tylko po
     * potwierdzeniu ($overwriteB2bDescription).
     */
    public function enrichProductSync(Product $product, User $user, bool $force = false, bool $overwriteB2bDescription = false): ProductEnrichmentBatch
    {
        if (! $force && $product->enrichment_status === Product::ENRICHMENT_DONE) {
            throw new RuntimeException('Produkt ma już pobrane dane. Użyj force=true, aby pobrać ponownie.');
        }
        app(B2bDescriptionSource::class)->assertMayOverwrite($product, $overwriteB2bDescription);

        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => 'product',
            'scope_id' => $product->id,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'created_by' => $user->id,
            'force' => $force,
            'current_sku' => $product->sku,
            'current_name' => mb_substr($product->name, 0, 255),
            'message' => 'Pobieranie synchroniczne…',
        ]);

        Cache::forget($this->prefetchPackKey($product));
        $this->seedBatchItems($batch, [$product], $this->singleCardGroups($product));
        $this->recordBatchProduct($batch, $product, ProductEnrichmentBatchItem::STATUS_RUNNING);

        try {
            $this->enrichProduct($product, $force, $batch->id);
            // propozycja (opis czeka w „Do przeglądu”) to też koniec pracy — pozycja gotowa, z komunikatem o propozycji
            $this->markBatchItem($batch, true, $product, ProductEnrichmentBatchItem::STATUS_DONE, $this->lastProposalNote());
            $batch->refresh();
            $batch->update([
                'message' => 'Gotowe',
                'current_sku' => null,
                'current_name' => null,
            ]);
        } catch (Throwable $e) {
            // bez joba nikt inny nie przywróci karty z przebiegu (EnrichProductJob::abandonCancelled)
            if ($e instanceof EnrichmentCancelledException) {
                $this->restoreAfterCancel((int) $batch->id, (int) $product->id, 'Anulowano przez użytkownika');
            }
            $this->markBatchItem(
                $batch,
                false,
                $product,
                $e instanceof ProductSourcesNotFoundException
                    && ! $this->searchFailedDueToEngineOutage($e->getMessage())
                    ? ProductEnrichmentBatchItem::STATUS_MANUAL
                    : ProductEnrichmentBatchItem::STATUS_FAILED,
                mb_substr($e->getMessage(), 0, 500),
            );
            $batch->refresh();
            $batch->update([
                'message' => 'Błąd: '.mb_substr($e->getMessage(), 0, 200),
                'current_sku' => null,
                'current_name' => null,
            ]);
            throw $e;
        }

        return $batch->refresh();
    }

    public function enrichProduct(Product $product, bool $force = false, ?int $batchId = null): void
    {
        // worker trzyma serwis między zadaniami — komunikat o propozycji i wersja należą tylko do tego przebiegu
        $this->lastProposalNote = null;
        $this->lastRunVersion = null;
        if (! $force && $product->enrichment_status === Product::ENRICHMENT_DONE) {
            return;
        }

        $this->assertBatchNotCancelled($batchId);

        // stan karty sprzed przebiegu — propozycja (gorszy opis) przywraca z niego normy, pliki i status
        $before = $this->versions()->snapshot($product);
        $this->attemptLog()->reset();
        $this->attemptLog()->add('start', trim($product->sku.' · '.$product->name.' · '.$product->manufacturer));
        $product->update([
            'enrichment_status' => Product::ENRICHMENT_RUNNING,
            'enrichment_error' => null,
        ]);

        $this->pages->bypassCache($force);
        if ($batchId !== null) {
            $this->liveProgress()->bind($batchId, $product);
        }
        $started = microtime(true);
        $timing = [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'from_cache' => false,
            'search_ms' => 0,
            'fetch_ms' => 0,
            'llm_sanitize_ms' => 0,
            'llm_extract_ms' => 0,
            'supplement_ms' => 0,
            'images_ms' => 0,
            'docs_ms' => 0,
        ];
        // pliki karty sprzed przebiegu z force (webFileIds) — ustalane po potwierdzeniu karty, potrzebne też w catch
        $previousWebFiles = null;
        try {
            // strony przychodzą z kilku wywołań fetch — werdykt tożsamości i zapis źródeł biorą je z dziennika przebiegu
            // (w try: stopRunLog w finally zamyka dziennik także wtedy, gdy przebieg padnie zaraz na starcie)
            $this->pages->startRunLog();
            // Strony odrzucone przez handlowca nie wracają jako źródło opisu (przed bramkami, na każdej ścieżce kandydatów)
            $this->rejectedSourceKeys = $this->rejectedSourceKeysFor($product);
            $this->listSources = $this->priceListSourcesFor($product);
            // Opis wspólny dla modelu (etap 2): lider grupy w partii dostaje notę modelu w poleceniu i sito opisu na
            // rdzeniu nazwy; marka z grupowaniem nie czyta ani nie pisze pamięci SKU (pochodzenie niesie wersja).
            $this->modelContext = $batchId !== null && self::batchItemsHaveModelColumns()
                ? $this->planner()->contextFor($product, $batchId)
                : null;
            $sharedModel = $this->sharesModelDescription($product);
            if (! $force && $product->trustedShopUrl() === null && $this->hasSkuCacheRow($product)) {
                // Pamięć SKU niesie opis spoza stron cennika (inna karta tego kodu, wcześniejszy przebieg bez ustawień) —
                // przy stronach cennika przebieg idzie pełną ścieżką.
                if ($this->listSources !== null) {
                    $this->attemptLog()->add('search', 'strony cennika '.$this->listSources->manufacturer.' — pamięć SKU pominięta');
                } elseif ($sharedModel) {
                    $this->attemptLog()->add('search', 'pamięć SKU pominięta — opis wspólny modelu');
                }
            }
            if (! $force && ! $sharedModel && $this->listSources === null && $product->trustedShopUrl() === null && $this->applyFromSkuCache($product, $before)) {
                $this->logEnrichmentTiming($timing, $started, extra: ['from_cache' => true]);

                return;
            }
            if ($force) {
                Cache::forget($this->prefetchPackKey($product));
                $this->search->forgetProductCache($product);
                $this->forgetSkuCache($product);
                // zdjęcia i dokumenty kasujemy dopiero po potwierdzeniu nowej karty
            }

            $this->assertBatchNotCancelled($batchId);
            $this->liveProgress()->step('wyszukiwarka');
            $t = microtime(true);
            $searchPack = $this->searchPackForEnrichment($product);
            // Strony cennika z pliku dokładane tylko tutaj — nie w searchPackForEnrichment, z którego korzysta też
            // uzupełnianie opisów B2B (supplementWebPages), a klucz paczki prefetchu zostaje bez zmian.
            $listSearchErrors = [];
            if ($this->listSources !== null) {
                [$searchPack, $listSearchErrors] = $this->withPriceListSiteResults($product, $searchPack);
            }
            // adres z synchronizacji B2B (hintedShopUrl bez zaufania) dochodzi po filtrze hostów — odrzucony też odpada
            $searchResults = $this->withoutRejectedSources($searchPack['results'], $product);
            $searchEmptyDetail = ($searchPack['errors'] ?? []) !== []
                ? implode(' | ', array_slice($searchPack['errors'], 0, 2))
                : 'brak wyników';
            // „Tylko strony cennika”: awaria wyszukiwarki na tych stronach to nie „brak strony wyrobu” — przez
            // $searchEmptyDetail rozpoznaje ją engineOutageDetail i przebieg kończy w failed, nie w „wpisz ręcznie”.
            if ($listSearchErrors !== [] && $this->listSources?->onlyMode()) {
                $listDetail = implode(' | ', array_slice($listSearchErrors, 0, 2));
                $searchEmptyDetail = ($searchPack['errors'] ?? []) !== [] ? $searchEmptyDetail.' | '.$listDetail : $listDetail;
            }
            // Katalog PDF producenta dopasowany po numerze katalogowym — źródło równorzędne
            // karcie producenta. Marki bez kart HTML per wyrób (SECURA) opisuje wyłącznie on,
            // więc gdy niesie blok tego kodu, brak wyników wyszukiwarki nie kończy przebiegu.
            $catalogPages = $this->withoutRejectedSources($this->manufacturerCatalogPages($product), $product);
            // Etap 3 (W3): druga próba na hostach producenta z innymi zapisami kodu — null = jeszcze nie było
            $manufacturerRetry = null;
            // Tavily include_images WYŁĄCZONE — dawało piwo/LEGO/mapy zamiast produktu
            if ($searchResults === [] && $catalogPages === []) {
                $this->attemptLog()->add('search', $searchEmptyDetail);
                if ($this->listSources?->onlyMode()) {
                    throw new ProductSourcesNotFoundException($this->listSitesNotFoundMessage($product, $searchEmptyDetail));
                }
                $outage = $this->engineOutageDetail($searchEmptyDetail);
                // Marka „tylko od producenta”: zanim karta pójdzie do ręki, druga próba na jego hostach (inny zapis kodu);
                // nic → brak strony producenta (manufacturer_missing), nie zwykłe „wpisz ręcznie”.
                if ($outage === null && $this->identity->usesManufacturerSourcesOnly($product)) {
                    $manufacturerRetry = $this->retryManufacturerHosts($product);
                    if ($manufacturerRetry === []) {
                        throw $this->manufacturerPageMissing($product, $searchEmptyDetail);
                    }
                } else {
                    throw new ProductSourcesNotFoundException(
                        $outage !== null
                            ? 'Wyszukiwarka nie odpowiedziała przy produkcie '.$product->sku
                                .' — nie wiadomo, czy karta istnieje. Ponów po przywróceniu wyszukiwarki. '.$outage
                            : 'Nie znaleziono karty produktu '.$product->sku
                                .' — bez strony nie ma opisu ani zdjęcia. Opis wpisz ręcznie. '.$searchEmptyDetail
                    );
                }
            }
            $hintedUrl = $product->hintedShopUrl();
            if ($hintedUrl !== null) {
                $this->attemptLog()->add('search', 'wskazany URL sklepu', urls: [$hintedUrl]);
            }

            // Opis/zdjęcia ← sklepy; PDF/certyfikaty ← producent (osobne ścieżki).
            $mfrDomains = [];
            if ($searchResults !== []) {
                if ($this->manufacturers->domainsFor($product) === []) {
                    $this->manufacturers->discoverOfficialDomains($product);
                }
                $mfrDomains = $this->withoutListSitesAsManufacturer($product, $this->manufacturers->discoverFromResults(
                    $product,
                    array_column($searchResults, 'url')
                ));
            }
            $timing['search_ms'] = $this->elapsedMs($t);
            $descResults = $this->rankResultsForDescription($searchResults, $product, $mfrDomains);
            $this->liveProgress()->step('pobieranie kart');
            $t = microtime(true);
            // „Tylko strony cennika”: sklepy spoza listy i tak nie wejdą do puli — nie zajmują trzech miejsc pobrania,
            // a pusta pula otwiera kolejne partie indeksu (też tylko ze stron cennika).
            $fetched = $this->pages->fetch($this->dropOutsideListSources($descResults, $product), (string) $product->sku, 3, [], $product);
            $this->attemptLog()->add(
                'fetch',
                count($fetched['pages']).' stron HTML, '.count($fetched['image_urls']).' zdjęć z kart'
            );
            $pageSnippets = $this->keepConfirmedCardPages($product, $fetched['pages']);

            // Certyfikaty + fakty techniczne: osobny fetch producenta (nie mieszać rankingu opisu).
            $mfrPageSnippets = [];
            $mfrResults = $this->manufacturerSearchResults($searchResults, $product, $mfrDomains);
            if ($mfrResults !== []) {
                $mfrFetched = $this->pages->fetch($mfrResults, (string) $product->sku, 3, $mfrDomains, $product);
                foreach ($mfrFetched['document_urls'] as $url) {
                    $fetched['document_urls'][] = $url;
                }
                $this->mergeDocumentLabels($fetched, $mfrFetched);
                $mfrPageSnippets = $this->keepConfirmedCardPages($product, $mfrFetched['pages']);
            }
            $timing['fetch_ms'] = $this->elapsedMs($t);

            $pageSnippets = $this->orderPagesForDescription(
                $this->keepConfirmedCardPages(
                    $product,
                    $this->mergePageSnippets($pageSnippets, $mfrPageSnippets)
                ),
                $product,
                $mfrDomains
            );
            // Etap 3 (W1) już przed rundami szukania: pula ze samą stroną innego wyrobu marki (1011 R przy 1011) albo ze
            // sprzeczną cechą jest pusta i uruchamia dalsze rundy (indeks, zmapowane sklepy, otwarty internet) — W1 po
            // rundach zostawiał wtedy pustą pulę bez ponownego szukania. Wywołanie niżej zostaje (strony z rund).
            $pageSnippets = $this->withoutForeignOrConflictingPages($product, $pageSnippets);
            $openWebCardsUnreachable = false;
            $openWebWalledCards = [];
            $this->walledReaderDetails = [];
            $openWebTried = false;
            // strona producenta z drugiej próby (wyszukiwarka nic nie dała) — kolejne rundy szukania sklepów nic nie wniosą
            if ($pageSnippets === [] && ($manufacturerRetry ?? []) === []) {
                $triedUrls = array_values(array_filter(array_map(
                    static fn ($p): string => is_array($p) ? (string) ($p['url'] ?? '') : '',
                    $fetched['pages']
                )));
                // HyFlex 11-130 i Ringers R259B: zaporę spotkało już pierwsze pobranie,
                // a komunikat końcowy nie niósł adresu — człowiek nie wiedział, co otworzyć.
                $openWebWalledCards = $this->walledCardUrls($this->logCardRejections($product, $fetched));
                $this->attemptLog()->add(
                    'page',
                    'pobrane strony nie potwierdzają produktu — kolejne karty z indeksu, potem zmapowane sklepy',
                    urls: $triedUrls
                );
                [$pageSnippets, $fetched, $shopResults] = $this->fetchMoreCatalogCards(
                    $product,
                    $searchResults,
                    $fetched
                );
                if ($pageSnippets === []) {
                    [$pageSnippets, $fetched, $shopResults] = $this->fetchMappedRetailerCards(
                        $product,
                        array_values(array_merge($searchResults, $shopResults)),
                        $fetched
                    );
                }
                if ($pageSnippets === []) {
                    $openWebTried = true;
                    [$pageSnippets, $fetched, $webResults, $openWebCardsUnreachable, $webWalledCards] = $this->fetchCardsFromOpenWeb(
                        $product,
                        array_values(array_merge($searchResults, $shopResults)),
                        $fetched,
                        $mfrDomains
                    );
                    $shopResults = array_values(array_merge($shopResults, $webResults));
                    $openWebWalledCards = array_values(array_unique(array_merge($openWebWalledCards, $webWalledCards)));
                }
                if ($shopResults !== [] && $pageSnippets !== []) {
                    $searchResults = array_values(array_merge($searchResults, $shopResults));
                    $descResults = $this->rankResultsForDescription($searchResults, $product, $mfrDomains);
                }
            }
            // Blok z katalogu dokładamy dopiero tutaj, po rundach dobierania kart: wcześniej
            // sam blok czyniłby pulę niepustą i produkt zostawałby bez zdjęcia z karty sklepu.
            // Nie przechodzi przez keepConfirmedCardPages — jest przypięty do dokładnego kodu
            // wyrobu, więc potwierdza go mocniej niż heurystyka nazwy na stronie sklepu.
            // Tą samą drogą idzie karta PDF ze strony producenta: za kartą HTML, przed sklepami, ponownie po
            // filtrze stron (tabela rozmiarów przetrwa) i z własnym wpisem w źródłach.
            // Etap 3 (W1): strona innego wyrobu marki (kod dłuższy albo etykietowany kod producenta) albo ze sprzeczną
            // cechą bezpieczeństwa (kV, klasa, filtr, gaz) odpada — razem ze swoimi zdjęciami i plikami. Przed kartą PDF:
            // numer wpisu jej pliku porównujemy już tylko ze stronami, które zostały.
            $pageSnippets = $this->withoutForeignOrConflictingPages($product, $pageSnippets);
            // Etap 3 (W6): plik innego wyrobu marki albo wpisu sklepu innej strony nie jest kartą PDF tego wyrobu.
            $catalogPages = array_merge(
                $catalogPages,
                $this->withoutRejectedSources($this->withoutForeignOrConflictingPages($product, $this->manufacturerPdfCardPages(
                    $product,
                    $this->withoutForeignDocuments(
                        $product,
                        array_values(array_filter((array) ($fetched['document_urls'] ?? []), 'is_string')),
                        is_array($fetched['document_labels'] ?? null) ? $fetched['document_labels'] : [],
                        $pageSnippets,
                    )
                )), $product)
            );
            $pageSnippets = $this->withCatalogPages($pageSnippets, $catalogPages, $product, $mfrDomains);
            // Etap 3 (W3): marka „tylko od producenta” bez jego karty w puli — druga próba na hostach producenta z innymi
            // zapisami kodu; znaleziona strona idzie przed sklepy, a manufacturerOnlyPages zostawia wtedy tylko ją.
            if ($this->identity->usesManufacturerSourcesOnly($product) && ! $this->manufacturerCardInPool($product, $pageSnippets)) {
                $manufacturerRetry ??= $this->retryManufacturerHosts($product);
                if ($manufacturerRetry !== []) {
                    $pageSnippets = $this->mergePageSnippets($manufacturerRetry, $pageSnippets);
                    if ($mfrDomains === []) {
                        $mfrDomains = $this->manufacturers->domainsFor($product);
                    }
                }
            }
            if ($pageSnippets === []) {
                // Gdy po drodze padła wyszukiwarka, „brak karty” jest tylko
                // skutkiem awarii — produkt wraca do ponowienia, nie do ręki.
                // To samo, gdy wyszukiwarka karty znalazła, ale żadna nie odpowiedziała:
                // „sklep nie odpowiada” to celowo fraza z SearchEngineOutage — przez nią
                // przebieg kończy w `failed`, nie w „wpisz ręcznie”.
                $outage = $this->engineOutageDetail($searchEmptyDetail);
                $outageOrWall = $outage !== null || $openWebCardsUnreachable || $openWebWalledCards !== [];
                $message = match (true) {
                    $outage !== null => 'Wyszukiwarka nie odpowiedziała przy produkcie '.$product->sku
                        .' — nie wiadomo, czy karta istnieje. Ponów po przywróceniu wyszukiwarki. '.$outage,
                    $openWebCardsUnreachable => 'Znalezione karty produktu '.$product->sku
                        .' nie odpowiedziały — sklep nie odpowiada albo blokuje pobieranie,'
                        .' więc nie wiadomo, czy potwierdzają produkt. Ponów później.',
                    // Bez frazy „nie odpowiada”: to ma trafić do ręki, nie do kolejki —
                    // WAF, którego nie przeszedł reader, nie puści też za godzinę.
                    $openWebWalledCards !== [] => $this->walledCardsMessage($product, $openWebWalledCards),
                    (bool) $this->listSources?->onlyMode() => $this->listSitesNotFoundMessage($product, $searchEmptyDetail),
                    default => 'Nie znaleziono karty potwierdzającej produkt '.$product->sku
                        .' — bez strony nie ma opisu ani zdjęcia. Opis wpisz ręcznie.',
                };
                // Marka „tylko od producenta”: bez żadnej strony (i po drugiej próbie) to brak strony producenta —
                // powód przeglądu manufacturer_missing; awaria, zapora i niedostępne karty zostają jak wyżej.
                if (! $outageOrWall && $this->identity->usesManufacturerSourcesOnly($product)) {
                    throw $this->manufacturerPageMissing($product, $searchEmptyDetail);
                }
                throw new ProductSourcesNotFoundException($message);
            }

            // Stare zdjęcia i dokumenty ustępują dopiero zapisanemu nowemu opisowi (dropPreviousWebFiles). Kasowane tu,
            // przy samej potwierdzonej karcie, znikały także wtedy, gdy model potem nie dał opisu albo przebieg padł —
            // karta zostawała ze starym opisem bez zdjęć i PDF-ów (recenzja planu napraw cenników, 04.10.2026).
            $previousWebFiles = $force ? $this->webFileIds($product) : null;

            $this->assertBatchNotCancelled($batchId);

            // Marka „tylko producent” z jego kartą w puli — od tego miejsca sklepy nie wchodzą ani do rozmiarów,
            // ani do filtra stron, ani do opisu, ani do zdjęć (te biorą się z kart źródłowych opisu).
            ['pages' => $pageSnippets, 'cut' => $manufacturerOnly, 'listed' => $listedOnly, 'list_sites' => $listSitesPool, 'missing' => $manufacturerMissing] = $this->manufacturerOnlyPages($product, $pageSnippets);
            // Etap 3 (W2/W4): marka „tylko od producenta” bez jego karty (także po drugiej próbie) — sklepy odpadły, a bez
            // katalogu PDF i adresu wskazanego ręcznie nie ma z czego pisać opisu: model nie jest wołany.
            if ($pageSnippets === [] && $manufacturerMissing) {
                throw $this->manufacturerPageMissing($product, $searchEmptyDetail);
            }
            // „Tylko producent i strony cennika” bez strony z cennika, katalogu PDF i adresu zaufanego — sklepy spoza
            // listy nie wchodzą, więc nie ma z czego pisać opisu (model nie jest wołany).
            if ($pageSnippets === [] && $this->listSources?->onlyMode()) {
                throw new ProductSourcesNotFoundException($this->listSitesNotFoundMessage($product, $searchEmptyDetail));
            }

            // opcje zakupu (radio/select) — zanim LLM wytnie je z tekstu karty
            $optionSizes = $this->collectOptionSizes(
                $pageSnippets,
                $this->sizeCategoryHint($product)
            );

            // sklep → opis PL; producent → normy/materiały — jedno sanitize, żeby nie dublować vLLM
            $this->liveProgress()->step('filtr stron');
            $t = microtime(true);
            $pageSnippets = $this->rememberOptionSizes(
                $this->sanitizePagesWithLlm($product, $pageSnippets),
                $optionSizes
            );
            // Filtr stron czyści śmieci sklepowe; tekst katalogu ich nie ma, a filtr potrafi
            // odrzucić całą stronę — wracamy więc z blokiem w postaci wziętej z PDF.
            $pageSnippets = $this->withCatalogPages($pageSnippets, $catalogPages, $product, $mfrDomains);
            $timing['llm_sanitize_ms'] = $this->elapsedMs($t);

            $this->liveProgress()->step('opis produktu');
            $t = microtime(true);
            $cardSources = [];
            foreach (array_slice($pageSnippets, 0, 4) as $page) {
                $cardSources[] = [
                    'url' => (string) ($page['url'] ?? ''),
                    'title' => '',
                    'snippet' => mb_substr((string) ($page['text'] ?? ''), 0, 200),
                ];
            }
            $extracted = $this->extractWithLlm(
                $product,
                $cardSources,
                array_slice($pageSnippets, 0, 5),
                modelContext: $this->modelContext,
            );
            $extracted = $this->enrichStructuredFieldsFromPages($extracted, $pageSnippets);
            $timing['llm_extract_ms'] = $this->elapsedMs($t);

            $rawDescription = $this->composeFullDescription($extracted);
            // Opis wyłącznie od modelu — tekstu strony jako opisu zapasowego nie bierzemy
            // (audyt 22.09.2026: CAPTCHA, cenniki i banery cookies zapisane jako opis).
            $description = $this->modelText($this->modelDescription($extracted));
            if (! $this->isUsableModelDescription($description, $product, array_column($pageSnippets, 'url'))
                || $this->descriptionContradictsCard($product, $description)) {
                $description = '';
            }

            // niepełny opis / puste listy → doszukaj na kolejnych sklepach
            $needsSupplement = $description === '' || $this->looksLikeMissingCardMeta($description) || $this->looksLikeThinDescription($description)
                || $this->looksLikeIncompleteDescription($description)
                || $this->looksLikeSparsePayload($extracted);
            // Uzupełnienie dokłada wyłącznie sklepy — przy marce „tylko producent” brak danych zostaje brakiem.
            // Pusty albo cienki opis idzie dalej jak dotąd: druga próba bierze całą nową pulę kart (bez mieszania).
            if ($needsSupplement && $manufacturerOnly) {
                $this->attemptLog()->add('desc', 'tylko strony producenta — bez uzupełniania opisu ze sklepów');
            }
            if ($needsSupplement && ! $manufacturerOnly) {
                $this->liveProgress()->step('uzupełnienie');
                $t = microtime(true);
                $supplement = $this->supplementDescriptionFromOtherSites(
                    $product,
                    $descResults,
                    $pageSnippets,
                    $extracted,
                    $description,
                    $listedOnly
                );
                $description = $this->modelText($supplement['description']);
                if ($this->descriptionContradictsCard($product, $description)) {
                    $description = '';
                }
                $extracted = $this->enrichStructuredFieldsFromPages(
                    $supplement['extracted'],
                    $supplement['pages']
                );
                // Etap 3 (W1): strony uzupełnienia przez tę samą bramkę wariantu i cech co pierwsza pula
                $supplementedPages = $this->withoutForeignOrConflictingPages($product, $this->keepConfirmedCardPages($product, $supplement['pages']));
                if ($supplementedPages !== []) {
                    $pageSnippets = $this->rememberOptionSizes(
                        $supplementedPages,
                        $this->mergeOptionSizeLists(
                            $optionSizes,
                            $this->collectOptionSizes(
                                $supplementedPages,
                                $this->sizeCategoryHint($product)
                            )
                        )
                    );
                }
                foreach ($supplement['document_urls'] as $url) {
                    $fetched['document_urls'][] = $url;
                }
                $timing['supplement_ms'] = $this->elapsedMs($t);
            }

            // Potwierdza karta, nie to, że model przepisał nazwę z cennika.
            $confirmed = $pageSnippets !== []
                && $description !== ''
                && ! $this->looksLikeMissingCardMeta($description)
                && ! $this->looksLikeThinDescription($description);

            // Pusty opis z potwierdzonej karty kończył szukanie (Ringers 259/297) — zanim
            // oddamy produkt do ręki, bierzemy jeszcze jedną porcję kart z indeksu i sklepów.
            if (! $confirmed) {
                // Karty z indeksu, które pobrano i odrzucono, trzymamy osobno od
                // $searchResults — tam idą tylko wyniki, które coś dały. Bez tego
                // wyszukiwarka pobierałaby drugi raz adres, który indeks już podsunął.
                [$retryPages, $fetched, $triedResults] = $this->fetchMoreCatalogCards($product, $searchResults, $fetched);
                $retryResults = [];
                if ($retryPages === []) {
                    [$retryPages, $fetched, $retryResults] = $this->fetchMappedRetailerCards(
                        $product,
                        array_values(array_merge($searchResults, $triedResults)),
                        $fetched
                    );
                    $triedResults = array_values(array_merge($triedResults, $retryResults));
                }
                // Pas „GREY PES BLT … 150CM” kończył tu na matach z indeksu i nigdy nie
                // trafiał do wyszukiwarki — ta gałąź nie miała trzeciej drogi, którą
                // ma gałąź „strony nie potwierdzają produktu”. Gdy tamta gałąź już
                // internet pytała, nie pytamy drugi raz — wpis „brak nowych adresów”
                // sugerowałby, że internet nic nie miał.
                if ($retryPages === [] && ! $openWebTried) {
                    [$retryPages, $fetched, $retryResults] = $this->fetchCardsFromOpenWeb(
                        $product,
                        array_values(array_merge($searchResults, $triedResults)),
                        $fetched,
                        $mfrDomains
                    );
                }
                // Ślad także wtedy, gdy druga próba nic nie znalazła — bez tego nie widać,
                // czy w ogóle się odbyła (w próbce 45 produktów nie zostawiła ani jednego wpisu).
                $this->attemptLog()->add(
                    'desc',
                    $retryPages === []
                        ? 'pusty opis — brak kolejnych kart do sprawdzenia'
                        : 'pusty opis — próbuję na '.count($retryPages).' kolejnych kartach'
                );
                if ($retryPages !== []) {
                    $searchResults = array_values(array_merge($searchResults, $retryResults));
                    $retry = $this->describeFromPages($product, $retryPages);
                    if ($retry['description'] !== '') {
                        $pageSnippets = $retry['pages'];
                        // nowa pula zastępuje starą w całości — reguła „tylko producent” wg tej puli
                        $manufacturerOnly = $retry['cut'];
                        $listSitesPool = $retry['list_sites'];
                        $extracted = $this->enrichStructuredFieldsFromPages($retry['extracted'], $pageSnippets);
                        $description = $retry['description'];
                        $confirmed = ! $this->looksLikeMissingCardMeta($description)
                            && ! $this->looksLikeThinDescription($description);
                    }
                }
            }

            if (! $confirmed) {
                Log::warning('Product description rejected', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'pages' => array_slice(array_column($pageSnippets, 'url'), 0, 5),
                    'search_urls' => array_slice(array_column($descResults, 'url'), 0, 5),
                    'raw_length' => mb_strlen($rawDescription),
                    'raw_head' => mb_substr($rawDescription, 0, 300),
                    'mentions_product' => $rawDescription !== ''
                        && $this->descriptionMentionsProduct($rawDescription, $product),
                    'thin' => $rawDescription !== '' && $this->looksLikeThinDescription($rawDescription),
                    'card_meta' => $rawDescription !== '' && $this->looksLikeMissingCardMeta($rawDescription),
                    'final_head' => mb_substr($description, 0, 300),
                ]);

                $why = [];
                if ($pageSnippets === []) {
                    $why[] = 'brak potwierdzonej karty';
                }
                if ($description === '') {
                    $why[] = 'pusty opis';
                }
                if ($description !== '' && $this->looksLikeThinDescription($description)) {
                    $why[] = 'opis za krótki';
                }
                if ($description !== '' && $this->looksLikeMissingCardMeta($description)) {
                    $why[] = 'brak danych z karty';
                }
                $this->attemptLog()->add(
                    'desc',
                    $why !== [] ? implode('; ', $why) : 'karta nie potwierdziła produktu',
                    urls: array_column($pageSnippets, 'url')
                );
                $this->recordHostOutcomes([], $fetched);
                // Opis nie potwierdził produktu, więc zdjęcie jest ostatnią rzeczą,
                // jaką z karty bierzemy — i musi pochodzić z karty niosącej kod
                // produktu, a nie dopasowanej pospolitym słowem.
                $codedCards = $this->cardsCarryProductCode($pageSnippets, $product);
                $savedImages = $codedCards
                    ? $this->downloadImagesFromFetchedCards($product, $fetched, $pageSnippets)
                    : [];
                if (! $codedCards) {
                    $this->attemptLog()->add(
                        'image',
                        'bez zdjęcia — żadna karta nie niesie kodu produktu w adresie'
                    );
                }
                if ($savedImages !== []) {
                    $this->attemptLog()->add('image', 'zdjęcie z karty mimo cienkiego opisu');
                    // nowe zdjęcie z karty niosącej kod zastępuje stare; dokumenty zostają razem ze starym opisem
                    $this->dropPreviousWebFiles($product, $previousWebFiles, $savedImages, null);
                    $previousWebFiles = null;
                    // Zdjęcie nie zastępuje opisu: karta bez opisu dostaje ten sam status, co
                    // przebieg bez zdjęcia (ProductSourcesNotFoundException → „manual”). Status
                    // „done” pokazywał się w panelu jako „OK”, a karta nie wracała do kolejki.
                    $product->update([
                        'enrichment_status' => Product::ENRICHMENT_MANUAL,
                        'enriched_at' => now(),
                        'enrichment_error' => 'Zdjęcie z karty sklepu. Opis wpisz ręcznie.',
                        'enrichment_trace' => $this->attemptLog()->snapshot($product),
                    ]);
                    $this->logEnrichmentTiming($timing, $started);

                    return;
                }
                throw new ProductSourcesNotFoundException(
                    $searchResults === []
                        ? 'Nie znaleziono stron z tym SKU w internecie. '.$searchEmptyDetail
                            .' Model nie potwierdził produktu — opis wpisz ręcznie.'
                        : 'Nie znaleziono karty potwierdzającej produkt '.$product->sku
                            .' — żaden opis nie wymieniał jego kodu ani modelu. Opis wpisz ręcznie.'
                );
            }

            $this->recordHostOutcomes($pageSnippets, $fetched);

            $sourceUrls = [];
            foreach ($extracted['source_urls'] ?? [] as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $sourceUrls[] = $this->identity->preferredLocaleUrl($url, $product);
                }
            }
            if ($sourceUrls === []) {
                $sourceUrls = array_column(array_slice($pageSnippets, 0, 3), 'url');
            }
            $sourceUrls = array_values(array_filter(
                $sourceUrls,
                fn (string $url): bool => $this->sourceUrlIsConfirmedCard($url, $pageSnippets, $product)
                    // model wymienia też adresy, których nie czytał — strona odrzucona przez handlowca nie wraca jako źródło
                    && ($this->rejectedSourceKeys === [] || $product->isTrustedShopUrl($url) || ! $this->isRejectedSourceUrl($url))
            ));
            // Model wymienia w source_urls także adresy z wyników wyszukiwania, których nie czytał — przy marce
            // „tylko producent” źródłem jest wyłącznie strona z puli (inaczej sklep wracał jako źródło i dawca zdjęć).
            // Tak samo pula zawężona do stron cennika z pliku.
            if ($manufacturerOnly || $listSitesPool) {
                $poolUrls = [];
                foreach ($pageSnippets as $page) {
                    $url = (string) ($page['url'] ?? '');
                    $poolUrls[mb_strtolower($url)] = true;
                    $poolUrls[mb_strtolower($this->identity->preferredLocaleUrl($url, $product))] = true;
                }
                $sourceUrls = array_values(array_filter(
                    $sourceUrls,
                    static fn (string $url): bool => isset($poolUrls[mb_strtolower($url)])
                ));
            }
            if ($sourceUrls === []) {
                $sourceUrls = array_column(array_slice($pageSnippets, 0, 3), 'url');
            }
            // Katalog producenta ma być widoczny jako źródło: z niego wziął się opis, a człowiek
            // musi mieć czym to sprawdzić. Dopisujemy go tylko wtedy, gdy blok został w puli stron.
            foreach ($this->catalogUrlsAmongPages($pageSnippets, $catalogPages) as $url) {
                $sourceUrls[] = $url;
            }

            // Które z tych źródeł jest docelowe. Testujący zgłosił, że opisy Artry brały się z kart
            // konkurencji (regera, Empik), a z samej listy adresów nie dało się tego zobaczyć.
            // Kolejności listy nie ruszamy — z niej wybierane są zdjęcia — zapisujemy samo rozstrzygnięcie.
            [$primarySourceUrl, $primarySourceKind] = $this->primarySource($sourceUrls, $product, $mfrDomains);
            // Werdykt tożsamości strony głównego źródła (sygnał dla przeglądu, nie bramka): strona z dziennika przebiegu
            // (tekst surowy, tytuł, mikrodane), zapasowo z puli opisu — karta PDF producenta i blok katalogu nie idą
            // przez fetch.
            $runPages = $this->pages->runLog();
            $identity = $this->sourceIdentity()->judgeCard(
                $product,
                [...$runPages, ...$pageSnippets],
                $primarySourceUrl,
                $primarySourceKind,
                $this->profiles()->for($product)?->catalogs ?? [],
            );

            // Zdjęcie z tej samej karty co opis — nie z innej pobranej strony.
            $this->liveProgress()->step('weryfikacja zdjęć');
            $t = microtime(true);
            $descPages = $this->pagesForDescriptionImages($pageSnippets, $sourceUrls);
            $fromCards = $this->imagesFromDescriptionPages($descPages);
            foreach ($extracted['image_urls'] ?? [] as $url) {
                if (! is_string($url) || ! str_starts_with($url, 'http')) {
                    continue;
                }
                if ($this->imageUrlMatchesDescriptionPages($url, $descPages)
                    || $this->identity->imageUrlMentionsProduct($url, $product)) {
                    $fromCards['all'][] = $url;
                }
            }
            if ($fromCards['all'] === []) {
                $fromCards = [
                    'all' => $this->imagesOnDescriptionHosts($fetched['image_urls'], $descPages),
                    'trusted' => $this->imagesOnDescriptionHosts($fetched['trusted_image_urls'], $descPages),
                ];
            }
            $imageUrls = $this->cardImagesAfterConfirmation(
                $fromCards['trusted'],
                $fromCards['all'],
                $descPages,
                $product
            );
            // Etap 2b: marka z opisem wspólnym dla modelu i karta z kolorem — zdjęcie lidera dobierane jak u członków:
            // picker na całej galerii stron opisu (zaufane pierwsze — ta sama lista, co page_image_urls wersji), PRZED
            // weryfikatorem (pilotaż #499: weryfikator zostawiał tylko og:image w innym kolorze, a reguła koloru je cięła —
            // lider bez zdjęcia, gdy członkowie dostawali plik w swoim kolorze). Adres w kolorze karty idzie jako pierwszy
            // kandydat bez weryfikatora; galeria tylko w innych kolorach — bez nowego zdjęcia i bez szukania na kartach
            // sklepów (cudzy sklep nie da zdjęcia w kolorze karty); galeria bez kolorów w nazwach — ścieżka jak dotąd.
            $cardColours = $sharedModel ? $this->cardColours($product) : [];
            $modelPickUrl = null;
            $modelOtherColour = false;
            if ($cardColours !== []) {
                $pick = $this->imagePicker()->pickFor($product, $cardColours, $this->galleryFromCards($fromCards), [], $this->modelKeys()->for($product)?->stem);
                if ($pick['url'] !== null) {
                    $modelPickUrl = $pick['url'];
                    $this->attemptLog()->add('image', $pick['reason'].' — pierwszy kandydat', urls: [$pick['url']]);
                } elseif ($pick['reason'] === ModelImagePicker::REASON_PAGE_OTHER_COLOUR) {
                    $modelOtherColour = true;
                    $this->attemptLog()->add('image', 'zdjęcia strony w innym kolorze — bez nowego');
                }
            }
            // Zdjęcie bez kodu/modelu w adresie nie jest niczym potwierdzone — „og:image”
            // sklepu bywa logo, budynkiem firmy albo zupełnie innym wyrobem (kurtka przy
            // płatku zaworu). Takie kandydatury ogląda model; przechodzą bez oglądania
            // tylko pliki, których adres sam nazywa produkt.
            // Etap 3 (W5): zdjęcie z kodem innej karty marki w nazwie pliku („…-model-1011-r.jpg” przy 1011) albo ze
            // sprzeczną cechą bezpieczeństwa („…-20kv.jpg” przy 30 kV) odpada przed potwierdzaniem i oglądaniem.
            // Zdjęcie z pickera lidera modelu (etap 2b) przechodzi przez tę samą bramkę — idzie potem bez weryfikatora.
            $allowedImages = array_flip($this->withoutForeignImages(
                $product,
                array_values(array_unique(array_filter(
                    [$modelPickUrl, ...$imageUrls, ...$fromCards['all'], ...$fromCards['trusted']],
                    static fn (mixed $url): bool => is_string($url) && $url !== ''
                )))
            ));
            if ($modelPickUrl !== null && ! isset($allowedImages[$modelPickUrl])) {
                $modelPickUrl = null;
            }
            $keepImages = static fn (array $urls): array => array_values(array_filter(
                $urls,
                static fn (mixed $url): bool => is_string($url) && isset($allowedImages[$url])
            ));
            $imageUrls = $keepImages($imageUrls);
            $verifierPool = $keepImages($fromCards['all']);
            $proven = $this->provenProductImages($imageUrls, $product);
            if ($proven !== []) {
                $imageUrls = $proven;
            } else {
                $imageUrls = $this->imageVerifier->select(
                    $product,
                    $verifierPool !== [] ? $verifierPool : $imageUrls,
                    $descPages,
                    3,
                    $keepImages($fromCards['trusted'])
                );
            }
            $imageUrls = array_values(array_unique($imageUrls));
            if ($modelPickUrl !== null) {
                // adres w kolorze karty bez weryfikatora — jak u członków modelu
                $imageUrls = array_values(array_unique([$modelPickUrl, ...$imageUrls]));
            }

            // Wartości, które ten przebieg zapisał na karcie przed decyzją o wersji — propozycja cofa tylko je
            // (i tylko gdy nikt ich w międzyczasie nie zmienił: synchronizacja B2B, handlowiec).
            $runWrites = [];
            if ($this->storeManufacturerPageNorms($product, $pageSnippets, $primarySourceUrl, $primarySourceKind)) {
                $runWrites['manufacturer_norms'] = $product->manufacturer_norms;
            }
            $description = $this->alignEn388WithManufacturer($description, $product);

            $extracted = $this->enrichStructuredFieldsFromPages($extracted, $pageSnippets, $description);
            $extracted = $this->withoutMissingDataListItems($extracted);
            // Źródło prawdy to pobrana strona: filtr stron (model) przepisuje tekst i bywa, że gubi zdanie z normą,
            // którą strona podaje — obok tekstu po filtrze idzie surowy tekst tych samych stron
            $descriptionUrls = array_fill_keys(array_map('mb_strtolower', array_column($pageSnippets, 'url')), true);
            $rawDescriptionPages = array_values(array_filter(
                $fetched['pages'] ?? [],
                static fn ($page): bool => is_array($page) && isset($descriptionUrls[mb_strtolower((string) ($page['url'] ?? ''))])
            ));
            // Etap 3 (W7): zdania powtarzające polecenie i zdania ze sprzecznym oznaczeniem normy wypadają przed sitem norm
            $textCheck = $this->withoutPromptEchoAndNormProblems($description);
            $description = $textCheck['description'];
            $claimCheck = $this->withoutUnsupportedNormClaims($product, $description, $extracted, [...$pageSnippets, ...$rawDescriptionPages]);
            $description = $claimCheck['description'];
            // Etap 3 (W7): pole norm bez poziomów cudzych norm, kategorii i pozycji bez oznaczenia normy; echa polecenia z list
            $listCheck = $this->withSaneNormLists($claimCheck['extracted']);
            $extracted = $listCheck['extracted'];
            $claimCheck['dropped_norm_claims'] = array_values(array_unique([
                ...$textCheck['dropped_norm_claims'],
                ...$claimCheck['dropped_norm_claims'],
                ...$listCheck['dropped_norm_claims'],
            ]));
            $droppedMetaSentences = array_values(array_unique([...$textCheck['dropped_meta_sentences'], ...$listCheck['dropped_meta_sentences']]));
            $fields = $this->payloadFromExtraction($product, $extracted, $description, $pageSnippets);
            $packaging = $fields['packaging'];
            // Strony opisu z tekstem surowym (dziennik przebiegu) i po filtrze — do dowodów i do zapisu źródeł
            $sourcePages = $this->descriptionSourcePages($product, $pageSnippets, $sourceUrls, $runPages);
            $evidence = $this->evidence()->extract($fields['lists'], $this->evidenceDocs($product, $sourcePages));

            $payload = [
                ...$fields['lists'],
                'source_urls' => array_values(array_unique($sourceUrls)),
                'primary_source_url' => $primarySourceUrl,
                'primary_source_kind' => $primarySourceKind,
                'confidence' => (float) ($extracted['confidence'] ?? 0),
                'from_cache' => false,
            ];
            // Z jakimi stronami cennika powstał opis — po zmianie stron w Cenniki → „Z pliku” widać, które opisy są
            // sprzed zmiany. Tylko na karcie: pamięć SKU (storeSkuCache) dostaje $payload bez tego klucza.
            $priceListSources = $this->listSources !== null ? [
                'price_list_id' => $this->listSources->priceListId,
                'mode' => $this->listSources->mode,
                'hosts_sha1' => $this->listSources->hostsSha1,
            ] : null;

            // Kolumna norm idzie za nowym opisem, także gdy nowa lista jest pusta. Zapis „tylko gdy puste” zostawiał
            // normy z poprzedniego (złego) pobrania na zawsze — AJ GROUP 906, 304/K, 604/K. Poza wzbogacaniem kolumnę
            // piszą tylko opis B2B (karta z nim nie przechodzi tej ścieżki bez potwierdzenia nadpisania) i Presta
            // (nasz własny dawny tekst, wykluczony jako źródło) — w obu przypadkach nowy opis i tak je zastępuje.
            // Marka ścisła: opis ze sklepu nie jest bazą dla opisu ze strony producenta — cofany dopiero w transakcji
            // zapisu (publishDescription); tu tylko wpis w śladzie (ślad karty jest zamykany przed zapisem)
            $shopBase = $this->shopBaseYieldsTo($product, $primarySourceUrl) ? $this->shopDescriptionSource($product) : null;
            if ($shopBase !== null) {
                $this->attemptLog()->add('desc', 'obecny opis ze sklepu nie jest bazą — zastąpi go opis ze strony producenta (wersja sklepu do historii)', urls: [$shopBase]);
            }
            if ($this->writeNormsColumn($product, $payload['norms'])) {
                $runWrites['norms'] = $product->norms;
            }

            $primaryImageUrls = $this->pickPrimaryImageUrls(
                $imageUrls,
                [],
                (string) $product->sku,
                (string) $product->name,
                $product,
            );
            if ($modelPickUrl !== null) {
                // adres w kolorze karty pierwszy bez względu na punktację (jak u członków)
                $primaryImageUrls = array_values(array_unique([$modelPickUrl, ...$primaryImageUrls]));
            } elseif ($modelOtherColour) {
                $primaryImageUrls = [];
            }
            Log::info('Product image candidates prepared', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'fetched_count' => count($fetched['image_urls']),
                'verified_count' => count($imageUrls),
                'selected_count' => count($primaryImageUrls),
                'selected_urls' => $primaryImageUrls,
                'source_urls' => array_slice($sourceUrls, 0, 3),
            ]);
            $savedImages = $this->images->downloadMany($product, $primaryImageUrls, 1);
            // zdjęcie z innej karty (tryImagesFromOtherCards) nie pochodzi z żadnej strony opisu — rola „image” w źródłach
            $imagesFromOtherCards = false;
            $imageFailure = $this->imageFailureSummary($primaryImageUrls);
            // galeria strony lidera tylko w innych kolorach: zamiennik z innej karty tego modelu w partii (pliki z dysku)
            if ($savedImages === [] && $modelOtherColour) {
                $siblingCopy = $this->siblingImageCopy($product, $cardColours, $batchId, $this->modelKeys()->for($product)?->stem);
                if ($siblingCopy !== null) {
                    $savedImages = [$siblingCopy];
                    $imagesFromOtherCards = true;
                }
            }
            if ($imageFailure === '' && $modelOtherColour && $savedImages === []) {
                $imageFailure = 'zdjęcia strony w innym kolorze';
            }
            // przed tryImagesFromOtherCards — kolejne downloadMany czyści listę
            $imageRetryUrls = $this->images->lastRetryLaterUrls();
            // galeria strony tylko w innych kolorach: karty sklepów nie dadzą zdjęcia w kolorze karty — bez szukania
            if ($savedImages === [] && ! $modelOtherColour) {
                // Świadomie także przy marce „tylko producent”: zdjęcie z potwierdzonej karty sklepu (bez outletu —
                // blocked_source_hosts) jest lepsze niż karta bez zdjęcia; opis i listy zostają z producenta.
                if ($manufacturerOnly) {
                    $this->attemptLog()->add('image', 'zdjęcie producenta nie pobrało się — szukam na kartach sklepów');
                }
                $imagesFromOtherCards = true;
                $savedImages = $this->tryImagesFromOtherCards(
                    $product,
                    $searchResults,
                    $sourceUrls,
                    $mfrDomains,
                    sourceRefused: $imageRetryUrls !== [],
                );
            }
            if ($savedImages === []) {
                Log::warning('Product image download exhausted', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'selected_urls' => $primaryImageUrls,
                    'search_urls' => array_slice(array_column($searchResults, 'url'), 0, 8),
                    'manufacturer_urls' => array_slice(array_column($mfrResults, 'url'), 0, 5),
                ]);
            }
            $timing['images_ms'] = $this->elapsedMs($t);
            $t = microtime(true);
            // Pliki pochodzą z tych samych stron co opis i zdjęcia ($descPages) — pierwsze, żeby limit plików nie wypchnął
            // arkusza karty. Z pozostałych pobranych stron (także „potwierdzonych” słabo: lista arkuszy producenta, karta
            // siostrzanego wyrobu) tylko plik z kodem wyrobu w adresie albo opisie linku (27.09.2026: CDR0400 dostał pięć
            // arkuszy innych mat Coba i stracił własny).
            $documentUrls = [];
            $descriptionPageDocs = [];
            foreach ($descPages as $page) {
                foreach ($page['document_urls'] ?? [] as $url) {
                    if (is_string($url) && $url !== '') {
                        $documentUrls[] = $url;
                        $descriptionPageDocs[$url] = true;
                    }
                }
            }
            $fetchedLabels = is_array($fetched['document_labels'] ?? null) ? $fetched['document_labels'] : [];
            // etap 3 (W6c): numer wpisu sklepu w adresie („pdf.php?id_product=103”, „/102-plaszcz-model-1102”) to nie kod
            $namesProduct = fn (string $url): bool => isset($descriptionPageDocs[$url]) || $this->identity->hayHasProductCode(
                mb_strtolower(urldecode(ShopEntryId::strip($url)).' '.($fetchedLabels[$url] ?? '')),
                $product,
            );
            foreach ($extracted['document_urls'] ?? [] as $url) {
                if (is_string($url) && ProductDocumentDownloader::looksLikeDocumentUrl($url) && $namesProduct($url)) {
                    $documentUrls[] = $url;
                }
            }
            foreach ($fetched['document_urls'] ?? [] as $url) {
                if (is_string($url) && $namesProduct($url)) {
                    $documentUrls[] = $url;
                }
            }
            foreach ($searchResults as $row) {
                $u = (string) ($row['url'] ?? '');
                // PDF wprost z wyszukiwarki — tylko z kodem wyrobu w adresie, tytule albo opisie (27.09.2026: bez strony
                // nic go nie wiąże z wyrobem, a trafiały arkusze sąsiednich wyrobów producenta)
                $bound = $this->identity->hayHasProductCode(
                    mb_strtolower(urldecode(ShopEntryId::strip($u)).' '.($row['title'] ?? '').' '.($row['snippet'] ?? '')),
                    $product,
                );
                if ($bound && ProductDocumentDownloader::looksLikeDocumentUrl($u)) {
                    $documentUrls[] = $u;
                }
            }
            // Etap 3 (W6a/b/d): plik innego wyrobu marki, plik wpisu sklepu innej strony i — przy marce „tylko od
            // producenta” — plik spoza stron opisu i hostów producenta odpadają, także ze strony opisu.
            $documentUrls = $this->withoutForeignDocuments(
                $product,
                array_values(array_unique(array_filter($documentUrls, 'is_string'))),
                $fetchedLabels,
                $descPages,
                $manufacturerOnly,
                $descriptionPageDocs,
            );
            // Katalog marki nie jest dokumentem wyrobu — opisuje setki wyrobów naraz.
            // Jako źródło opisu owszem, w „Plikach PDF” produktu byłby mylącym załącznikiem.
            $documentUrls = array_values(array_filter(
                $documentUrls,
                fn ($u): bool => is_string($u) && ! $this->catalogPdf()->isConfiguredCatalogUrl($u)
            ));
            $this->assertBatchNotCancelled($batchId);

            $mfrDomains = $this->withoutListSitesAsManufacturer($product, $this->manufacturers->discoverFromResults($product, array_merge(
                $documentUrls,
                array_column($searchResults, 'url'),
            )));
            $preferredDocs = $this->preferManufacturerDocuments($documentUrls, $product, $mfrDomains);
            $documentLabels = is_array($fetched['document_labels'] ?? null) ? $fetched['document_labels'] : [];
            $savedDocs = $this->documents->downloadMany(
                $product,
                $preferredDocs,
                self::MAX_PRODUCT_DOCUMENTS,
                $documentLabels,
            );
            // Imperva na domenie producenta → PDF z CDN/dystrybutora (SKU w URL)
            if ($savedDocs === []) {
                $fallbackDocs = array_values(array_unique(array_filter(
                    $documentUrls,
                    static fn ($u): bool => is_string($u) && ProductDocumentDownloader::looksLikePdfUrl($u)
                )));
                if ($fallbackDocs !== $preferredDocs) {
                    $savedDocs = $this->documents->downloadMany(
                        $product,
                        $fallbackDocs,
                        self::MAX_PRODUCT_DOCUMENTS,
                        $documentLabels,
                    );
                }
            }
            // Etykieta pliku tylko z tego, co mówi jego nazwa i adres: deklaracja to nie certyfikat, a nierozpoznany
            // plik (deklaracja opakowania PPWR, certyfikat wykonawcy) zostaje w plikach karty bez wpisu na liście.
            // Dawne „Certyfikat producenta” przy każdym pliku — 456 kart Ansella, 04.10.2026.
            $payload['certificates'] = CertificateLabels::relabel(
                $this->stringList($payload['certificates'] ?? null),
                $savedDocs,
            );
            $payload['document_urls'] = array_values(array_filter(array_map(
                static fn ($d): ?string => is_string($d->source_url) ? $d->source_url : null,
                $savedDocs
            )));

            $cachedImageUrls = array_values(array_filter(array_map(
                static fn ($img): ?string => is_string($img->source_url) ? $img->source_url : null,
                $savedImages
            )));

            // Zdjęcie wybrane, ale źródło chwilowo odmówiło (zapora ansell.com) — products:retry-images ponowi później.
            // Tylko na karcie: pamięć SKU (storeSkuCache) dostaje $payload bez tego klucza.
            // bez nowego zdjęcia poprzednie zostaje (dropPreviousWebFiles) — komunikat nie może mówić o karcie bez zdjęcia,
            // a ponawianie (products:retry-images bierze tylko karty bez zdjęć) nie ma czego ponawiać
            // Etap 2b (decyzja właściciela 08.10.2026): przy pełnym pobraniu (force) bez nowego zdjęcia stare zdjęcia
            // z internetu o kolorach rozłącznych z kolorami karty (nazwa pliku) są usuwane — po zapisie opisu, tu tylko
            // lista, żeby ślad i komunikat karty mówiły prawdę; zdjęcia bez koloru w nazwie zostają (nie wiemy, jakie są)
            $staleColourImages = $savedImages === [] && $previousWebFiles !== null
                ? $this->imagesInOtherColour($product, $cardColours, $previousWebFiles['images'])
                : [];
            $keptPreviousImages = $savedImages === [] && $previousWebFiles !== null
                && array_diff($previousWebFiles['images'], array_keys($staleColourImages)) !== [];
            $retryImages = $cachedImageUrls === [] && $imageRetryUrls !== [] && ! $keptPreviousImages;
            if ($staleColourImages !== []) {
                $this->attemptLog()->add('image', self::staleColourNote($staleColourImages), urls: array_values($staleColourImages));
            }
            if ($keptPreviousImages) {
                $this->attemptLog()->add('image', 'nowego zdjęcia brak — zostaje poprzednie zdjęcie karty');
            }
            $product->refresh();
            $productPayload = $payload;
            if ($retryImages) {
                // sklepy ten przebieg już sprawdził (tryImagesFromOtherCards ze sourceRefused) — ponawianie ich nie powtarza
                $productPayload[ProductImageRetry::PAYLOAD_KEY] = ProductImageRetry::fresh($imageRetryUrls, shopsTried: true);
            }
            if ($priceListSources !== null) {
                $productPayload['price_list_sources'] = $priceListSources;
            }
            // co kontrola źródeł usunęła (kody norm) albo tylko zauważyła (twierdzenia bez pokrycia) — do sprawdzenia na karcie
            if ($claimCheck['dropped_norm_claims'] !== []) {
                $productPayload['dropped_norm_claims'] = $claimCheck['dropped_norm_claims'];
            }
            if ($claimCheck['unverified_claims'] !== []) {
                $productPayload['unverified_claims'] = $claimCheck['unverified_claims'];
            }
            // zdania i pozycje list, w których model powtórzył polecenie zamiast opisać wyrób (etap 3, W7)
            if ($droppedMetaSentences !== []) {
                $productPayload['dropped_meta_sentences'] = $droppedMetaSentences;
            }
            // Pochodzenie opisu (etap 1): werdykt tożsamości źródła i dowody wartości krytycznych — tylko na karcie
            // i w wersji opisu, pamięć SKU dostaje $payload bez nich.
            $productPayload['identity'] = $identity;
            $productPayload['evidence'] = $evidence['entries'];
            $productPayload['evidence_summary'] = ['explicit' => $evidence['explicit'], 'inferred' => $evidence['inferred']];
            $productPayload['completeness'] = $evidence['completeness'];
            // Pochodzenie opisu modelu (etap 2): klucz modelu karty — lider = ta karta, numer wersji lidera niesie
            // description_version_id; członkowie dostają leader_product_id lidera i shared = true (applyModelDescription).
            $modelGroup = $this->modelGroupPayload($product);
            if ($modelGroup !== null) {
                $productPayload['model_group'] = $modelGroup;
            }
            $storedDescription = mb_substr($description, 0, 10000);
            $trace = $cachedImageUrls === []
                ? $this->attemptLog()->snapshot($product)
                : $this->attemptLog()->snapshot($product, self::TRACE_STEPS_ON_SUCCESS);
            $candidate = [
                'identity' => $identity,
                'evidence_count' => $evidence['explicit'],
                'primary_source_url' => $primarySourceUrl,
                'manual_url' => $primarySourceKind === 'manual',
            ];
            $versionData = [
                'description' => $storedDescription,
                'enrichment_payload' => $productPayload,
                'enrichment_trace' => $trace,
                'packaging' => $packaging,
                'primary_source_url' => $primarySourceUrl,
                'batch_id' => $batchId,
                // adresy zdjęć stron opisu — tylko w danych technicznych wersji (etap 2: członkowie modelu biorą z nich
                // zdjęcie w kolorze swojej karty bez ponownego pobierania stron); publishDescription dokłada resztę meta
                DescriptionVersionStore::META_KEY => ['page_image_urls' => $this->pageImageUrls($descPages)],
            ];
            $saved = [
                'description' => $storedDescription,
                'enrichment_payload' => $productPayload,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
                'enrichment_error' => $cachedImageUrls === []
                    ? ($staleColourImages !== []
                        ? 'Opis OK, zdjęcie w innym kolorze usunięte, nowego brak'
                        : ($keptPreviousImages ? 'Opis OK, nowego zdjęcia nie udało się pobrać — zostaje poprzednie' : 'Opis OK, nie udało się pobrać zdjęcia'))
                        .($imageFailure !== '' ? ' ('.$imageFailure.')' : ' (karty nie miały zdjęcia produktu)').'.'
                        .($retryImages ? ProductImageRetry::ERROR_NOTE : '')
                    : null,
                // Ślad zapisujemy też po udanym przebiegu (skrócony) — bez niego nie da się
                // sprawdzić, z której karty powstał opis, a właśnie to zgłasza tester.
                'enrichment_trace' => $trace,
            ];
            if ($packaging !== null) {
                $saved['packaging'] = $packaging;
            }
            // Decyzja i zapis atomowo (publishRun): pod blokadą karty ponowne decide — w międzyczasie inny przebieg mógł
            // zapisać lepszy opis albo handlowiec odrzucić stronę źródła. Opis gorszy od obecnego (albo ze strony
            // odrzuconej) zostaje propozycją, a karta wraca do stanu sprzed przebiegu.
            $version = $this->publishDescription($product, ProductDescriptionVersion::ORIGIN_ENRICHMENT, $candidate, $versionData, $saved, $before, runWrites: $runWrites, newSourceUrl: $primarySourceUrl);
            if ($version === null) {
                // nic nie znika przy propozycji — ślad wersji nie może mówić o usuniętym zdjęciu
                $versionData['enrichment_trace'] = self::traceWithStaleColourKept($versionData['enrichment_trace'], $staleColourImages);
                $this->keepAsProposal($product, $before, $batchId, $versionData, $this->lastRunDecision, $runWrites, $identity, $sourcePages, $primarySourceUrl, $primarySourceKind);
                $timing['docs_ms'] = $this->elapsedMs($t);
                $this->logEnrichmentTiming($timing, $started, extra: ['proposal' => true]);

                return;
            }
            // Nowy opis zapisany — stare zdjęcia i dokumenty z internetu ustępują; te, które przebieg pobrał ponownie
            // (ten sam plik — downloader oddaje istniejący wiersz), zostają.
            $this->dropPreviousWebFiles($product, $previousWebFiles, $savedImages, $savedDocs);
            if ($staleColourImages !== []) {
                $this->clearProductImages($product, array_keys($staleColourImages));
            }
            // od tej chwili pliki i normy należą do nowego opisu — błąd dalszych kroków nie może ich cofać (catch)
            $previousWebFiles = null;
            $runWrites = [];
            $this->refineCategoryFromDescription($product, $description);
            $this->rememberAccessories($product, $pageSnippets);

            ReindexProductEmbeddingJob::dispatch($product->id, true);

            // marka z opisem wspólnym dla modelu nie pisze pamięci SKU — pochodzenie niesie wersja opisu (etap 2)
            if (! $sharedModel) {
                $this->storeSkuCache(
                    $product,
                    $description,
                    $payload,
                    $cachedImageUrls, // tylko realnie pobrane — nie cache'uj 404
                    $sourceUrls
                );
            }
            $this->recordSourceDocuments(
                $product,
                $sourcePages,
                $identity,
                $primarySourceUrl,
                $primarySourceKind,
                $imagesFromOtherCards ? [] : $this->imageSourcePages($descPages, $savedImages),
                (int) $version->id,
            );
            $timing['docs_ms'] = $this->elapsedMs($t);
            $this->logEnrichmentTiming($timing, $started);
        } catch (Throwable $e) {
            $this->logEnrichmentTiming($timing, $started, $e->getMessage());
            Log::warning('Product enrichment failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            // Anulowanie partii to nie wynik przebiegu: status, komunikat i ślad karty ustawia restoreAfterCancel
            // (stan sprzed kolejki) — tu tylko nowe pliki tego przebiegu ustępują starym.
            if ($e instanceof EnrichmentCancelledException) {
                try {
                    if ($previousWebFiles !== null) {
                        $this->dropWebFilesAddedSince($product, $previousWebFiles);
                    }
                    // normy zapisane przez przebieg przed decyzją należą do opisu, który się nie zapisał
                    $this->undoRunColumnWrites($product, $before, $runWrites ?? []);
                } catch (Throwable) {
                    // jak niżej — pliki sprzątnie kolejny przebieg
                }
                throw $e;
            }
            try {
                $this->attemptLog()->add('fail', $e->getMessage());
                // Etap 3 (W4, decyzja właściciela 08.10.2026): brak strony producenta przy marce „tylko od producenta” —
                // przy pełnym pobraniu opis ze sklepu cofany do historii wersji (zdjęcia i pliki zostają).
                // czekająca propozycja (gorszy opis / strona odrzucona) jest ważniejsza niż brak strony producenta —
                // handlowiec ma ją zobaczyć w „Do przeglądu” pod jej powodem (czytana przed cofnięciem, które ustawia
                // manufacturer_missing w magazynie wersji)
                $waitingReview = $e instanceof ManufacturerPageMissingException ? $this->waitingProposalReview($product) : null;
                $withdrawn = $force && $e instanceof ManufacturerPageMissingException
                    && $this->withdrawShopDescription($product);
                $failed = [
                    'enrichment_status' => $this->enrichmentStatusForFailure($e),
                    'enrichment_error' => mb_substr($e->getMessage(), 0, 2000),
                    'enrichment_trace' => $this->attemptLog()->snapshot($product),
                ];
                if ($waitingReview !== null) {
                    $failed = [...$failed, ...$waitingReview];
                } elseif ($e instanceof ManufacturerPageMissingException) {
                    $failed['review_reason'] = Product::REVIEW_MANUFACTURER_MISSING;
                    $failed['review_since'] = $this->reviewSince($product, Product::REVIEW_MANUFACTURER_MISSING);
                }
                // Opis się nie zapisał (błąd modelu, zatrzymanie partii) — zdjęcia i pliki pobrane w tym przebiegu
                // znikają, stare zostają przy starym opisie; bez tego karta miała oba komplety naraz.
                if ($previousWebFiles !== null) {
                    $this->dropWebFilesAddedSince($product, $previousWebFiles);
                }
                // normy zapisane przez przebieg przed decyzją należą do opisu, który się nie zapisał — wracają (jak przy
                // propozycji), o ile nikt ich w międzyczasie nie zmienił
                $this->undoRunColumnWrites($product, $before, $runWrites ?? []);
                // opis cofnięty przez W4 jest już w historii wersji — bez kasowania czegokolwiek więcej. Brak strony
                // producenta (marka ścisła) nie czyści karty wcale: opis, który W4 zostawił (producent, katalog PDF,
                // adres wskazany ręcznie, wersja chroniona, bez źródła), nie jest „cudzy” tylko dlatego, że nie nazywa
                // marki — bez tego warunku tracił tekst, payload, normy, zdjęcia i pliki.
                if ($force && $e instanceof ProductSourcesNotFoundException && ! $withdrawn
                    && ! $e instanceof ManufacturerPageMissingException) {
                    $old = trim((string) $product->description);
                    if ($old !== '' && ! $this->descriptionMentionsProduct($old, $product)) {
                        $failed['description'] = '';
                        $failed['enrichment_payload'] = null;
                        // normy należą do tego samego cudzego opisu (writeNormsColumn)
                        $failed['norms'] = null;
                        // cudzy opis przyszedł z cudzej karty — jej zdjęcia i PDF-y też
                        $this->clearProductImages($product);
                        $this->clearProductDocuments($product);
                    }
                }
                $product->update($failed);
            } catch (Throwable) {
                // np. padnięte MySQL — status może zostać "running"; UI pozwala odblokować
            }
            throw $e;
        } finally {
            $this->pages->bypassCache(false);
            $this->pages->stopRunLog();
            $this->liveProgress()->clear();
            // worker kolejki trzyma serwis między zadaniami — kolejna karta nie może dostać stron tego cennika
            $this->listSources = null;
            $this->listSourcesManufacturerDomains = [];
            // kody kart producenta (bramka wariantu) czytane na nowo przy kolejnej karcie — katalog rośnie między zadaniami
            $this->manufacturerCatalogCodes = [];
            $this->rejectedSourceKeys = [];
            // nota modelu należy do lidera tego przebiegu — kolejna karta liczy ją od nowa (albo nie ma jej wcale)
            $this->modelContext = null;
            $this->manufacturerRetryOutage = null;
            // Etap 3 (W8): kody kart marki arbitra czytane na nowo przy kolejnej karcie — katalog rośnie między zadaniami
            app(CardCodeArbiter::class)->forget();
        }
    }

    /**
     * Komunikat o propozycji z ostatniego enrichProduct: opis powstał, ale nie wszedł na kartę i czeka w „Do przeglądu”
     * (gorszy od obecnego albo ze strony odrzuconej przez handlowca). null = przebieg bez propozycji. Pozycja partii
     * zostaje „gotowe”, z tym komunikatem zamiast komunikatu karty.
     */
    public function lastProposalNote(): ?string
    {
        return $this->lastProposalNote;
    }

    /**
     * Wersja opisu z ostatniego enrichProduct: zapisana (published, także z pamięci SKU) albo propozycja (proposed).
     * null = przebieg bez wersji (brak źródeł, wyjątek, opis odrzucony, zdjęcie bez opisu). EnrichProductJob daje ją
     * członkom modelu lidera (ApplyModelDescriptionJob) albo — bez wersji — promuje kolejnego członka na lidera.
     */
    public function lastRunVersion(): ?ProductDescriptionVersion
    {
        return $this->lastRunVersion;
    }

    /**
     * Karta do sita opisu (isUsableProductDescription → descriptionMentionsProduct) w przebiegu lidera modelu: klon
     * z nazwą = rdzeń nazwy modelu (bez wymiarów i koloru). Sito odrzucało poprawne opisy Coby, bo nazwa z cennika
     * niesie wymiary („Rib Mat Czarny 0.9m x 15.3m (12.5mm)”), których opis modelu celowo nie powtarza. Bez noty
     * modelu — karta bez zmian.
     */
    /**
     * Sito opisu w przebiegu lidera modelu: wystarczy, że opis przejdzie na rdzeniu nazwy ALBO na pełnej nazwie karty.
     * Sam rdzeń gubi wymiar, który rozstrzyga typ wyrobu — pilotaż 08.10.2026 (partia #499): „CCLIP-38” z rdzeniem
     * „Akcesoria Krata GRP Uchwyt typu C” (bez „38mm”) czytany jako rozmiar buta 38 → wymagany typ „obuwie” → poprawny
     * opis uchwytu odrzucony, karta „ręcznie”; sama pełna nazwa odrzucała z kolei opisy mat Coby przez wymiary.
     *
     * @param  list<string>  $sourceUrls
     */
    private function isUsableModelDescription(string $description, Product $product, array $sourceUrls): bool
    {
        $card = $this->modelIdentityCard($product);
        if ($this->isUsableProductDescription($description, $card, $sourceUrls)) {
            return true;
        }

        return $card !== $product && $this->isUsableProductDescription($description, $product, $sourceUrls);
    }

    private function modelIdentityCard(Product $product): Product
    {
        $stem = trim((string) ($this->modelContext['stem'] ?? ''));
        if ($this->modelContext === null || $stem === '') {
            return $product;
        }
        $card = clone $product;
        $card->name = $stem;

        return $card;
    }

    /**
     * Nota modelu w treści użytkownika polecenia opisu (tylko lider grupy z ≥ min_members kart modelu w partii):
     * lista wariantów „sku — nazwa” i prośba o opis modelu bez wymiaru, koloru i kodu pojedynczego wariantu.
     *
     * @param  array{key: string, stem: string, members: list<array{id: int, sku: string, name: string}>}  $context
     */
    private function modelContextNote(array $context): string
    {
        $members = array_values(is_array($context['members'] ?? null) ? $context['members'] : []);
        $lines = [];
        foreach (array_slice($members, 0, self::MODEL_NOTE_MAX_MEMBERS) as $member) {
            $lines[] = '- '.trim((string) ($member['sku'] ?? '')).' — '.trim((string) ($member['name'] ?? ''));
        }
        $rest = count($members) - count($lines);
        if ($rest > 0) {
            $lines[] = '- … i '.$rest.' kolejnych';
        }
        $stem = trim((string) ($context['stem'] ?? ''));

        return "\n\nOpis wspólny dla ".count($members).' wariantów tego modelu'.($stem !== '' ? ' „'.$stem.'”' : '')." (lista: sku — nazwa):\n"
            .implode("\n", $lines)
            ."\nOpisz model bez wymiaru, koloru i kodu pojedynczego wariantu w description; warianty/rozmiary/kolory ze źródła podaj w specs."
            // audyt Coby 08.10.2026: COBAswitch 9,5 mm z parametrami wariantu 6 mm (11 000 V / 450 V zamiast 15 000 V /
            // 650 V), Tough Lock pomarańczowy „w kolorze żółtym”, listwy GRP z wymiarem i wagą jednego wariantu
            ."\nW tekście description nie podawaj wartości jednego wariantu — wymiarów, grubości, koloru, wagi, napięcia ani innych parametrów elektrycznych, długości rolki — tylko cechy wspólne wszystkich wariantów."
            .' Wartości poszczególnych wariantów podawaj wyłącznie w specs, każdą z kodem wariantu (sku), którego dotyczy.';
    }

    /**
     * Pochodzenie opisu modelu w enrichment_payload lidera (etap 2): klucz z pozycji partii (zamrożony), a bez noty
     * modelu — liczony z karty; null = marka bez grupowania albo pusty rdzeń. Numer wersji lidera niesie
     * description_version_id karty; członkowie dostają go w leader_version_id (applyModelDescription).
     *
     * @return array{key: string, stem: string, family: string, leader_product_id: int, leader_version_id: null, members: int, shared: false}|null
     */
    private function modelGroupPayload(Product $product): ?array
    {
        $key = $this->modelKeys()->for($product);
        if ($key === null) {
            return null;
        }
        $context = $this->modelContext;

        return [
            'key' => trim((string) ($context['key'] ?? '')) !== '' ? (string) $context['key'] : $key->key,
            'stem' => $key->stem,
            'family' => $key->family,
            'leader_product_id' => (int) $product->id,
            'leader_version_id' => null,
            'members' => $context !== null ? count($context['members']) : 1,
            'shared' => false,
        ];
    }

    /**
     * Adresy zdjęć stron opisu (zaufane pierwsze, bez śmieci) do danych technicznych wersji lidera — członkowie
     * modelu biorą z nich zdjęcie w kolorze swojej karty bez ponownego pobierania stron (ModelImagePicker).
     *
     * @param  list<array<string, mixed>>  $descPages
     * @return list<string>
     */
    private function pageImageUrls(array $descPages): array
    {
        $urls = [];
        foreach (['trusted_image_urls', 'image_urls'] as $field) {
            foreach ($descPages as $page) {
                foreach ((array) ($page[$field] ?? []) as $url) {
                    $urls[] = $url;
                }
            }
        }

        return $this->cleanImageUrls($urls);
    }

    /**
     * Galeria stron opisu dla pickera zdjęcia lidera (etap 2b): zaufane adresy pierwsze, potem reszta — ta sama
     * kolejność i ten sam filtr, co page_image_urls wersji.
     *
     * @param  array{all?: list<mixed>, trusted?: list<mixed>}  $fromCards
     * @return list<string>
     */
    private function galleryFromCards(array $fromCards): array
    {
        return $this->cleanImageUrls([...(array) ($fromCards['trusted'] ?? []), ...(array) ($fromCards['all'] ?? [])]);
    }

    /**
     * Adresy zdjęć bez powtórzeń, śmieci (isJunkImageUrl) i adresów niebędących plikiem obrazka, w podanej kolejności,
     * najwyżej PAGE_IMAGE_URLS_MAX.
     *
     * @param  list<mixed>  $urls
     * @return list<string>
     */
    private function cleanImageUrls(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            if (! is_string($url) || ! str_starts_with($url, 'http') || isset($out[$url])
                || $this->isJunkImageUrl($url) || ! ProductImageDownloader::looksLikeImageUrl($url)) {
                continue;
            }
            $out[$url] = true;
            if (count($out) >= DescriptionVersionStore::PAGE_IMAGE_URLS_MAX) {
                break;
            }
        }

        return array_keys($out);
    }

    /**
     * Kolory karty (zbiór kanoniczny, np. „grey”) dla marki z opisem wspólnym dla modelu: z faktów z cennika
     * (PriceListCardFacts — kolumna „kolor” albo nazwa), zapasowo wprost z nazwy; [] = karta bez koloru.
     *
     * @return list<string>
     */
    private function cardColours(Product $product): array
    {
        $facts = $this->cardFacts()->for($product);
        $colours = is_array($facts['colours'] ?? null) ? array_values(array_filter($facts['colours'], 'is_string')) : [];

        return $colours !== [] ? $colours : ColourWords::allInName((string) $product->name);
    }

    /**
     * Zamiennik zdjęcia z innej karty TEGO SAMEGO modelu w tej partii (ModelImagePicker::pickFromModelSiblings — ponowny
     * audyt Coby 08.10.2026: Entra-Plush Szary 11162 ← „…_Grey.jpg” z 11163): karty z tym samym model_key w partii, bez
     * samej karty, ich zdjęcia z internetu (bez plików z panelu B2B) w kolejności główne → sort_order → id. Kopia pliku
     * na karcie (właścicielem źródła jest karta-rodzeństwo, nie lider). null = bez partii, bez koloru karty, bez
     * pasującego zdjęcia albo kopia się nie udała (plik zniknął z dysku, karta odrzuciła ten plik).
     *
     * @param  list<string>  $colours  kolory karty
     */
    private function siblingImageCopy(Product $card, array $colours, ?int $batchId, ?string $modelStem): ?ProductImage
    {
        if ($batchId === null || $colours === [] || ! self::batchItemsHaveModelColumns()) {
            return null;
        }
        $key = ProductEnrichmentBatchItem::query()->where('batch_id', $batchId)->where('product_id', $card->id)->value('model_key');
        if (! is_string($key) || trim($key) === '') {
            return null;
        }
        $siblingIds = ProductEnrichmentBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('model_key', $key)
            ->where('product_id', '!=', $card->id)
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($siblingIds === []) {
            return null;
        }
        $images = ProductImage::query()
            ->with('product:id,sku,name')
            ->whereIn('product_id', $siblingIds)
            ->whereNull('b2b_account_id')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
        // W5 jak przy każdej kandydaturze: zdjęcie innego wyrobu marki albo ze sprzeczną cechą (kV, klasa) odpada
        $allowed = array_flip($this->withoutForeignImages($card, array_values(array_filter(array_map(
            static fn (ProductImage $image): string => trim((string) $image->source_url),
            $images
        )))));
        $images = array_values(array_filter(
            $images,
            static fn (ProductImage $image): bool => isset($allowed[trim((string) $image->source_url)])
        ));
        $sibling = $this->imagePicker()->pickFromModelSiblings($card, $colours, $images, $modelStem);
        if ($sibling === null || $sibling->product === null) {
            return null;
        }
        $copy = $this->copier()->copyImage($sibling->product, $sibling, $card);
        $this->attemptLog()->add(
            'image',
            ModelImagePicker::REASON_SIBLING_COPY.' ('.$sibling->product->sku.')'.($copy === null ? ' — kopia nie powiodła się' : ''),
            urls: [(string) $sibling->source_url]
        );

        return $copy;
    }

    /**
     * Zdjęcia z internetu karty (bez plików z panelu B2B, z adresem źródła) spośród podanych, których nazwa pliku mówi
     * o innym zbiorze kolorów niż karta (ColourWords::sameSet — plik dwubarwny przy karcie jednobarwnej też) — id => adres (etap 2b, decyzja właściciela 08.10.2026: takie zdjęcie przy
     * pełnym pobraniu bez zamiennika jest usuwane). Zdjęcie bez koloru w nazwie pliku zostaje — nie wiemy, co
     * przedstawia; zdjęcia ręczne (bez adresu) i z B2B nietknięte.
     *
     * @param  list<string>  $colours  kolory karty; [] = bez reguły
     * @param  list<int>  $onlyIds  zdjęcia sprzed przebiegu (webFileIds)
     * @return array<int, string>
     */
    private function imagesInOtherColour(Product $product, array $colours, array $onlyIds): array
    {
        if ($colours === [] || $onlyIds === []) {
            return [];
        }
        $out = [];
        $images = ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNull('b2b_account_id')
            ->whereNotNull('source_url')
            ->whereIntegerInRaw('id', $onlyIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'source_url']);
        foreach ($images as $image) {
            $fileColours = ColourWords::allInUrl((string) $image->source_url);
            // plik dwubarwny („BlackYellow” na karcie Żółtej, „Yel_Bk” na Czarnej) to też zdjęcie innego wariantu
            if ($fileColours !== [] && ! ColourWords::sameSet($fileColours, $colours)) {
                $out[(int) $image->id] = (string) $image->source_url;
            }
        }

        return $out;
    }

    /**
     * Krok śladu o usunięciu zdjęć w innym kolorze — nazwy plików.
     *
     * @param  array<int, string>  $staleColourImages  id => adres
     */
    private static function staleColourNote(array $staleColourImages): string
    {
        $names = array_map(
            static fn (string $url): string => rawurldecode(basename((string) (parse_url($url, PHP_URL_PATH) ?? $url))),
            array_values($staleColourImages),
        );

        return 'zdjęcie w innym kolorze usunięte: '.implode(', ', $names).' — nowego brak';
    }

    /**
     * Ślad przebiegu dla propozycji: krok o usunięciu zdjęcia w innym kolorze (staleColourNote) zastąpiony informacją,
     * że zdjęcie zostaje — przy propozycji karta wraca do stanu sprzed przebiegu i nic nie jest usuwane.
     *
     * @param  mixed  $trace  EnrichmentAttemptLog::snapshot
     * @param  array<int, string>  $staleColourImages  id => adres
     */
    private static function traceWithStaleColourKept(mixed $trace, array $staleColourImages): mixed
    {
        if ($staleColourImages === [] || ! is_array($trace) || ! is_array($trace['steps'] ?? null)) {
            return $trace;
        }
        $note = self::staleColourNote($staleColourImages);
        foreach ($trace['steps'] as $i => $step) {
            if (is_array($step) && ($step['m'] ?? null) === $note) {
                $trace['steps'][$i]['m'] = 'zdjęcie w innym kolorze zostaje — opis czeka w przeglądzie';
            }
        }

        return $trace;
    }

    /** Ranga werdyktu tożsamości (hard > soft > none > brak) — zapasowy werdykt karty członka modelu. */
    private static function verdictRank(mixed $verdict): int
    {
        return match ($verdict) {
            ProductDescriptionVersion::VERDICT_HARD => 3,
            ProductDescriptionVersion::VERDICT_SOFT => 2,
            ProductDescriptionVersion::VERDICT_NONE => 1,
            default => 0,
        };
    }

    /**
     * Etykieta linii specs („Grubość: 9,5 mm” → „grubość”): tekst przed pierwszym dwukropkiem, małymi literami, bez
     * nadmiarowych białych znaków; null = linia bez etykiety.
     */
    private static function specLabel(string $line): ?string
    {
        $pos = mb_strpos($line, ':');
        if ($pos === false) {
            return null;
        }
        $label = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', mb_substr($line, 0, $pos))), 'UTF-8');

        return $label !== '' ? $label : null;
    }

    /**
     * Klucz tożsamości członka do source.identity kolumny norm producenta — tylko z twardego werdyktu kodem wyrobu na
     * stronie, w formacie ManufacturerNormIdentity::confirm (norms:from-manufacturer-pages): by = rodzaj kodu,
     * value = kod, where = gdzie na stronie. Adres ręczny, blok katalogu i słabsze werdykty nie dają klucza.
     *
     * @param  array<string, mixed>  $identity  werdykt karty (SourceIdentity::judgePage + source_url/profile)
     * @return array{by: string, value: string, where: string}|null
     */
    private static function normIdentityFromVerdict(array $identity): ?array
    {
        $keyType = (string) ($identity['key_type'] ?? '');
        $key = trim((string) ($identity['key'] ?? ''));
        $where = (string) ($identity['where'] ?? '');
        if (($identity['verdict'] ?? null) !== ProductDescriptionVersion::VERDICT_HARD
            || $key === ''
            || ! in_array($keyType, ['sku', 'ean', 'manufacturer_code', 'model_code'], true)
            || ! in_array($where, ['url', 'title', 'markup', 'text'], true)) {
            return null;
        }

        return ['by' => $keyType, 'value' => $key, 'where' => $where];
    }

    /**
     * Opis wspólny dla modelu (etap 2 opisów z cenników, §1.2 kontraktu): członek grupy modelu dostaje opis z wersji
     * lidera bez modelu językowego. Per karta: werdykt tożsamości (judgePage na zapisanych stronach źródeł lidera
     * z kluczami członka — tabela części Coby daje twardy werdykt wymiarom; werdykt karty = strona głównego źródła
     * lidera, zapasowo najlepszy), listy wspólne z payloadu lidera + fakty z cennika członka (PriceListCardFacts:
     * wymiary, kolor, atrybuty z kolumn, EAN — do specs i do dowodów `price_list`), atrybuty i rozmiar per karta
     * (payloadFromExtraction, bez rozmiaru lidera), dowody przeliczone na tekstach lidera, normy jak u lidera (kolumna
     * norm z listy; normy producenta ze strony producenta z ochroną replaceableFromWebPage), zdjęcie w kolorze karty
     * (ModelImagePicker: adres z galerii stron lidera do pobrania albo kopia pliku lidera bez sieci; kolor sprzeczny —
     * bez nowego), dokumenty lidera kopią, opis prozą identyczny z liderem, nazwa karty bez zmian. Decyzja zapisu per
     * karta (publishRun): twarda baza i słabszy werdykt → propozycja (karta wraca do stanu sprzed przebiegu), inaczej
     * publikacja z origin model_shared. Członek, który ten opis lidera już ma (bieżąca baza o tym samym skrócie
     * i leader_version_id) → skipped. Pamięć SKU nieczytana i niepisana. Pliki z internetu sprzed przebiegu ustępują
     * nowym po zapisie w partii z force (jak przebieg karty). Wyjątki idą do wołającego (ApplyModelDescriptionJob łapie
     * per członek i ustawia kartę) — pliki dodane przez ten przebieg znikają wtedy razem z błędem.
     *
     * @return string ApplyModelDescriptionJob::RESULT_PUBLISHED | RESULT_PROPOSED | RESULT_SKIPPED
     */
    public function applyModelDescription(Product $member, ProductDescriptionVersion $leaderVersion, ?int $batchId): string
    {
        // worker trzyma serwis między zadaniami — nota propozycji i wersja należą tylko do tego członka
        $this->lastProposalNote = null;
        $this->lastRunVersion = null;
        $this->assertBatchNotCancelled($batchId);

        $leader = $leaderVersion->product;
        if ($leader === null) {
            throw new RuntimeException("Karta lidera wersji opisu #{$leaderVersion->id} nie istnieje");
        }
        if ((int) $leader->id === (int) $member->id) {
            throw new RuntimeException('Lider modelu nie dostaje własnego opisu jako członek');
        }
        // zadanie członków czeka w kolejce za przebiegami liderów (sweeper zleca je nawet po kwadransach) — w tym czasie
        // handlowiec mógł odrzucić opis lidera jako cudzą stronę; odrzucona wersja nie jest podstawą dla członków
        if ($leaderVersion->status === ProductDescriptionVersion::STATUS_REJECTED
            || $leaderVersion->decision === ProductDescriptionVersion::DECISION_REJECTED) {
            throw new RuntimeException("Wersja opisu lidera #{$leaderVersion->id} została odrzucona w przeglądzie — bez podstawy dla członków modelu");
        }
        // ten sam tekst co u lidera — bez przekształceń, ten sam limit co przy zapisie karty
        $description = mb_substr((string) $leaderVersion->description, 0, 10000);
        if (! Product::isDescriptionText($description)) {
            throw new RuntimeException("Wersja opisu lidera #{$leaderVersion->id} nie ma tekstu opisu");
        }
        $leaderPayload = is_array($leaderVersion->enrichment_payload) ? $leaderVersion->enrichment_payload : [];
        $leaderMeta = $this->versions()->meta($leaderVersion);
        unset($leaderPayload[DescriptionVersionStore::META_KEY]);
        // strony tej wersji lidera = adresy z jej payloadu (główne źródło + source_urls); zapisane źródła karty lidera
        // obejmują też wcześniejsze przebiegi (inne, czasem cudze strony) — te do tego opisu nie należą
        $leaderUrlKeys = [];
        foreach ([(string) ($leaderPayload['primary_source_url'] ?? ''), ...array_filter((array) ($leaderPayload['source_urls'] ?? []), 'is_string')] as $url) {
            if (trim($url) !== '') {
                $leaderUrlKeys[Product::normalizeShopUrl($url)] = true;
                $leaderUrlKeys[Product::normalizeShopUrl($this->identity->preferredLocaleUrl($url, $leader))] = true;
            }
        }
        // lider skończył propozycją (opis słabszy od jego bazy): członek dostaje tekst do własnej decyzji, ale bez plików
        // i norm z karty lidera — jego zdjęcia, dokumenty i normy pochodzą sprzed tego przebiegu (§5 kontraktu)
        $leaderProposal = $leaderVersion->status === ProductDescriptionVersion::STATUS_PROPOSED;

        // opis tego lidera już na karcie (bieżąca baza z tym samym skrótem i numerem wersji lidera) — tylko status
        $current = $this->versions()->current($member);
        if ($current !== null
            && $current->description_sha1 === sha1($description)
            && (int) ($current->enrichment_payload['model_group']['leader_version_id'] ?? 0) === (int) $leaderVersion->id) {
            if ($member->enrichment_status !== Product::ENRICHMENT_DONE) {
                $member->update(['enrichment_status' => Product::ENRICHMENT_DONE]);
            }

            return ApplyModelDescriptionJob::RESULT_SKIPPED;
        }

        // stan karty sprzed przebiegu — propozycja przywraca z niego normy, pliki i status (jak enrichProduct)
        $before = $this->versions()->snapshot($member);
        $this->attemptLog()->reset();
        $this->attemptLog()->add('start', trim($member->sku.' · '.$member->name.' · '.$member->manufacturer));
        $this->attemptLog()->add('model', 'opis wspólny modelu z karty lidera '.$leader->sku.' (wersja #'.$leaderVersion->id.')');
        $member->update([
            'enrichment_status' => Product::ENRICHMENT_RUNNING,
            'enrichment_error' => null,
        ]);
        if ($batchId !== null) {
            $this->liveProgress()->bind($batchId, $member);
            $this->liveProgress()->step('opis wspólny modelu');
        }
        // pliki z internetu sprzed przebiegu: partia z force zastępuje je nowymi po zapisie opisu (jak przebieg karty)
        $replaceFiles = $batchId !== null && (bool) ProductEnrichmentBatch::query()->whereKey($batchId)->value('force');
        $previousWebFiles = $replaceFiles ? $before['web_files'] : null;
        $published = false;
        try {
            $profile = $this->profiles()->for($member);
            $primaryUrl = trim((string) ($leaderPayload['primary_source_url'] ?? $leaderVersion->primary_source_url ?? ''));
            $primaryUrl = $primaryUrl !== '' ? $primaryUrl : null;
            $primaryKind = is_string($leaderPayload['primary_source_kind'] ?? null) ? $leaderPayload['primary_source_kind'] : null;

            // zapisane strony źródeł lidera (teksty z dysku po jednym) i werdykt członka na każdej — klucze członka
            $pages = [];
            $primaryVerdict = null;
            $bestVerdict = null;
            $primaryKey = $primaryUrl !== null ? Product::normalizeShopUrl($primaryUrl) : null;
            foreach ($this->sources()->forProduct($leader) as $doc) {
                $addresses = [
                    Product::normalizeShopUrl($doc->url),
                    Product::normalizeShopUrl($this->identity->preferredLocaleUrl($doc->url, $member)),
                ];
                if (trim((string) $doc->finalUrl) !== '') {
                    $addresses[] = Product::normalizeShopUrl((string) $doc->finalUrl);
                }
                if (array_intersect_key(array_fill_keys($addresses, true), $leaderUrlKeys) === []) {
                    continue;
                }
                $page = $this->storedSourcePage($doc);
                if ($page === null) {
                    continue;
                }
                $verdict = $this->sourceIdentity()->judgePage($member, $page, $profile);
                $pages[] = $page;
                if ($primaryVerdict === null && $primaryKey !== null && in_array($primaryKey, $addresses, true)) {
                    $primaryVerdict = $verdict;
                }
                if ($bestVerdict === null || self::verdictRank($verdict['verdict']) > self::verdictRank($bestVerdict['verdict'])) {
                    $bestVerdict = $verdict;
                }
            }
            $cardVerdict = $primaryVerdict ?? $bestVerdict;
            // bez zapisanych stron wersji lidera nie ma czym potwierdzić karty — werdykt „none” (nie null): przy bazie
            // o znanej randze decyzja daje propozycję, bez bazy zapis z powodem przeglądu identity_none
            $identity = ($cardVerdict ?? [
                'verdict' => ProductDescriptionVersion::VERDICT_NONE,
                'reason' => $leaderUrlKeys === []
                    ? 'wersja lidera bez adresów źródeł'
                    : ($pages === [] ? 'brak zapisanych stron źródeł tej wersji lidera' : 'strona głównego źródła lidera nie jest zapisana'),
                'key_type' => null,
                'key' => null,
                'where' => null,
            ]) + ['source_url' => $primaryUrl, 'profile' => $profile === null ? null : ($profile->profileKey ?? 'default')];
            $this->attemptLog()->add(
                'identity',
                'werdykt '.($identity['verdict'] ?? 'brak').' — '.$identity['reason'].($primaryVerdict === null && $bestVerdict !== null ? ' (zapasowo: najlepsza strona lidera)' : ''),
                urls: array_column($pages, 'url'),
            );

            // Normy producenta jak u lidera (odczyt ze strony producenta z przebiegu lidera) — tylko gdy kolumna członka
            // jest pusta albo sama pochodzi ze strony (replaceableFromWebPage); cofane przy propozycji ($runWrites).
            $runWrites = [];
            $leaderNorms = $leaderMeta['manufacturer_norms_written'] ?? $leader->manufacturer_norms;
            if (! $leaderProposal
                && is_array($leaderNorms)
                && ($leaderNorms['source']['connector'] ?? null) === ManufacturerNormFacts::WEB_PAGE_CONNECTOR
                && ManufacturerNormFacts::norms($leaderNorms) !== []
                && ManufacturerNormFacts::replaceableFromWebPage($member->manufacturer_norms)) {
                // Klucz tożsamości (source.identity) należy do karty lidera — u członka czyniłby kolumnę „sprawdzoną”
                // (ManufacturerNormFacts::verified, dowód w przetargu) cudzym kodem. Zostaje tylko przy twardym werdykcie
                // członka, w formacie norms:from-manufacturer-pages (ManufacturerNormIdentity::confirm), z jego kluczem.
                $column = $leaderNorms;
                unset($column['source']['identity']);
                $column['source']['copied_from_product_id'] = (int) $leader->id;
                $memberIdentity = self::normIdentityFromVerdict($identity);
                if ($memberIdentity !== null) {
                    $column['source']['identity'] = $memberIdentity;
                }
                if (! ManufacturerNormFacts::sameFacts($member->manufacturer_norms, $column)) {
                    $member->manufacturer_norms = $column;
                    $member->save();
                    $runWrites['manufacturer_norms'] = $member->manufacturer_norms;
                    $this->attemptLog()->add('desc', 'normy producenta jak u lidera: '.implode(', ', ManufacturerNormFacts::norms($column)));
                }
            }

            // listy wspólne z wersji lidera + fakty z cennika członka; atrybuty i rozmiar per karta (rozmiar lidera nie
            // przechodzi — członek ma własny z cennika i ze swoich specs)
            $facts = $this->cardFacts()->for($member);
            $memberKey = $this->modelKeys()->for($member);
            // Nota modelu każe liderowi wpisywać warianty do specs („Grubość: 9,5 mm”, „Kolor: Czarny”, „Wymiary: …”) —
            // linia lidera z etykietą, którą członek ma we własnych faktach z cennika, to wartość innego wariantu i odpada;
            // linie bez takiej etykiety (np. „Faktura: bąbelkowa”) zostają wspólne.
            $ownLabels = [];
            foreach ($facts['specs'] as $line) {
                $label = self::specLabel($line);
                if ($label !== null) {
                    $ownLabels[$label] = true;
                }
            }
            $specs = [];
            foreach ($this->stringList($leaderPayload['specs'] ?? null) as $line) {
                $label = self::specLabel($line);
                if ($label !== null && isset($ownLabels[$label])) {
                    continue;
                }
                $specs[] = $line;
            }
            foreach ($facts['specs'] as $line) {
                if (! in_array($line, $specs, true)) {
                    $specs[] = $line;
                }
            }
            // atrybuty tożsamości karty nie przechodzą z lidera: kod wyrobu liczy normalizator z SKU członka (lider
            // „AF010001” u członka to fałszywy atrybut w dopasowaniu przetargów), rozmiar — z cennika i specs członka
            $leaderAttributes = is_array($leaderPayload['attributes'] ?? null) ? $leaderPayload['attributes'] : null;
            if ($leaderAttributes !== null) {
                unset($leaderAttributes['rozmiar'], $leaderAttributes['kod_producenta']);
            }
            $fields = $this->payloadFromExtraction($member, [
                'features' => $this->stringList($leaderPayload['features'] ?? null),
                'norms' => $this->stringList($leaderPayload['norms'] ?? null),
                'certificates' => $this->stringList($leaderPayload['certificates'] ?? null),
                'materials' => $this->stringList($leaderPayload['materials'] ?? null),
                'use_cases' => $this->stringList($leaderPayload['use_cases'] ?? null),
                'specs' => $specs,
                'attributes' => $leaderAttributes,
            ], $description, []);
            $lists = $fields['lists'];

            // dowody na tekstach lidera (plus normy producenta i atrybuty cennika członka — evidenceDocs) + wiersze z pliku
            // Liczba dowodów (decyzja publishRun, evidence_count wersji) tylko ze stron — tak liczy baza etapu 1; fakty
            // z cennika to dane samej karty (tautologia) i nie mogą zasłonić słabszego pokrycia ze stron lidera —
            // zostają w evidence ze źródłem price_list, liczone osobno (evidence_summary.price_list).
            $evidence = $this->evidence()->extract($lists, $this->evidenceDocs($member, $pages));
            $entries = [...$evidence['entries'], ...$facts['evidence']];
            $explicit = (int) $evidence['explicit'];
            $priceListEvidence = count($facts['evidence']);

            if ($this->writeNormsColumn($member, $lists['norms'])) {
                $runWrites['norms'] = $member->norms;
            }

            // kolory karty jako zbiór (karta dwubarwna to inny kolor niż jednobarwna) — do doboru zdjęcia i do usuwania
            // zdjęć w sprzecznym kolorze
            $memberColours = is_array($facts['colours'] ?? null)
                ? array_values(array_filter($facts['colours'], 'is_string'))
                : (($facts['colour'] ?? null) !== null ? [(string) $facts['colour']] : []);
            // zdjęcie w kolorze karty: adres z galerii stron lidera do pobrania albo kopia pliku lidera bez sieci
            if ($leaderProposal) {
                $pick = ['url' => null, 'copy_of' => null, 'reason' => 'lider skończył propozycją — bez plików z jego karty'];
            } else {
                $pageImageUrls = array_values(array_filter((array) ($leaderMeta['page_image_urls'] ?? []), 'is_string'));
                $leaderImages = ProductImage::query()
                    ->where('product_id', $leader->id)
                    ->whereNull('b2b_account_id')
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->all();
                // rdzeń modelu do nazwy pliku galerii
                $pick = $this->imagePicker()->pickFor($member, $memberColours, $pageImageUrls, $leaderImages, $memberKey?->stem);
            }
            $savedImages = [];
            $imageNote = $pick['reason'];
            // bez adresu w kolorze karty: zamiennik z innej karty modelu w partii przed kopią zdjęcia lidera
            $siblingCopy = $pick['url'] === null && $memberColours !== [] && ! $leaderProposal
                ? $this->siblingImageCopy($member, $memberColours, $batchId, $memberKey?->stem)
                : null;
            if ($siblingCopy !== null) {
                $savedImages = [$siblingCopy];
                $imageNote = ModelImagePicker::REASON_SIBLING_COPY;
            } elseif ($pick['url'] !== null) {
                $savedImages = $this->images->downloadMany($member, [$pick['url']], 1);
                if ($savedImages === []) {
                    $failure = $this->imageFailureSummary([$pick['url']]);
                    $imageNote .= ' — nie pobrano'.($failure !== '' ? ' ('.$failure.')' : '');
                    // adres z galerii nie pobrał się — najpierw zamiennik z innej karty modelu w partii, potem kopia zdjęcia
                    // lidera z tą samą regułą kolorów (pickFor bez galerii: lider bez koloru albo z tym samym zbiorem
                    // kolorów; inaczej nic)
                    $siblingCopy = $memberColours !== [] ? $this->siblingImageCopy($member, $memberColours, $batchId, $memberKey?->stem) : null;
                    $fallback = $siblingCopy === null
                        ? $this->imagePicker()->pickFor($member, $memberColours, [], $leaderImages ?? [], $memberKey?->stem)
                        : ['copy_of' => null];
                    if ($siblingCopy !== null) {
                        $savedImages = [$siblingCopy];
                        $imageNote = 'adres z galerii nie pobrał się — '.ModelImagePicker::REASON_SIBLING_COPY;
                    } elseif ($fallback['copy_of'] !== null) {
                        $copy = $this->copier()->copyImage($leader, $fallback['copy_of'], $member);
                        if ($copy !== null) {
                            $savedImages = [$copy];
                            $imageNote = 'adres z galerii nie pobrał się — kopia zdjęcia lidera';
                        }
                    }
                }
            } elseif ($pick['copy_of'] !== null) {
                $copy = $this->copier()->copyImage($leader, $pick['copy_of'], $member);
                if ($copy !== null) {
                    $savedImages = [$copy];
                } else {
                    $imageNote .= ' — pliku lidera nie ma na dysku albo karta go odrzuciła';
                }
            }
            $this->attemptLog()->add('image', $imageNote, urls: $pick['url'] !== null ? [$pick['url']] : []);

            // dokumenty lidera z internetu kopią (ten sam plik albo adres już na karcie = istniejący wiersz)
            $leaderDocIds = $leaderProposal ? [] : ProductDocument::query()
                ->where('product_id', $leader->id)
                ->whereNull('b2b_account_id')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(self::MAX_PRODUCT_DOCUMENTS)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $savedDocs = $leaderDocIds !== [] ? $this->copier()->copyDocuments($leader, $leaderDocIds, $member) : [];
            $this->assertBatchNotCancelled($batchId);

            $leaderGroup = is_array($leaderPayload['model_group'] ?? null) ? $leaderPayload['model_group'] : [];
            $members = (int) ($leaderGroup['members'] ?? 0);
            if ($members < 1 && $batchId !== null && self::batchItemsHaveModelColumns()) {
                $members = ProductEnrichmentBatchItem::query()->where('batch_id', $batchId)->where('model_leader_id', $leader->id)->count();
            }
            $payload = [
                ...$lists,
                'certificates' => CertificateLabels::relabel($lists['certificates'], $savedDocs),
                'source_urls' => array_values(array_filter((array) ($leaderPayload['source_urls'] ?? []), 'is_string')),
                'primary_source_url' => $primaryUrl,
                'primary_source_kind' => $primaryKind,
                'confidence' => (float) ($leaderPayload['confidence'] ?? 0),
                'from_cache' => false,
                'document_urls' => array_values(array_filter(array_map(
                    static fn ($d): ?string => is_string($d->source_url) ? $d->source_url : null,
                    $savedDocs
                ))),
                'identity' => $identity,
                'evidence' => $entries,
                'evidence_summary' => ['explicit' => $explicit, 'inferred' => (int) $evidence['inferred'], 'price_list' => $priceListEvidence],
                'completeness' => $evidence['completeness'],
                'model_group' => [
                    'key' => trim((string) ($leaderGroup['key'] ?? '')) !== '' ? (string) $leaderGroup['key'] : (string) ($memberKey?->key ?? ''),
                    'stem' => trim((string) ($leaderGroup['stem'] ?? '')) !== '' ? (string) $leaderGroup['stem'] : (string) ($memberKey?->stem ?? ''),
                    'family' => (string) ($leaderGroup['family'] ?? ($memberKey?->family ?? '')),
                    'leader_product_id' => (int) $leader->id,
                    'leader_version_id' => (int) $leaderVersion->id,
                    'members' => $members,
                    'shared' => true,
                ],
            ];
            foreach (['price_list_sources', 'dropped_norm_claims', 'unverified_claims'] as $key) {
                if (is_array($leaderPayload[$key] ?? null) && $leaderPayload[$key] !== []) {
                    $payload[$key] = $leaderPayload[$key];
                }
            }

            // Etap 2b: przy partii z force bez nowego zdjęcia stare zdjęcia z internetu w kolorach rozłącznych z kolorami
            // karty są usuwane po zapisie opisu (lista teraz — ślad i komunikat mówią prawdę; przy propozycji nic nie znika)
            $staleColourImages = $savedImages === [] && $previousWebFiles !== null
                ? $this->imagesInOtherColour($member, $memberColours, $previousWebFiles['images'])
                : [];
            $keptPreviousImages = $savedImages === [] && $previousWebFiles !== null
                && array_diff($previousWebFiles['images'], array_keys($staleColourImages)) !== [];
            if ($staleColourImages !== []) {
                $this->attemptLog()->add('image', self::staleColourNote($staleColourImages), urls: array_values($staleColourImages));
            }
            if ($keptPreviousImages) {
                $this->attemptLog()->add('image', 'nowego zdjęcia brak — zostaje poprzednie zdjęcie karty');
            }
            $trace = $this->attemptLog()->snapshot($member);
            $candidate = [
                'identity' => $identity,
                'evidence_count' => $explicit,
                'primary_source_url' => $primaryUrl,
                'manual_url' => $primaryUrl !== null && $member->isTrustedShopUrl($primaryUrl),
            ];
            $versionData = [
                'description' => $description,
                'enrichment_payload' => $payload,
                'enrichment_trace' => $trace,
                'packaging' => $fields['packaging'],
                'primary_source_url' => $primaryUrl,
                'batch_id' => $batchId,
            ];
            $saved = [
                'description' => $description,
                'enrichment_payload' => $payload,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
                'enrichment_error' => $savedImages === []
                    ? ($staleColourImages !== []
                        ? 'Opis OK, zdjęcie w innym kolorze usunięte, nowego brak'
                        : ($keptPreviousImages ? 'Opis OK, nowego zdjęcia nie udało się pobrać — zostaje poprzednie' : 'Opis OK, nie udało się pobrać zdjęcia'))
                        .' ('.$imageNote.').'
                    : null,
                'enrichment_trace' => $trace,
            ];
            if ($fields['packaging'] !== null) {
                $saved['packaging'] = $fields['packaging'];
            }
            // decyzja per karta pod blokadą (publishRun): twarda baza i słabszy werdykt → propozycja, karta wraca do stanu
            // sprzed przebiegu; inaczej zapis z pochodzeniem model_shared
            $version = $this->publishDescription($member, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, $candidate, $versionData, $saved, $before, runWrites: $runWrites, newSourceUrl: $primaryUrl);
            if ($version === null) {
                // nic nie znika przy propozycji — ślad wersji nie może mówić o usuniętym zdjęciu
                $versionData['enrichment_trace'] = self::traceWithStaleColourKept($versionData['enrichment_trace'], $staleColourImages);
                $this->keepAsProposal($member, $before, $batchId, $versionData, $this->lastRunDecision, $runWrites, $identity, $pages, $primaryUrl, $primaryKind, ProductDescriptionVersion::ORIGIN_MODEL_SHARED);

                return ApplyModelDescriptionJob::RESULT_PROPOSED;
            }
            $published = true;
            $this->dropPreviousWebFiles($member, $previousWebFiles, $savedImages, $savedDocs);
            if ($staleColourImages !== []) {
                $this->clearProductImages($member, array_keys($staleColourImages));
            }
            ReindexProductEmbeddingJob::dispatch($member->id, true);
            // te same teksty (sha256) co u lidera, wiersze per karta z werdyktem członka
            $this->recordSourceDocuments($member, $pages, $identity, $primaryUrl, $primaryKind, [], (int) $version->id);
            Log::info('Model description applied to member', [
                'product_id' => $member->id,
                'sku' => $member->sku,
                'leader_id' => $leader->id,
                'leader_version_id' => $leaderVersion->id,
                'version_id' => $version->id,
                'verdict' => $identity['verdict'],
                'image' => $pick['reason'],
            ]);

            return ApplyModelDescriptionJob::RESULT_PUBLISHED;
        } catch (Throwable $e) {
            // opis się nie zapisał — pliki dodane przez ten przebieg znikają, stare zostają; kartę ustawia wołający
            if (! $published) {
                try {
                    $this->dropWebFilesAddedSince($member, $before['web_files']);
                } catch (Throwable) {
                    // jak w enrichProduct — pliki sprzątnie kolejny przebieg
                }
            }
            throw $e;
        } finally {
            $this->liveProgress()->clear();
        }
    }

    private function enrichmentStatusForFailure(Throwable $e): string
    {
        if (! $e instanceof ProductSourcesNotFoundException) {
            return Product::ENRICHMENT_FAILED;
        }
        if ($this->searchFailedDueToEngineOutage($e->getMessage())) {
            return Product::ENRICHMENT_FAILED;
        }

        return Product::ENRICHMENT_MANUAL;
    }

    /**
     * Wyszukiwarka padła (limit, captcha, brak połączenia) — to nie znaczy,
     * że produkt nie ma karty w internecie. Taki przebieg wraca do kolejki,
     * zamiast kazać człowiekowi pisać opis z ręki.
     */
    private function searchFailedDueToEngineOutage(string $detail): bool
    {
        return SearchEngineOutage::matches($detail);
    }

    /**
     * Pierwszy komunikat świadczący o awarii wyszukiwarki — z podsumowania
     * fazy szukania albo z kroków „err” całego przebiegu (kolejne partie
     * kart i zmapowane sklepy szukają już po tym podsumowaniu).
     */
    private function engineOutageDetail(string $searchDetail): ?string
    {
        if ($this->searchFailedDueToEngineOutage($searchDetail)) {
            return $searchDetail;
        }
        // tylko błędy z tej próby: batch #293 brał komunikat prefetchu o zablokowanym SearXNG,
        // choć wyszukiwarka na żywo odpowiedziała — produkt szedł „do ponowienia” zamiast do ręki
        foreach ($this->attemptLog()->messagesOfType('err', includeReplayed: false) as $message) {
            if ($this->searchFailedDueToEngineOutage($message)) {
                return $message;
            }
        }

        return null;
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * @param  array<string, mixed>  $timing
     * @param  array<string, mixed>  $extra
     */
    private function logEnrichmentTiming(array $timing, float $started, ?string $error = null, array $extra = []): void
    {
        $payload = array_merge($timing, $extra, [
            'total_ms' => $this->elapsedMs($started),
        ]);
        if ($error !== null && $error !== '') {
            $payload['error'] = mb_substr($error, 0, 200);
        }
        Log::info('Product enrichment timing', $payload);
    }

    private function hasSkuCacheRow(Product $product): bool
    {
        $key = ProductEnrichmentCache::normalizeKey(
            (string) $product->manufacturer,
            (string) $product->sku
        );

        return ProductEnrichmentCache::query()
            ->where('manufacturer', $key['manufacturer'])
            ->where('sku', $key['sku'])
            ->exists();
    }

    private function forgetSkuCache(Product $product): void
    {
        $key = ProductEnrichmentCache::normalizeKey(
            (string) $product->manufacturer,
            (string) $product->sku
        );
        ProductEnrichmentCache::query()
            ->where('manufacturer', $key['manufacturer'])
            ->where('sku', $key['sku'])
            ->delete();
    }

    /**
     * Zdjęcia i dokumenty z internetu, które karta ma przed przebiegiem z force (bez plików z panelu B2B).
     *
     * @return array{images: list<int>, documents: list<int>}
     */
    private function webFileIds(Product $product): array
    {
        return [
            'images' => ProductImage::query()->where('product_id', $product->id)->whereNull('b2b_account_id')
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            'documents' => ProductDocument::query()->where('product_id', $product->id)->whereNull('b2b_account_id')
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        ];
    }

    /**
     * Usuwa zdjęcia i dokumenty sprzed przebiegu (webFileIds), których przebieg nie pobrał ponownie.
     * null przy liście nowych plików = tych plików nie ruszamy. Pusta lista nowych zdjęć też ich nie rusza: przebieg,
     * który nie pobrał żadnego zdjęcia (zapora ansell.com oddająca stronę zamiast pliku), kasował dobre zdjęcie karty
     * i zostawiał ją bez żadnego (plan 07.10.2026, B2). Zdjęcie ustępuje tylko nowemu zdjęciu.
     *
     * @param  array{images: list<int>, documents: list<int>}|null  $previous
     * @param  list<object>|null  $newImages
     * @param  list<object>|null  $newDocuments
     */
    private function dropPreviousWebFiles(Product $product, ?array $previous, ?array $newImages, ?array $newDocuments): void
    {
        if ($previous === null) {
            return;
        }
        $idsOf = static fn (array $rows): array => array_map(static fn (object $row): int => (int) ($row->id ?? 0), $rows);
        if ($newImages !== null && $newImages !== []) {
            $this->clearProductImages($product, array_values(array_diff($previous['images'], $idsOf($newImages))));
        }
        // Dokumenty inaczej niż zdjęcia: pusta lista nowych też kasuje stare z internetu. Certyfikat albo deklaracja
        // z poprzedniej (być może cudzej) strony nie może zostać przy nowym opisie — idzie do przetargów jako dowód
        // zgodności; brak pliku jest tu mniejszym złem niż cudzy plik (test_force_sync_reenriches_done_product).
        if ($newDocuments !== null) {
            $this->clearProductDocuments($product, array_values(array_diff($previous['documents'], $idsOf($newDocuments))));
        }
    }

    /**
     * Zdjęcia i dokumenty z internetu dodane od webFileIds() — przebieg z force, który nie zapisał opisu, nie zostawia
     * nowych plików obok starych.
     *
     * @param  array{images: list<int>, documents: list<int>}  $previous
     */
    private function dropWebFilesAddedSince(Product $product, array $previous): void
    {
        $now = $this->webFileIds($product);
        $this->clearProductImages($product, array_values(array_diff($now['images'], $previous['images'])));
        $this->clearProductDocuments($product, array_values(array_diff($now['documents'], $previous['documents'])));
    }

    /**
     * Atomowy zapis nowego opisu (etap 1) — jedno miejsce publikacji pełnego przebiegu i pamięci SKU. Pod blokadą
     * karty DescriptionVersionStore::publishRun decyduje ponownie (decide) na świeżym stanie karty; przy „publish” zapisuje
     * wersję published z danymi technicznymi przebiegu (`_version`: pliki z internetu dodane od $before, a gdy przebieg
     * zapisał normy producenta ($runWrites) — ich wartość sprzed przebiegu i zapisana; odrzucenie wersji w przeglądzie
     * z nich korzysta) i w tej samej transakcji kartę:
     * $cardUpdates + description_version_id w payloadzie + powód przeglądu z decyzji. Wyjątek przy zapisie karty cofa
     * też wersję. Przy „propose” nic nie zapisuje i oddaje null — decyzja w $lastRunDecision (wołający robi propozycję).
     *
     * @param  array<string, mixed>  $candidate  jak DescriptionVersionStore::decide
     * @param  array<string, mixed>  $versionData  pola wersji (DescriptionVersionStore::record)
     * @param  array<string, mixed>  $cardUpdates  kolumny karty
     * @param  array<string, mixed>  $before  DescriptionVersionStore::snapshot sprzed przebiegu
     * @param  (callable(): bool)|null  $stillValid  sprawdzenie pod blokadą przed zapisem karty; false = wersja cofnięta, wynik null
     * @param  array<string, mixed>  $runWrites  kolumna => wartość zapisana przez ten przebieg przed decyzją (norms, manufacturer_norms)
     * @param  string|null  $newSourceUrl  źródło nowego opisu (shopBaseYieldsTo)
     *
     * Etap 3, runda 3: marka „tylko od producenta” ze źródłem nowego opisu u producenta — wersja ze sklepu (bez ochrony:
     * nie wybór człowieka, nie B2B) przechodzi w superseded jako cofnięta (DescriptionVersionStore::supersedeAsWithdrawn,
     * karta bez zmian) w tej samej transakcji, pod blokadą, tuż przed decide — decide widzi kartę bez bazy i publikuje.
     * Propozycja mimo to (np. strona odrzucona), wyjątek albo anulowanie przed zapisem cofają też to (rollback) — karta
     * zostaje z dotychczasowym opisem i jego wersją.
     */
    private function publishDescription(
        Product $product,
        string $origin,
        array $candidate,
        array $versionData,
        array $cardUpdates,
        array $before,
        ?callable $stillValid = null,
        array $runWrites = [],
        ?string $newSourceUrl = null,
    ): ?ProductDescriptionVersion {
        // dane techniczne przebiegu (pliki, normy producenta) obok tych od wołającego (page_image_urls lidera modelu)
        $versionData[DescriptionVersionStore::META_KEY] = $this->versions()->versionMeta($product, $before, $runWrites)
            + (is_array($versionData[DescriptionVersionStore::META_KEY] ?? null) ? $versionData[DescriptionVersionStore::META_KEY] : []);
        $this->lastRunDecision = null;
        $abandoned = new RuntimeException('karta zmieniona w trakcie przebiegu — opis nie zapisany');
        $shopBaseYields = $this->shopBaseYieldsTo($product, $newSourceUrl);
        $keepShopBase = new RuntimeException('propozycja — wersja ze sklepu zostaje bazą');
        $keptDecision = null;
        try {
            $result = DB::transaction(function () use ($product, $candidate, $versionData, $cardUpdates, $stillValid, $abandoned, $origin, $shopBaseYields, $keepShopBase, &$keptDecision): array {
                $withdrawn = $shopBaseYields ? $this->supersedeShopBase($product) : null;
                $result = $this->publishRunWithCard($product, $candidate, $versionData, $cardUpdates, $stillValid, $abandoned, $origin);
                if ($withdrawn !== null && $result['version'] === null) {
                    // propozycja: cofnięcie wersji ze sklepu też wraca (rollback całej transakcji)
                    $keptDecision = $result['decision'];
                    throw $keepShopBase;
                }

                return $result;
            });
        } catch (Throwable $e) {
            if ($e === $keepShopBase && $keptDecision !== null) {
                $result = ['decision' => $keptDecision, 'version' => null];
            } else {
                // publishRun cofnął wersję i zapis karty — model w pamięci trzyma jednak atrybuty z nieudanego update();
                // bez odświeżenia zapis statusu błędu (catch w enrichProduct) zapisałby je razem z nim
                try {
                    $product->refresh();
                } catch (Throwable) {
                    // baza niedostępna — catch w enrichProduct i tak nie zapisze karty
                }
                if ($e !== $abandoned) {
                    throw $e;
                }

                return null;
            }
        }
        $this->lastRunDecision = $result['decision'];
        $this->lastRunVersion = $result['version'];

        return $result['version'];
    }

    /**
     * publishRun z zapisem karty (description_version_id, klucze stanu karty z obecnego wiersza, powód przeglądu
     * z decyzji) — część publishDescription, w jego transakcji.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $cardUpdates
     * @param  (callable(): bool)|null  $stillValid
     * @return array{decision: array{action: string, review_reason: string|null, reason: string}, version: ProductDescriptionVersion|null}
     */
    private function publishRunWithCard(
        Product $product,
        array $candidate,
        array $versionData,
        array $cardUpdates,
        ?callable $stillValid,
        RuntimeException $abandoned,
        string $origin,
    ): array {
        return $this->versions()->publishRun(
            $product,
            $candidate,
            $versionData,
            function (ProductDescriptionVersion $version, array $decision) use ($product, $cardUpdates, $stillValid, $abandoned): void {
                if ($stillValid !== null && ! $stillValid()) {
                    throw $abandoned;
                }
                $payload = is_array($cardUpdates['enrichment_payload'] ?? null) ? $cardUpdates['enrichment_payload'] : [];
                // Klucze stanu karty (scalone rozmiary, ślady B2B — DescriptionVersionStore::CARD_STATE_PAYLOAD_KEYS)
                // nie należą do opisu: zostają z obecnej karty (wiersz zablokowany w publishRun), jak w publish().
                // Payload przebiegu budowany od zera kasował je przy każdym pobraniu (np. merged_size_skus).
                $current = Product::query()->whereKey($product->id)->value('enrichment_payload');
                $current = is_string($current) ? json_decode($current, true) : $current;
                $keys = array_flip(DescriptionVersionStore::CARD_STATE_PAYLOAD_KEYS);
                $payload = array_diff_key($payload, $keys)
                    + (is_array($current) ? array_intersect_key($current, $keys) : []);
                $payload['description_version_id'] = (int) $version->id;
                $product->update([
                    ...$cardUpdates,
                    'enrichment_payload' => $payload,
                    'review_reason' => $decision['review_reason'],
                    'review_since' => $this->reviewSince($product, $decision['review_reason']),
                ]);
            },
            $origin,
        );
    }

    /**
     * Nowy opis gorszy od obecnego albo ze strony odrzuconej przez handlowca (DescriptionVersionStore::decide) —
     * zostaje wersją proposed do przeglądu, a karta wraca do stanu sprzed przebiegu: zdjęcia i pliki pobrane w tym
     * przebiegu znikają, normy i normy producenta zapisane przez przebieg przed decyzją ($runWrites) oraz status
     * wracają — każde tylko wtedy, gdy na karcie stoi dalej wartość z tego przebiegu (synchronizacja B2B albo handlowiec
     * mogli ją w międzyczasie zmienić i wtedy zostaje). Do komunikatu karty dochodzi informacja o propozycji. Opis, ślad
     * przebiegu i pamięć SKU bez zmian; dochodzi powód przeglądu. Źródła zapisane przy propozycji.
     *
     * @param  array{status: string|null, error: string|null, norms: string|null, manufacturer_norms: mixed, packaging: string|null, web_files: array{images: list<int>, documents: list<int>}}  $before
     * @param  array<string, mixed>  $versionData
     * @param  array{action: string, review_reason: string|null, reason: string}|null  $decision  decyzja spod blokady (publishRun)
     * @param  array<string, mixed>  $runWrites  kolumna => wartość zapisana przez ten przebieg (norms, manufacturer_norms)
     * @param  array<string, mixed>  $identity
     * @param  list<array<string, mixed>>  $sourcePages
     * @param  string  $origin  pochodzenie wersji: przebieg karty (enrichment) albo opis członka modelu (model_shared)
     */
    private function keepAsProposal(
        Product $product,
        array $before,
        ?int $batchId,
        array $versionData,
        ?array $decision,
        array $runWrites,
        array $identity,
        array $sourcePages,
        ?string $primarySourceUrl,
        ?string $primarySourceKind,
        string $origin = ProductDescriptionVersion::ORIGIN_ENRICHMENT,
    ): void {
        // pliki przebiegu znikają razem z propozycją — dane techniczne publikacji (`_version`) jej nie dotyczą
        unset($versionData[DescriptionVersionStore::META_KEY]);
        $decision ??= ['action' => 'propose', 'review_reason' => Product::REVIEW_WORSE_VERSION, 'reason' => 'karta zmieniona w trakcie przebiegu'];
        $version = $this->versions()->record(
            $product,
            ProductDescriptionVersion::STATUS_PROPOSED,
            $origin,
            [...$versionData, 'review_reason' => $decision['review_reason'], 'reason' => $decision['reason']],
        );
        $this->lastRunVersion = $version;
        $this->dropWebFilesAddedSince($product, $before['web_files']);
        $note = self::proposalNote($decision['review_reason']);
        DB::transaction(function () use ($product, $before, $batchId, $decision, $runWrites, $note): void {
            /** @var Product|null $current */
            $current = Product::query()->lockForUpdate()->find($product->id);
            if ($current === null) {
                return;
            }
            foreach (['norms', 'manufacturer_norms'] as $column) {
                if (array_key_exists($column, $runWrites) && self::sameColumnValue($column, $current->{$column}, $runWrites[$column])) {
                    $current->{$column} = $before[$column];
                }
            }
            // „w trakcie” ustawił ten przebieg (albo job tuż przed nim) — inny status to cudza zmiana, zostaje
            if ($current->enrichment_status === Product::ENRICHMENT_RUNNING) {
                [$status, $error] = $this->statusBeforeRun($current, $before, $batchId);
                $current->enrichment_status = $status;
                $current->enrichment_error = self::withProposalNote($error, $note);
            }
            $current->review_reason = $decision['review_reason'];
            $current->review_since = $this->reviewSince($current, $decision['review_reason']);
            // przez model — hak saving przelicza indeks wyszukiwania, gdy normy wróciły
            $current->save();
        });
        $product->refresh();
        $this->lastProposalNote = $note;
        Log::info('Product description kept as proposal', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'version_id' => $version->id,
            'review_reason' => $decision['review_reason'],
            'reason' => $decision['reason'],
        ]);
        $this->recordSourceDocuments($product, $sourcePages, $identity, $primarySourceUrl, $primarySourceKind, [], (int) $version->id);
    }

    /**
     * Przebieg skończył się bez zapisu opisu (wyjątek, anulowanie partii): kolumny norm zapisane przez przebieg przed
     * decyzją ($runWrites) wracają do stanu sprzed przebiegu — każda tylko wtedy, gdy na karcie stoi dalej wartość
     * z tego przebiegu (jak w keepAsProposal). Model w pamięci dostaje stan z bazy.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $runWrites
     */
    private function undoRunColumnWrites(Product $product, array $before, array $runWrites): void
    {
        if ($runWrites === []) {
            return;
        }
        DB::transaction(static function () use ($product, $before, $runWrites): void {
            /** @var Product|null $current */
            $current = Product::query()->lockForUpdate()->find($product->id);
            if ($current === null) {
                return;
            }
            foreach (['norms', 'manufacturer_norms'] as $column) {
                if (array_key_exists($column, $runWrites) && self::sameColumnValue($column, $current->{$column}, $runWrites[$column])) {
                    $current->{$column} = $before[$column];
                }
            }
            if ($current->isDirty()) {
                // przez model — hak saving przelicza indeks wyszukiwania
                $current->save();
            }
        });
        foreach (['norms', 'manufacturer_norms'] as $column) {
            $product->setAttribute($column, Product::query()->whereKey($product->id)->value($column));
            $product->syncOriginalAttribute($column);
        }
    }

    /**
     * Pamięć SKU nie zapisała opisu (karta chroniona albo zmieniona pod blokadą) — zdjęcia i pliki pobrane z wpisu
     * znikają, a kolumna norm wraca, jeśli stoi na niej dalej wartość z wpisu. Przebieg idzie dalej pełną ścieżką.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $runWrites
     */
    private function undoSkuCacheWrites(Product $product, array $before, array $runWrites): void
    {
        $this->dropWebFilesAddedSince($product, $before['web_files']);
        if (! array_key_exists('norms', $runWrites)) {
            return;
        }
        DB::transaction(static function () use ($product, $before, $runWrites): void {
            /** @var Product|null $current */
            $current = Product::query()->lockForUpdate()->find($product->id);
            if ($current !== null && self::sameColumnValue('norms', $current->norms, $runWrites['norms'])) {
                $current->norms = $before['norms'];
                $current->save();
            }
        });
        $product->refresh();
    }

    /** Informacja o propozycji dla karty i pozycji partii — z powodem przeglądu. */
    private static function proposalNote(?string $reviewReason): string
    {
        return self::PROPOSAL_NOTE_PREFIX.match ($reviewReason) {
            Product::REVIEW_REJECTED_SOURCE => ' (strona odrzucona przez handlowca).',
            Product::REVIEW_WORSE_VERSION => ' (gorszy od obecnego).',
            default => '.',
        };
    }

    /** Komunikat karty z informacją o propozycji: poprzedni komunikat zostaje, wcześniejsza informacja się nie dubluje. */
    private static function withProposalNote(?string $error, string $note): string
    {
        $previous = trim((string) preg_replace('/\s*'.preg_quote(self::PROPOSAL_NOTE_PREFIX, '/').'[^.]*\.?/u', '', (string) $error));

        return mb_substr($previous === '' ? $note : $previous.' '.$note, 0, 2000);
    }

    /**
     * Ta sama wartość kolumny karty. manufacturer_norms przez ManufacturerNormFacts::sameFacts — kolumna JSON może
     * wrócić z bazy z kluczami w innej kolejności (MySQL porządkuje klucze obiektu), a porównanie tekstu JSON dawało
     * wtedy „inna wartość” dla tej samej, zapisanej przez przebieg. Reszta (tekst) — dosłownie.
     */
    private static function sameColumnValue(string $column, mixed $current, mixed $written): bool
    {
        if ($column === 'manufacturer_norms') {
            return is_array($written) && ManufacturerNormFacts::sameFacts($current, $written);
        }

        return json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === json_encode($written, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Strony opisu, z których pochodzi zapisane zdjęcie (rola „image” w zapisie źródeł): adres pliku na liście zdjęć
     * strony (image_urls, trusted_image_urls), a gdy żadna strona go nie wymienia (adres z odpowiedzi modelu albo z puli
     * pobrania, wybrany po hoście strony opisu) — strona opisu z tego samego hosta.
     *
     * @param  list<array<string, mixed>>  $descPages
     * @param  list<object>  $savedImages
     * @return list<array<string, mixed>>
     */
    private function imageSourcePages(array $descPages, array $savedImages): array
    {
        $urls = [];
        foreach ($savedImages as $image) {
            $url = is_string($image->source_url ?? null) ? trim($image->source_url) : '';
            if ($url !== '') {
                $urls[$url] = true;
            }
        }
        if ($urls === []) {
            return [];
        }
        $out = [];
        foreach ($descPages as $page) {
            foreach ([...(array) ($page['trusted_image_urls'] ?? []), ...(array) ($page['image_urls'] ?? [])] as $url) {
                if (is_string($url) && isset($urls[$url])) {
                    $out[] = $page;

                    continue 2;
                }
            }
        }
        if ($out !== []) {
            return $out;
        }
        foreach ($descPages as $page) {
            if ($this->imagesOnDescriptionHosts(array_keys($urls), [$page]) !== []) {
                $out[] = $page;
            }
        }

        return $out;
    }

    /**
     * Status i komunikat karty sprzed przebiegu. Przebieg z kolejki zastaje kartę „w kolejce” albo „w trakcie” — wtedy
     * stan sprzed kolejki z pozycji partii (jak restoreAfterCancel); bez niego karta z opisem = gotowa, bez = bez opisu.
     *
     * @param  array{status: string|null, error: string|null}  $before
     * @return array{0: string, 1: string|null}
     */
    private function statusBeforeRun(Product $product, array $before, ?int $batchId): array
    {
        if (in_array($before['status'], self::RESTORABLE_STATUSES, true)) {
            return [(string) $before['status'], $before['error']];
        }
        if ($batchId !== null && self::batchItemsHavePreviousStatus()) {
            $item = ProductEnrichmentBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('product_id', $product->id)
                ->first(['previous_status', 'previous_error']);
            if ($item !== null && in_array($item->previous_status, self::RESTORABLE_STATUSES, true)) {
                return [(string) $item->previous_status, $item->previous_error];
            }
        }

        return $product->hasDescriptionText() ? [Product::ENRICHMENT_DONE, null] : [Product::ENRICHMENT_NONE, null];
    }

    /** Od kiedy karta czeka na przegląd: ten sam powód co dotąd zachowuje datę, nowy liczy się od teraz. */
    private function reviewSince(Product $product, ?string $reason): ?CarbonInterface
    {
        if ($reason === null) {
            return null;
        }

        return $product->review_reason === $reason && $product->review_since !== null ? $product->review_since : now();
    }

    /**
     * Strony, z których powstał opis: pierwsze pięć stron puli (tyle dostaje model) i strony z listy źródeł, każda
     * z tekstem surowym z dziennika przebiegu (przed filtrem stron) i po filtrze. Strona spoza fetch (blok katalogu
     * PDF, karta PDF producenta) nie ma tekstu sprzed filtra — jej tekst w puli jest tekstem źródła.
     *
     * @param  list<array<string, mixed>>  $pageSnippets
     * @param  list<string>  $sourceUrls
     * @param  list<array<string, mixed>>  $runPages
     * @return list<array{url: string, final_url: string, title: string, text: string, filtered_text: string, markup_codes: list<mixed>, norm_facts: list<mixed>}>
     */
    private function descriptionSourcePages(Product $product, array $pageSnippets, array $sourceUrls, array $runPages): array
    {
        $raw = [];
        foreach ($runPages as $page) {
            foreach (array_filter([(string) ($page['url'] ?? ''), (string) ($page['final_url'] ?? '')]) as $address) {
                $raw[Product::normalizeShopUrl($address)] = $page;
            }
        }
        foreach ($runPages as $page) {
            $address = (string) ($page['url'] ?? '');
            if ($address !== '') {
                $raw[Product::normalizeShopUrl($this->identity->preferredLocaleUrl($address, $product))] ??= $page;
            }
        }
        $wanted = [];
        foreach ($sourceUrls as $url) {
            if (is_string($url) && trim($url) !== '') {
                $wanted[Product::normalizeShopUrl($url)] = true;
            }
        }

        $out = [];
        $seen = [];
        $position = 0;
        foreach ($pageSnippets as $page) {
            $url = trim((string) ($page['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $key = Product::normalizeShopUrl($url);
            $localeKey = Product::normalizeShopUrl($this->identity->preferredLocaleUrl($url, $product));
            $inModelInput = $position++ < 5;
            if (isset($seen[$key]) || (! $inModelInput && ! isset($wanted[$key]) && ! isset($wanted[$localeKey]))) {
                continue;
            }
            $seen[$key] = true;
            $rawPage = $raw[$key] ?? $raw[$localeKey] ?? null;
            $filtered = trim((string) ($page['text'] ?? ''));
            $rawText = is_array($rawPage) ? trim((string) ($rawPage['text'] ?? '')) : '';
            $markup = is_array($rawPage['markup_codes'] ?? null) ? $rawPage['markup_codes'] : ($page['markup_codes'] ?? []);
            $normFacts = is_array($page['norm_facts'] ?? null) ? $page['norm_facts'] : ($rawPage['norm_facts'] ?? []);
            $out[] = [
                'url' => $url,
                'final_url' => (string) ($page['final_url'] ?? ($rawPage['final_url'] ?? '')),
                'title' => (string) ($rawPage['title'] ?? ($page['title'] ?? '')),
                'text' => $rawText !== '' ? $rawText : $filtered,
                'filtered_text' => $filtered,
                'markup_codes' => is_array($markup) ? array_values($markup) : [],
                'norm_facts' => is_array($normFacts) ? array_values($normFacts) : [],
            ];
        }

        return $out;
    }

    /**
     * Teksty źródeł dla dowodów (EvidenceExtractor): strony opisu surowe, potem po filtrze, ramki norm stron, normy
     * producenta zapisane na karcie i atrybuty z cennika — każdy tekst raz (po sha256).
     *
     * @param  list<array{text: string, filtered_text: string, norm_facts: list<mixed>}>  $sourcePages
     * @return list<array{sha256: string, text: string}>
     */
    private function evidenceDocs(Product $product, array $sourcePages): array
    {
        $docs = [];
        $add = static function (string $text) use (&$docs): void {
            $text = trim($text);
            if ($text !== '') {
                $docs[hash('sha256', $text)] ??= $text;
            }
        };
        foreach ($sourcePages as $page) {
            $add($page['text']);
        }
        foreach ($sourcePages as $page) {
            $add($page['filtered_text']);
        }
        foreach ($sourcePages as $page) {
            $add(self::labelValueLines($page['norm_facts']));
        }
        $add(self::labelValueLines(ManufacturerNormFacts::rows($product->manufacturer_norms)));
        $add(self::labelValueLines(is_array($product->price_list_attributes) ? $product->price_list_attributes : []));

        $out = [];
        foreach ($docs as $sha256 => $text) {
            $out[] = ['sha256' => (string) $sha256, 'text' => $text];
        }

        return $out;
    }

    /**
     * Pary etykieta–wartość jako wiersze „etykieta: wartość” — ramka norm ({label, value}) albo słownik atrybutów.
     *
     * @param  array<mixed>  $rows
     */
    private static function labelValueLines(array $rows): string
    {
        $lines = [];
        foreach ($rows as $key => $row) {
            if (is_array($row) && array_key_exists('label', $row)) {
                $lines[] = trim((string) $row['label'].(is_scalar($row['value'] ?? null) && (string) $row['value'] !== '' ? ': '.$row['value'] : ''));
            } elseif (is_scalar($row) && (string) $row !== '') {
                $lines[] = (is_string($key) ? $key.': ' : '').$row;
            } elseif (is_array($row) && $row !== []) {
                $lines[] = (is_string($key) ? $key.': ' : '').json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Zapis źródeł opisu (SourceDocumentStore, przy enrichment.store_sources) przypiętych do wersji opisu. Role: każda
     * strona — description; strona, z której wzięto zdjęcie — image; główne źródło u producenta z ramką norm (z niej
     * storeManufacturerPageNorms pisze manufacturer_norms) — norms. Werdykt strony głównego źródła = werdykt karty,
     * pozostałych liczony osobno. Błąd zapisu źródeł nie cofa zapisanego opisu — trafia do dziennika.
     *
     * @param  list<array<string, mixed>>  $sourcePages
     * @param  array<string, mixed>  $identity
     * @param  list<array<string, mixed>>  $imagePages
     */
    private function recordSourceDocuments(
        Product $product,
        array $sourcePages,
        array $identity,
        ?string $primarySourceUrl,
        ?string $primarySourceKind,
        array $imagePages,
        int $versionId,
    ): void {
        if (! config('enrichment.store_sources', true) || $sourcePages === []) {
            return;
        }
        try {
            $profile = $this->profiles()->for($product);
            $primary = $primarySourceUrl !== null && $primarySourceUrl !== '' ? Product::normalizeShopUrl($primarySourceUrl) : null;
            $imageUrls = [];
            foreach ($imagePages as $page) {
                $url = (string) ($page['url'] ?? '');
                if ($url !== '') {
                    $imageUrls[Product::normalizeShopUrl($url)] = true;
                }
            }
            $docs = [];
            foreach ($sourcePages as $page) {
                $key = Product::normalizeShopUrl($page['url']);
                $isPrimary = $primary !== null && in_array($primary, [
                    $key,
                    Product::normalizeShopUrl($this->identity->preferredLocaleUrl($page['url'], $product)),
                ], true);
                $roles = [ProductSourceDocument::ROLE_DESCRIPTION];
                if (isset($imageUrls[$key])) {
                    $roles[] = ProductSourceDocument::ROLE_IMAGE;
                }
                if ($isPrimary && $primarySourceKind === 'manufacturer' && $page['norm_facts'] !== []) {
                    $roles[] = ProductSourceDocument::ROLE_NORMS;
                }
                $docs[] = [
                    'url' => $page['url'],
                    'final_url' => $page['final_url'] !== '' ? $page['final_url'] : null,
                    'text' => $page['text'],
                    'filtered_text' => $page['filtered_text'],
                    'roles' => $roles,
                    'identity' => $isPrimary ? $identity : $this->sourceIdentity()->judgePage($product, $page, $profile),
                    'markup_codes' => $page['markup_codes'],
                    'norm_facts' => $page['norm_facts'],
                ];
            }
            $this->sources()->record($product, $docs, $versionId);
        } catch (Throwable $e) {
            Log::warning('Product source documents not stored', [
                'product_id' => $product->id,
                'version_id' => $versionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Zdjęcia znalezione w internecie ustępują nowemu przebiegowi. Zdjęć z witryny dostawcy to nie
     * dotyczy — dokładnie jak przy plikach: packshot producenta przedstawia ten wariant wyrobu,
     * a ponowne wzbogacanie wstawiłoby na jego miejsce zdjęcie wyłowione przy cudzej karcie.
     *
     * @param  list<int>|null  $onlyIds  null = wszystkie zdjęcia z internetu
     */
    private function clearProductImages(Product $product, ?array $onlyIds = null): void
    {
        if ($onlyIds === []) {
            return;
        }
        $images = ProductImage::query()
            ->where('product_id', $product->id)
            ->whereNull('b2b_account_id')
            ->when($onlyIds !== null, static fn ($q) => $q->whereIn('id', $onlyIds))
            ->get();
        $removed = false;
        foreach ($images as $image) {
            try {
                Storage::disk('public')->delete($image->path);
            } catch (Throwable) {
                // ignore missing file
            }
            $image->delete();
            $removed = true;
        }
        if ($removed) {
            $product->unsetRelation('images');
            ProductImage::resequence((int) $product->id);
        }
    }

    /**
     * Pliki znalezione w internecie ustępują nowej karcie produktu. Plików z panelu B2B to nie dotyczy:
     * przyszły od dostawcy razem z ceną, są dowodem pochodzenia danych karty i nie da się ich odtworzyć
     * z sieci — kolejne pobranie cennika pobrałoby je jeszcze raz niepotrzebnie.
     *
     * @param  list<int>|null  $onlyIds  null = wszystkie dokumenty z internetu
     */
    private function clearProductDocuments(Product $product, ?array $onlyIds = null): void
    {
        if ($onlyIds === []) {
            return;
        }
        $documents = ProductDocument::query()
            ->where('product_id', $product->id)
            ->whereNull('b2b_account_id')
            ->when($onlyIds !== null, static fn ($q) => $q->whereIn('id', $onlyIds))
            ->get();
        foreach ($documents as $doc) {
            try {
                Storage::disk('public')->delete($doc->path);
            } catch (Throwable) {
                // ignore missing file
            }
            $doc->delete();
        }
        $product->unsetRelation('documents');
    }

    /**
     * Opis z pamięci SKU. $before = stan karty sprzed przebiegu (DescriptionVersionStore::snapshot) do zapisu wersji;
     * null = liczony tutaj (wywołanie spoza enrichProduct).
     *
     * @param  array<string, mixed>|null  $before
     */
    private function applyFromSkuCache(Product $product, ?array $before = null): bool
    {
        // Opis z bieżącą wersją bazową (etap 1) nie ustępuje pamięci SKU — wpis nie niesie werdyktu ani dowodów, więc
        // nie da się sprawdzić, czy nie jest gorszy; karta idzie pełną ścieżką z porównaniem wersji.
        if ($this->versions()->hasProtectedPublished($product)) {
            return false;
        }
        $before ??= $this->versions()->snapshot($product);
        $key = ProductEnrichmentCache::normalizeKey(
            (string) $product->manufacturer,
            (string) $product->sku
        );
        $cache = ProductEnrichmentCache::query()
            ->where('manufacturer', $key['manufacturer'])
            ->where('sku', $key['sku'])
            ->first();

        if ($cache === null) {
            return false;
        }

        $payload = is_array($cache->enrichment_payload) ? $cache->enrichment_payload : [];
        // Wpis powstał ze strony, którą handlowiec odrzucił dla tej karty — pełna ścieżka (strona nie wróci jako źródło)
        if ($this->rejectedSourceKeys !== []) {
            $cacheSources = [
                $payload['primary_source_url'] ?? null,
                ...(is_array($cache->source_urls) ? $cache->source_urls : []),
                ...(is_array($payload['source_urls'] ?? null) ? $payload['source_urls'] : []),
            ];
            foreach ($cacheSources as $url) {
                if (is_string($url) && trim($url) !== '' && $this->isRejectedSourceUrl($url)) {
                    $this->attemptLog()->add('search', 'pamięć SKU pominięta — opis ze strony odrzuconej przez handlowca', urls: [$url]);

                    return false;
                }
            }
        }
        // Marka „tylko od producenta” (etap 3): wpis bierzemy tylko ze źródłem na hoście producenta, w katalogu PDF albo
        // pod adresem wskazanym przez człowieka — opis ze sklepu (albo bez znanego źródła) z pamięci SKU omijałby W2/W4.
        if ($this->identity->usesManufacturerSourcesOnly($product)) {
            $cacheSource = $payload['primary_source_url'] ?? null;
            if (! is_string($cacheSource) || trim($cacheSource) === '') {
                $cacheSource = null;
                foreach ([...(is_array($cache->source_urls) ? $cache->source_urls : []), ...(is_array($payload['source_urls'] ?? null) ? $payload['source_urls'] : [])] as $url) {
                    if (is_string($url) && trim($url) !== '') {
                        $cacheSource = $url;
                        break;
                    }
                }
            }
            if ($cacheSource === null || ! $this->isManufacturerSourceUrl($product, trim($cacheSource))) {
                $this->attemptLog()->add(
                    'search',
                    'pamięć SKU pominięta — marka tylko od producenta, wpis '.($cacheSource === null ? 'bez źródła' : 'ze źródła spoza stron producenta'),
                    urls: $cacheSource !== null ? [trim($cacheSource)] : []
                );

                return false;
            }
        }
        // Pewność 0 (albo jej brak) to wpis, w którym model nie dał opisu: przed audytem 22.09.2026
        // opisem zostawał wtedy tekst strony (CAPTCHA, cennik, baner cookies). Taki wpis wracał
        // do karty manual/failed ze statusem „done” — produkt idzie normalną ścieżką.
        if ((float) ($payload['confidence'] ?? 0) <= 0) {
            return false;
        }
        // wpis sprzed bezpiecznika w zwykłym wzbogacaniu może nieść „Typ zapięcia: brak danych w źródle”
        $payload = $this->withoutMissingDataListItems($payload);
        $payload['from_cache'] = true;
        $cacheDescription = ProductDescriptionText::plain((string) $cache->description);
        // Zrzut strony zapisany w cache przed tą kontrolą nie może się kopiować dalej —
        // produkt idzie wtedy normalną ścieżką i nowy opis nadpisuje wpis w cache.
        if ($this->looksLikeForeignOrPartsTableDump($cacheDescription)) {
            return false;
        }
        // Pusty albo szczątkowy wpis w cache dawał status „done” bez opisu — karta
        // wyglądała w panelu na gotową i nie wracała już do kolejki.
        if (! Product::isDescriptionText($cacheDescription)) {
            return false;
        }
        $cacheSpecs = ProductDescriptionText::dropDuplicatedListItems(
            $this->stringList($payload['specs'] ?? null),
            $cacheDescription
        );
        $payload['specs'] = $cacheSpecs;
        $payload['features'] = ProductDescriptionText::dropDuplicatedListItems(
            $this->stringList($payload['features'] ?? null),
            $cacheDescription
        );
        $payload['attributes'] = $this->bhpAttributes->normalize(
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null,
            [
                'materials' => $this->stringList($payload['materials'] ?? null),
                'norms' => $this->stringList($payload['norms'] ?? null),
                'specs' => $cacheSpecs,
                'certificates' => $this->stringList($payload['certificates'] ?? null),
                // kategoria-dowód: ścieżka dobrana automatem nie mówi, czym wyrób jest
                'category' => $product->categoryAsEvidence(),
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'description' => $cacheDescription,
                // kolumna norm to wynik poprzedniego wzbogacania, nie tożsamość — patrz payloadFromExtraction
                'norms_column' => '',
                // te same źródła co BhpAttributeNormalizer::forProduct — inaczej zapisana klasa nie zna cennika
                // ani tabelki dostawcy i bierze ją ze strony sklepu, która bywa kartą wariantu
                'shop_fields' => (string) ($product->shop_fields_summary ?? ''),
                'price_list' => is_array($product->price_list_attributes) ? $product->price_list_attributes : [],
            ]
        );
        $sized = $this->applyExtractedSizes($product, $payload['attributes'], $cacheSpecs, $cacheDescription);
        $payload['attributes'] = $sized['attributes'];
        $cachePackaging = $sized['packaging'];

        $rawImageUrls = is_array($cache->image_urls) ? $cache->image_urls : [];
        $imageUrls = [];
        foreach ($rawImageUrls as $u) {
            if (! is_string($u) || ! ProductImageDownloader::looksLikeImageUrl($u)) {
                continue;
            }
            if (! $this->identity->isTrustedPageImageUrl($u, $product)) {
                continue;
            }
            $imageUrls[] = $u;
        }

        // Cache ma tylko URL karty HTML / śmieci — pełne pobranie po zdjęcie.
        if ($rawImageUrls !== [] && $imageUrls === []) {
            return false;
        }

        if ($imageUrls !== []) {
            $this->images->downloadMany($product, $imageUrls, 1);
        }
        $docUrls = is_array($payload['document_urls'] ?? null) ? $payload['document_urls'] : [];
        $cacheDocs = [];
        if ($docUrls !== []) {
            $cacheDocs = $this->documents->downloadMany(
                $product,
                array_values(array_filter($docUrls, static fn ($u): bool => is_string($u))),
                3
            );
        }
        // Wpis cache sprzed 04.10.2026 niesie „Certyfikat producenta” i „CE” — etykiety od nowa z plików tej karty,
        // jak w pełnym przebiegu (CertificateLabels::relabel).
        $payload['certificates'] = CertificateLabels::relabel($this->stringList($payload['certificates'] ?? null), $cacheDocs);

        // kolumna norm za opisem z cache, jak w pełnym przebiegu — wcześniej ta ścieżka jej nie pisała wcale
        $runWrites = [];
        if ($this->writeNormsColumn($product, $this->stringList($payload['norms'] ?? null))) {
            $runWrites['norms'] = $product->norms;
        }
        $product->refresh();
        // Wpis bez werdyktu i dowodów nie nadpisuje chronionego opisu także wtedy, gdy bazę zapisał w międzyczasie inny
        // proces; pod blokadą zapisu karta musi mieć dalej ten sam opis, co tutaj.
        $seenDescription = $product->description;
        if ($this->versions()->hasProtectedPublished($product)) {
            $this->undoSkuCacheWrites($product, $before, $runWrites);

            return false;
        }
        $storedDescription = mb_substr($cacheDescription, 0, 10000);
        // powód przeglądu dotyczył poprzedniego opisu — nowy (z decyzji pod blokadą) ustawia publishDescription
        $cached = [
            'description' => $storedDescription,
            'enrichment_payload' => $payload,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
            'enrichment_error' => null,
            'enrichment_trace' => null,
        ];
        if ($cachePackaging !== null) {
            $cached['packaging'] = $cachePackaging;
        }
        $version = $this->publishDescription(
            $product,
            ProductDescriptionVersion::ORIGIN_SKU_CACHE,
            ['identity' => null, 'evidence_count' => null, 'primary_source_url' => $payload['primary_source_url'] ?? null],
            [
                'description' => $storedDescription,
                'enrichment_payload' => $payload,
                'packaging' => $cachePackaging,
            ],
            $cached,
            $before,
            static fn (): bool => Product::query()->whereKey($product->id)->value('description') === $seenDescription,
        );
        if ($version === null) {
            // pod blokadą: opis karty zmieniony w trakcie albo strona źródła wpisu odrzucona — pełna ścieżka z porównaniem
            $this->attemptLog()->add('search', 'pamięć SKU pominięta — karta zmieniła się w trakcie przebiegu');
            $this->undoSkuCacheWrites($product, $before, $runWrites);

            return false;
        }

        ReindexProductEmbeddingJob::dispatch($product->id, true);

        Log::info('Product enrichment from SKU cache', [
            'product_id' => $product->id,
            'sku' => $product->sku,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $imageUrls
     * @param  list<string>  $sourceUrls
     */
    private function storeSkuCache(
        Product $product,
        string $description,
        array $payload,
        array $imageUrls,
        array $sourceUrls,
    ): void {
        $key = ProductEnrichmentCache::normalizeKey(
            (string) $product->manufacturer,
            (string) $product->sku
        );

        ProductEnrichmentCache::query()->updateOrCreate(
            $key,
            [
                'description' => mb_substr($description, 0, 10000),
                'enrichment_payload' => $payload,
                'image_urls' => array_values(array_unique(array_slice($imageUrls, 0, 1))),
                'source_urls' => array_values(array_unique(array_slice($sourceUrls, 0, 5))),
            ]
        );
    }

    /**
     * Zdjęcie z karty sklepu dla ponawiania (products:retry-images): plik producenta nadal zasłania zapora,
     * a opis karty już jest — szukamy tylko zdjęcia, bez nowego przebiegu opisu.
     *
     * @return list<object>
     */
    public function imageFromShopCards(Product $product): array
    {
        // „Tylko producent i strony cennika” — sklepów spoza listy nie bierzemy, także po zdjęcie.
        if (app(PriceListCards::class)->sourceSettingsFor($product)?->onlyMode()) {
            return [];
        }
        // Dziennik przebiegu żyje w zakresie polecenia, a nie karty — bez tego kroki kart zlewałyby się do limitu.
        $this->attemptLog()->reset();
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $sourceUrls = array_values(array_filter(
            (array) ($payload['source_urls'] ?? []),
            static fn ($url): bool => is_string($url) && $url !== ''
        ));

        return $this->tryImagesFromOtherCards(
            $product,
            [],
            $sourceUrls,
            $this->manufacturers->domainsFor($product),
            sourceRefused: true,
        );
    }

    /**
     * Gdy pierwsza karta nie da ściągalnego zdjęcia — do 5 innych sklepów, bez zrzutu strony.
     *
     * Źródło odmówiło pliku (zapora ansell.com, 403/429) — wyniki wyszukiwania kończą wtedy zwykle na karcie
     * producenta i sklepów w nich nie ma. Kolejne partie, każda tylko gdy poprzednia nie dała zdjęcia: karty sklepów
     * z lokalnego indeksu, potem jedno zapytanie o sklepy bez domeny producenta.
     *
     * @param  list<array{url?: string, title?: string, snippet?: string}>  $searchResults
     * @param  list<string>  $usedPageUrls
     * @param  list<string>  $mfrDomains
     * @return list<object>
     */
    private function tryImagesFromOtherCards(
        Product $product,
        array $searchResults,
        array $usedPageUrls,
        array $mfrDomains,
        bool $sourceRefused = false,
    ): array {
        $tried = [];
        foreach ($usedPageUrls as $url) {
            $key = mb_strtolower(trim($url));
            if ($key !== '') {
                $tried[$key] = true;
            }
        }

        $batches = [
            'wyniki wyszukiwania' => fn (): array => $searchResults,
        ];
        // „Tylko producent i strony cennika”: sklepy z indeksu i wyszukiwarki są spoza listy cennika
        if ($sourceRefused && ! $this->listSources?->onlyMode()) {
            $batches['indeks sklepów'] = fn (): array => $this->search->moreCatalogHits($product, array_keys($tried));
            $batches['sklepy bez strony producenta'] = fn (): array => $this->search->shopCardsForImage($product, $mfrDomains);
        }
        foreach ($batches as $label => $rows) {
            $candidates = $this->shopImageCandidates($product, $rows(), $mfrDomains, $tried);
            if ($candidates === []) {
                continue;
            }
            $saved = $this->imageFromCandidateCards($product, $candidates, strict: $sourceRefused);
            if ($saved !== []) {
                if ($sourceRefused) {
                    $this->attemptLog()->add(
                        'image',
                        'zdjęcie z karty sklepu ('.$label.') — plik producenta zablokowany',
                        urls: [(string) ($saved[0]->source_url ?? '')]
                    );
                }

                return $saved;
            }
        }

        return [];
    }

    /**
     * Do 5 kart sklepów spoza $tried — bez producenta, wykluczonych hostów (nasz sklep), obrazków i PDF-ów.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $mfrDomains
     * @param  array<string, true>  $tried  uzupełniane o wybrane adresy
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function shopImageCandidates(Product $product, array $rows, array $mfrDomains, array &$tried): array
    {
        $candidates = [];
        // po drugiej próbie $searchResults niesie też karty z indeksu i sklepów — bez filtra wykluczonych hostów
        foreach ($this->dropBlockedSourceHosts($rows, $product) as $row) {
            $url = (string) ($row['url'] ?? '');
            $key = mb_strtolower($url);
            if ($url === '' || isset($tried[$key]) || ! str_starts_with($url, 'http')) {
                continue;
            }
            if (ProductImageDownloader::looksLikeImageUrl($url)
                || ProductDocumentDownloader::looksLikeDocumentUrl($url)) {
                continue;
            }
            if ($this->manufacturers->isManufacturerUrl($url, $product, $mfrDomains)) {
                continue;
            }
            $tried[$key] = true;
            $candidates[] = [
                'url' => $url,
                'title' => (string) ($row['title'] ?? ''),
                'snippet' => (string) ($row['snippet'] ?? ''),
            ];
            if (count($candidates) >= 5) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * $strict — zdjęcie zamiast zablokowanego pliku producenta (sourceRefused). 05.10.2026 na ok. 100 takich zdjęć
     * cztery były cudze: logo witryny i PU610 jako og:image potwierdzonej karty (ProductImageCandidateVerifier,
     * trustStructured), 08-354 przy 08352 (imageUrlNamesForeignGloveModel — dla wszystkich dróg) i jeden plik
     * na Ringers R169SD i R840VP (imageOnAnotherModelCard).
     *
     * @param  list<array{url: string, title: string, snippet: string}>  $candidates
     * @return list<object>
     */
    private function imageFromCandidateCards(Product $product, array $candidates, bool $strict = false): array
    {
        foreach ($candidates as $row) {
            $fetched = $this->pages->fetch([$row], (string) $product->sku, 1, [], $product);
            // etap 3 (W1): strona innego wyrobu marki albo ze sprzeczną cechą nie daje zdjęcia
            $pages = $this->withoutForeignOrConflictingPages($product, $this->keepConfirmedCardPages($product, $fetched['pages']));
            if ($pages === []) {
                continue;
            }
            $urls = $this->imageVerifier->select(
                $product,
                // og:image bez kodu nie przechodzi tu z automatu (trustStructured), więc musi być wśród kandydatów
                // do modelu wizyjnego — i to na początku: cas-technik.eu ma packshot „48-501.jpg” tylko jako og:image,
                // a lista obrazków strony to głównie grafiki menu (EDGE 48501, 05.10.2026)
                $strict
                    ? array_values(array_unique([...$fetched['trusted_image_urls'], ...$fetched['image_urls']]))
                    : $fetched['image_urls'],
                $pages,
                3,
                $fetched['trusted_image_urls'],
                trustStructured: ! $strict,
            );
            $hadImages = $fetched['image_urls'] !== [] || $fetched['trusted_image_urls'] !== [];
            if ($urls === [] && ! $hadImages && ! $strict) {
                $urls = $this->cardImagesAfterConfirmation(
                    $fetched['trusted_image_urls'],
                    $fetched['image_urls'],
                    $pages,
                    $product
                );
            }
            $picked = $this->pickPrimaryImageUrls(
                $urls,
                [],
                (string) $product->sku,
                (string) $product->name,
                $product,
            );
            if ($strict) {
                $picked = array_values(array_filter(
                    $picked,
                    fn (string $url): bool => ! $this->imageOnAnotherModelCard($url, $product)
                ));
            }
            // etap 3 (W5, decyzja właściciela 08.10.2026: zdjęcie ze sklepu przy markach ścisłych tylko po tych filtrach)
            $picked = $this->withoutForeignImages($product, $picked);
            $saved = $this->images->downloadMany($product, $picked, 1);
            if ($saved !== []) {
                return $saved;
            }
        }

        return [];
    }

    /**
     * Ten sam plik ze sklepu stoi już na karcie innego modelu (05.10.2026: jeden obrazek imagedelivery.net na Ringers
     * R169SD i R840VP) — sklep pokazuje wspólne zdjęcie albo nie to, więc nie jest dowodem dla żadnej z kart. Warianty
     * rozmiaru i szerokości tego samego modelu (HyFlex 11250 N/W/XW, 11-250) mogą mieć wspólne zdjęcie.
     */
    private function imageOnAnotherModelCard(string $url, Product $product): bool
    {
        $keys = array_values(array_unique([$url, ProductImageDownloader::preferFullSizeUrl($url)]));
        $others = ProductImage::query()
            ->whereIn('source_url', $keys)
            ->where('product_id', '!=', $product->id)
            ->distinct()
            ->limit(20)
            ->pluck('product_id');
        if ($others->isEmpty()) {
            return false;
        }
        foreach (Product::query()->whereKey($others)->get() as $other) {
            if (! $this->identity->sameGloveModel($product, $other)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Karta już potwierdzona opisem — bierz og:image / galerię z tej strony,
     * bez SKU w nazwie pliku i bez Vision.
     *
     * @param  list<string>  $trusted
     * @param  list<string>  $all
     * @param  list<array{url?: string, text?: string}>  $pages
     * @return list<string>
     */
    /**
     * Czy któraś z potwierdzonych kart niesie w adresie kod produktu.
     *
     * Gdy opis nie potwierdził produktu, zdjęcie zostaje ostatnią rzeczą, jaką
     * z karty bierzemy — musi więc pochodzić z karty związanej z produktem
     * mocniej niż pospolitym słowem. „microflex-93-833” i „ih-72286-10” niosą
     * kod i dają dobre zdjęcia mimo pustego opisu; „mata-monotone-150cm-x-mb”
     * pasowała do pasa „GREY PES BLT … 150CM” samym „150cm” i dała mu zdjęcie
     * osłony kabli. Wskazany ręcznie adres sklepu zostaje zaufany bez tego
     * warunku — to człowiek wybrał tę stronę.
     *
     * @param  list<array{url?: string, title?: string}>  $pages
     */
    private function cardsCarryProductCode(array $pages, Product $product): bool
    {
        if ($product->trustedShopUrl() !== null) {
            return true;
        }

        return $this->codedCardPages($pages, $product) !== [];
    }

    /**
     * Karty, które niosą kod wyrobu w adresie albo tytule (jak cardsCarryProductCode).
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function codedCardPages(array $pages, Product $product): array
    {
        return array_values(array_filter($pages, function (array $page) use ($product): bool {
            $hay = mb_strtolower(((string) ($page['url'] ?? '')).' '.((string) ($page['title'] ?? '')));

            return $hay !== ' ' && $this->identity->hayHasProductCode($hay, $product);
        }));
    }

    /**
     * Wskazana karta Shoper ma packshot, ale og:description to newsletter —
     * zdjęcie zapisujemy mimo odrzuconego opisu.
     *
     * @param  array{image_urls?: list<string>, trusted_image_urls?: list<string>}  $fetched
     * @param  list<array{url?: string}>  $pages
     * @return list<ProductImage>
     */
    /**
     * „obrazek za mały (190x417) ×3” — powód odrzucenia wybranych zdjęć trafia do
     * komunikatu i przebiegu; wcześniej każda porażka wyglądała jak „błędne URL”.
     *
     * @param  list<string>  $urls
     */
    private function imageFailureSummary(array $urls): string
    {
        $failures = $this->images->lastFailures();
        if ($urls === [] || $failures === []) {
            return '';
        }
        $counts = [];
        foreach ($failures as $url => $reason) {
            $key = mb_strtolower(trim($reason));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $parts = [];
        foreach (array_slice($counts, 0, 2, true) as $reason => $n) {
            $parts[] = $reason.($n > 1 ? ' ×'.$n : '');
        }
        $summary = implode(', ', $parts);
        $this->attemptLog()->add('image', 'nie pobrano: '.$summary, urls: array_slice(array_keys($failures), 0, 3));

        return $summary;
    }

    private function downloadImagesFromFetchedCards(Product $product, array $fetched, array $pages): array
    {
        $trusted = is_array($fetched['trusted_image_urls'] ?? null) ? $fetched['trusted_image_urls'] : [];
        $all = is_array($fetched['image_urls'] ?? null) ? $fetched['image_urls'] : [];
        if ($product->trustedShopUrl() === null) {
            // Tylko zdjęcia kart z kodem wyrobu — nie wspólna pula wszystkich pobranych stron. 05.10.2026 kod dała
            // karta labproinc.com „klngd-a40-overboot-white-univ-98800”, a zdjęcie przyszło z pobranej obok strony
            // icd.pl „szybki do przyłbic ESAB Savage A40” (karta KleenGuard A40 #8662 dostała szybkę spawalniczą).
            $own = $this->imagesFromDescriptionPages($this->codedCardPages($pages, $product));
            $trusted = $own['trusted'];
            $all = $own['all'];
        }
        if ($trusted === [] && $all === []) {
            return [];
        }
        if ($product->trustedShopUrl() === null && $pages === []) {
            return [];
        }
        // etap 3 (W5): bez zdjęć z kodem innej karty marki i ze sprzeczną cechą bezpieczeństwa
        $urls = $this->withoutForeignImages($product, $this->cardImagesAfterConfirmation($trusted, $all, $pages, $product));
        if ($urls === []) {
            return [];
        }

        return $this->images->downloadMany(
            $product,
            $this->pickPrimaryImageUrls(
                $urls,
                [],
                (string) $product->sku,
                (string) $product->name,
                $product
            ),
            1
        );
    }

    private function cardImagesAfterConfirmation(array $trusted, array $all, array $pages, Product $product): array
    {
        $usable = [];
        foreach (array_merge($trusted, $all) as $url) {
            if (! is_string($url) || $this->isJunkImageUrl($url)
                || ! ProductImageDownloader::looksLikeImageUrl($url)
                || $this->identity->imageUrlMentionsForeignBrand($url, $product)
                || $this->identity->imageUrlHasForeignType($url, $product)) {
                continue;
            }
            $usable[] = $url;
        }
        $trustedClean = [];
        foreach ($trusted as $url) {
            if (is_string($url) && in_array($url, $usable, true)) {
                $trustedClean[] = $url;
            }
        }
        if ($trustedClean !== []) {
            return array_values(array_unique(array_slice(ProductImageDownloader::packshotsFirst($trustedClean), 0, 3)));
        }

        $hosts = [];
        foreach ($pages as $page) {
            $host = mb_strtolower((string) (parse_url((string) ($page['url'] ?? ''), PHP_URL_HOST) ?? ''));
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        $sameHost = [];
        $other = [];
        foreach ($usable as $url) {
            $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            if ($host !== '' && isset($hosts[$host])) {
                $sameHost[] = $url;
            } else {
                $other[] = $url;
            }
        }
        $chosen = $sameHost !== [] ? $sameHost : $other;

        return array_values(array_unique(array_slice(ProductImageDownloader::packshotsFirst($chosen), 0, 3)));
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    private function copyPageMeta(array $from, array $to): array
    {
        foreach (['option_sizes', 'accessories', 'image_urls', 'trusted_image_urls', 'norm_facts', 'document_urls', 'markup_codes'] as $key) {
            if (isset($from[$key]) && is_array($from[$key])) {
                $to[$key] = $from[$key];
            }
        }
        // adres po przekierowaniach — werdykt tożsamości szuka strony źródła także pod nim
        if (isset($from['final_url']) && is_string($from['final_url']) && $from['final_url'] !== '') {
            $to['final_url'] = $from['final_url'];
        }

        return $to;
    }

    /**
     * @param  list<array{url?: string, text?: string}>  $pages
     * @param  list<string>  $sourceUrls
     * @return list<array{url?: string, text?: string}>
     */
    private function pagesForDescriptionImages(array $pages, array $sourceUrls): array
    {
        $wanted = [];
        foreach ($sourceUrls as $url) {
            $key = mb_strtolower(trim($url));
            if ($key !== '') {
                $wanted[$key] = true;
            }
        }
        if ($wanted === []) {
            return $pages;
        }
        $matched = [];
        foreach ($pages as $page) {
            $key = mb_strtolower((string) ($page['url'] ?? ''));
            if (isset($wanted[$key])) {
                $matched[] = $page;
            }
        }

        return $matched !== [] ? $matched : $pages;
    }

    /**
     * @param  list<array{url?: string, image_urls?: list<string>, trusted_image_urls?: list<string>}>  $pages
     * @return array{all: list<string>, trusted: list<string>}
     */
    private function imagesFromDescriptionPages(array $pages): array
    {
        $all = [];
        $trusted = [];
        foreach ($pages as $page) {
            foreach ($page['trusted_image_urls'] ?? [] as $url) {
                if (is_string($url) && $url !== '') {
                    $trusted[] = $url;
                    $all[] = $url;
                }
            }
            foreach ($page['image_urls'] ?? [] as $url) {
                if (is_string($url) && $url !== '') {
                    $all[] = $url;
                }
            }
        }

        return [
            'all' => array_values(array_unique($all)),
            'trusted' => array_values(array_unique($trusted)),
        ];
    }

    /**
     * @param  list<string>  $urls
     * @param  list<array{url?: string}>  $pages
     * @return list<string>
     */
    private function imagesOnDescriptionHosts(array $urls, array $pages): array
    {
        $hosts = [];
        foreach ($pages as $page) {
            $host = mb_strtolower((string) (parse_url((string) ($page['url'] ?? ''), PHP_URL_HOST) ?? ''));
            $host = preg_replace('/^www\./u', '', $host) ?? $host;
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        $out = [];
        foreach ($urls as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }
            $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            $host = preg_replace('/^www\./u', '', $host) ?? $host;
            if ($host !== '' && isset($hosts[$host])) {
                $out[] = $url;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<array{url?: string}>  $pages
     */
    private function imageUrlMatchesDescriptionPages(string $url, array $pages): bool
    {
        return $this->imagesOnDescriptionHosts([$url], $pages) !== [];
    }

    private function pickPrimaryImageUrls(array $allUrls, mixed $llmUrls, string $sku, string $name, ?Product $product = null): array
    {
        $scored = [];
        $skuNorm = mb_strtolower(trim($sku));
        $nameBits = array_values(array_filter(preg_split('/[\s\-®™]+/u', mb_strtolower($name)) ?: [], static fn ($w): bool => mb_strlen($w) >= 4));
        // Kolory karty z nazwy — tylko marka z opisem wspólnym dla modelu (etap 2, Coba: strona modelu pokazuje zwykle
        // czarny, a nazwy plików galerii niosą kolor). U innych marek nazwa pliku bywa „glove-on-white-background”,
        // „navy-yellow” przy karcie żółto-granatowej — kara zostawiała kartę bez zdjęcia (Portwest/Mascot/JHK). Zbiory:
        // karta dwubarwna to inny kolor niż jednobarwna. Nazwa bez koloru — bez tej reguły.
        // te same kolory karty co picker i usuwanie zdjęć (fakty z cennika, zapasowo nazwa)
        $cardColours = $product !== null && $this->sharesModelDescription($product) ? $this->cardColours($product) : [];

        $push = static function (string $url, int $bonus) use (&$scored): void {
            $scored[$url] = max($scored[$url] ?? 0, $bonus);
        };

        // najpierw URL z HTML karty — wiarygodniejsze niż zgadywanie LLM
        foreach ($allUrls as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                if ($product !== null && $this->identity->imageUrlMentionsForeignBrand($url, $product)) {
                    continue;
                }
                $push($url, 40);
            }
        }
        if (is_array($llmUrls)) {
            foreach ($llmUrls as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    // LLM często zmyśla obce JPG — bez śladu SKU/modelu odrzuć
                    if ($product !== null && ! $this->identity->imageUrlMentionsProduct($url, $product)) {
                        continue;
                    }
                    $push($url, 15);
                }
            }
        }

        $skuTokens = [];
        if (preg_match('/\d{1,2}-\d{3}/', $skuNorm, $m)) {
            $skuTokens[] = $m[0];
            $skuTokens[] = str_replace('-', '', $m[0]);
        }

        $ranked = [];
        foreach ($scored as $url => $base) {
            if ($this->isJunkImageUrl($url) || ! ProductImageDownloader::looksLikeImageUrl($url)) {
                continue;
            }
            // Kandydaci przeszli już ProductImageCandidateVerifier (SKU / zaufane
            // strukturalne / AI Vision) — ponowny wymóg SKU w URL odrzucałby
            // prawidłowe CDN-y dystrybutorów (np. RS z kodem Y…).
            $u = mb_strtolower($url);
            // miniatury WP (-80x80 …)
            if (preg_match('/-(\d{2,4})x(\d{2,4})\.(jpe?g|png|webp)(\?|$)/i', $u, $wm)
                && (((int) $wm[1] < 400) || ((int) $wm[2] < 400))) {
                continue;
            }
            if (ProductImageDownloader::isSmallShoperCacheUrl($u)) {
                continue;
            }
            // inny kod art. w pliku (7-003 przy SKU 9-084)
            if ($skuTokens !== [] && preg_match_all('/\b(\d{1,2}-\d{3})\b/', $u, $foundCodes)) {
                $wrong = false;
                foreach ($foundCodes[1] as $found) {
                    $ok = in_array($found, $skuTokens, true)
                        || in_array(str_replace('-', '', $found), $skuTokens, true)
                        || str_contains($skuNorm, $found);
                    if (! $ok) {
                        $wrong = true;
                        break;
                    }
                }
                if ($wrong) {
                    continue;
                }
            }
            $score = $base;
            if ($skuNorm !== '' && str_contains($u, $skuNorm)) {
                $score += 100;
            }
            foreach ($skuTokens as $token) {
                if (str_contains($u, $token)) {
                    $score += 80;
                    break;
                }
            }
            foreach ($nameBits as $bit) {
                if (str_contains($u, $bit)) {
                    $score += 15;
                }
            }
            if (str_contains($u, 'glove') || str_contains($u, 'rekaw') || str_contains($u, 'maxi') || str_contains($u, 'ringers') || str_contains($u, 'ansell')) {
                $score += 25;
            }
            // Drupal/ATG PIM / uvex shop-media — prawdziwe pliki
            if (str_contains($u, 'pim/products') || str_contains($u, 'sites/default/files') || str_contains($u, 'media/catalog/product') || str_contains($u, 'shop-media')) {
                $score += 90;
            }
            if (str_contains($u, 'menu-') || str_contains($u, 'menue-') || str_contains($u, 'favicon')) {
                continue;
            }
            // zmyślone /images/products/ z LLM — nie galeria RedCart
            if (preg_match('#(?<!/templates)/images/products/#', $u) === 1
                && ! str_contains($u, 'sites/default')) {
                $score -= 80;
            }
            if ($cardColours !== []) {
                $fileColours = ColourWords::allInUrl($url);
                if (ColourWords::sameSet($fileColours, $cardColours)) {
                    $score += 30;
                } elseif ($fileColours !== [] && array_intersect($fileColours, $cardColours) === []) {
                    // plik mówi o kolorach i żaden nie jest kolorem karty — inny wariant, odpada (etap 2b: −50 nie
                    // odrzucało — lider szarego Orthomatu pobrał „black-1.jpg”)
                    continue;
                }
            }
            if ($score >= 20) {
                $ranked[] = ['url' => $url, 'score' => $score];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        // Zdjęcia z zastosowania zbierają punkty za słowa z nazwy („chemical”) i wyprzedzały
        // packshot tej samej karty — a pobierane jest tylko pierwsze.
        return array_slice(ProductImageDownloader::packshotsFirst(array_map(
            static fn (array $row): string => $row['url'],
            $ranked
        )), 0, 4);
    }

    /**
     * Klucz karty bez członu językowego: „ansell.com/pl/pl/products/x” i „ansell.com/gb/en/products/x”
     * to jedna i ta sama karta. Pusty string, gdy adres nie jest kartą z wersjami językowymi —
     * takich nie zwijamy, bo dwa różne adresy u dystrybutora bywają dwoma różnymi wyrobami.
     */
    private function localeInsensitiveCardKey(string $url): string
    {
        if (preg_match('#^https?://(?:www\.)?ansell\.com/[^/]+/[^/]+/products/(.+)$#i', $url, $m) !== 1) {
            return '';
        }

        return 'ansell:'.mb_strtolower(rtrim($m[1], '/'));
    }

    /**
     * Opis: polska karta producenta pierwsza, potem sklepy / dystrybutorzy, a obcojęzyczne
     * karty producenta na końcu (bywają cienkie).
     *
     * @param  list<array{url: string, title?: string, snippet?: string}>  $results
     * @param  list<string>  $mfrDomains
     * @return list<array{url: string, title?: string, snippet?: string}>
     */
    private function rankResultsForDescription(array $results, Product $product, array $mfrDomains = []): array
    {
        $retailers = $this->retailerHostList();
        // ranga czytana RAZ: komparator woła się O(n log n) razy, zapytanie w środku byłoby drogie
        $ranks = CatalogHostPriority::map();

        $rows = [];
        foreach ($results as $position => $row) {
            $rows[] = [
                'row' => $row,
                // remis rozstrzyga kolejność z wyszukiwarki, a nie przypadek sortowania
                'position' => $position,
                'score' => $this->descriptionSourceScore((string) ($row['url'] ?? ''), $product, $mfrDomains, $retailers, $ranks, (string) ($row['title'] ?? '')),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [-$a['score'], $a['position']] <=> [-$b['score'], $b['position']]);

        // Ta sama karta w dwóch wersjach językowych to dla modelu dwie osobne strony: opis
        // wychodził po polsku i po angielsku naraz, a te same fakty trafiały do specyfikacji
        // w dwóch tłumaczeniach („Gramatura” i „Waga” tej samej wartości). Zostaje jedna —
        // po sortowaniu pierwsza, czyli wersja polska.
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = $this->localeInsensitiveCardKey((string) ($row['row']['url'] ?? ''));
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            if ($key !== '') {
                $seen[$key] = true;
            }
            $out[] = $row['row'];
        }

        return $out;
    }

    /**
     * @param  list<string>  $mfrDomains
     * @param  list<string>  $retailers
     * @param  array<string, int>  $ranks  ręczna ranga domeny (1 = najwyżej) z panelu
     * @param  string  $title  tytuł wyniku — strona cennika z pliku dostaje pierwszeństwo tylko z kodem karty w adresie
     *                         albo tytule (jak hitsCarryProductCode)
     */
    private function descriptionSourceScore(string $url, Product $product, array $mfrDomains, array $retailers, array $ranks = [], string $title = ''): int
    {
        if ($url === '') {
            return -100;
        }
        if ($product->isHintedShopUrl($url)) {
            return 100;
        }
        // host cennika z pliku spoza znanych domen producenta to sklep — nawet gdy nazwa hosta wygląda jak marka
        $listPosition = $this->listSiteShopPosition($url, $product);
        if ($listPosition === null && $this->manufacturers->isManufacturerUrl($url, $product, $mfrDomains)) {
            $u = mb_strtolower($url);
            if (str_contains($u, '/blogs/') || str_contains($u, '/blog/')) {
                return -80;
            }
            // Polska karta Ansella jest pełna i to ona ma być źródłem opisu. Przy 8 punktach
            // przegrywała z dowolnym sklepem (20), a nawet z nieznaną domeną (10), i przy
            // pobieraniu trzech pierwszych adresów w ogóle nie trafiała do puli opisu —
            // stąd karty AlphaTec opisane z przypadkowego sklepu. Ręcznie wskazany adres
            // (100) nadal bije producenta, bo to wybór człowieka.
            if (str_contains($u, 'ansell.com/pl/pl/products/')) {
                return 75;
            }
            // Karty obcojęzyczne zostają nisko: bywają cieńsze, a opis i tak ma być po polsku.
            if (str_contains($u, 'ansell.com/gb/en/products/')) {
                return 4;
            }
            if (preg_match('#ansell\.com/(?:cn|lac|hk|nz|apac|ap)/#', $u) === 1) {
                return -40;
            }

            return 0;
        }
        // Strona cennika z pliku z kodem karty: 90 dla pierwszej, 75 od szesnastej — nad rangą z panelu (21–70)
        // i zmapowanymi sklepami, pod adresem wskazanym ręcznie (100). Kolejność na liście cennika to ważność. Bez kodu
        // (strony sąsiednich wariantów) zwykłe reguły — inaczej zajmowały trzy miejsca pobrania.
        if ($listPosition !== null
            && $this->identity->hayHasProductCode(mb_strtolower(rawurldecode($url).' '.$title), $product)) {
            return 90 - min($listPosition, 15);
        }
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $rank = $ranks[preg_replace('/^www\./', '', $host) ?? $host] ?? null;
        if ($rank !== null) {
            // Ranga z panelu: 1 → 70, 100 → 21. Zawsze nad domeną zmapowaną bez rangi (20)
            // i zawsze pod kartą wskazaną ręcznie przy wyrobie (100).
            return 20 + (int) round((101 - $rank) / 2);
        }
        foreach ($retailers as $retailer) {
            if ($host === $retailer || str_ends_with($host, '.'.$retailer)) {
                return 20;
            }
        }

        return 10;
    }

    /**
     * @param  list<array{url: string, title?: string, snippet?: string}>  $results
     * @param  list<string>  $mfrDomains
     * @return list<array{url: string, title?: string, snippet?: string}>
     */
    private function manufacturerSearchResults(array $results, Product $product, array $mfrDomains): array
    {
        $out = [];
        foreach ($results as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            // bezpośrednie PDF z SERP — do ścieżki dokumentów
            if (ProductDocumentDownloader::looksLikePdfUrl($url)
                || $this->manufacturers->isManufacturerUrl($url, $product, $mfrDomains)) {
                $out[] = $row;
            }
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function retailerHostList(): array
    {
        $raw = config('enrichment.retailer_domains', []);
        if (! is_array($raw)) {
            $raw = [];
        }
        $out = [];
        foreach ([...$raw, ...CatalogSearchSite::allHosts()] as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $h = mb_strtolower(trim(preg_replace('#^https?://#i', '', $domain) ?? $domain));
            $h = rtrim(explode('/', $h)[0] ?? $h, '/');
            $h = preg_replace('/^www\./', '', $h) ?? $h;
            if ($h !== '') {
                $out[] = $h;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Opis z karty producenta bywa krótki — dobierz treść ze sklepów / innych źródeł.
     *
     * @param  list<array{url: string, title?: string, snippet?: string}>  $searchResults
     * @param  list<array{url: string, text: string}>  $pageSnippets
     * @param  array<string, mixed>  $extracted
     * @return array{
     *     description: string,
     *     extracted: array<string, mixed>,
     *     pages: list<array{url: string, text: string}>,
     *     image_urls: list<string>,
     *     trusted_image_urls: list<string>,
     *     document_urls: list<string>
     * }
     */
    /**
     * Karty z indeksu nie potwierdziły produktu — bierzemy kolejne z tego samego indeksu
     * (inne domeny), zanim wyszukiwarka dostanie site: na kilku sklepach.
     *
     * @param  list<array<string, mixed>>  $searchResults
     * @param  array<string, mixed>  $fetched
     * @return array{0: list<array{url?: string, text?: string}>, 1: array<string, mixed>, 2: list<array<string, mixed>>}
     */
    private function fetchMoreCatalogCards(Product $product, array $searchResults, array $fetched): array
    {
        $tried = [];
        foreach ($searchResults as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url !== '') {
                $tried[] = $url;
            }
        }
        foreach ($fetched['pages'] ?? [] as $page) {
            $url = is_array($page) ? (string) ($page['url'] ?? '') : '';
            if ($url !== '') {
                $tried[] = $url;
            }
        }

        $checked = [];
        for ($round = 1; $round <= self::CATALOG_EXTRA_ROUNDS; $round++) {
            $hits = $this->search->moreCatalogHits($product, $tried);
            if ($hits === []) {
                break;
            }
            foreach ($hits as $row) {
                $tried[] = (string) $row['url'];
                $checked[] = $row;
            }
            $this->attemptLog()->add(
                'catalog',
                'indeks: partia '.$round.' — '.count($hits).' kolejnych kart',
                urls: array_column($hits, 'url')
            );
            // do $tried idą wszystkie trafienia (także wykluczone), żeby kolejna partia ich nie powtarzała
            $more = $this->pages->fetch($this->dropOutsideListSources($this->dropBlockedSourceHosts($hits, $product), $product), (string) $product->sku, 3, [], $product);
            $confirmed = $this->keepConfirmedCardPages($product, $more['pages'] ?? []);
            if ($confirmed !== []) {
                $this->mergeDocumentLabels($fetched, $more);
                foreach (['image_urls', 'trusted_image_urls', 'document_urls'] as $key) {
                    foreach ($more[$key] ?? [] as $url) {
                        if (is_string($url) && $url !== '') {
                            $fetched[$key][] = $url;
                        }
                    }
                }

                return [$confirmed, $fetched, $checked];
            }
            $this->logCardRejections($product, $more);
        }

        return [[], $fetched, $checked];
    }

    /**
     * Pobrane karty, których nie przepuścił ani fetcher, ani końcowe potwierdzenie.
     *
     * @param  array<string, mixed>  $fetched
     * @return list<array{url: string, reason: string}> jeden adres raz, z pierwszym powodem
     */
    private function logCardRejections(Product $product, array $fetched): array
    {
        $rejections = is_array($fetched['rejected'] ?? null) ? $fetched['rejected'] : [];
        $reason = $this->identity->requiresExactSkuOrNameOnCard($product)
            ? CandidateRejection::UNCONFIRMED_STRICT
            : CandidateRejection::UNCONFIRMED;
        foreach ($fetched['pages'] ?? [] as $page) {
            $url = is_array($page) ? (string) ($page['url'] ?? '') : '';
            if ($url !== '') {
                $rejections[] = ['url' => $url, 'reason' => $reason];
            }
        }
        $rejections = CandidateRejection::unique($rejections);
        $this->attemptLog()->addRejections('pobrane karty', $rejections);

        return $rejections;
    }

    /**
     * Każda znaleziona karta odpadła wyłącznie dlatego, że sklep nie odpowiedział
     * (blokada, timeout). Nikt nie zobaczył treści, więc „brak karty” nic nie mówi
     * o produkcie — jedna karta odrzucona za treść już to rozstrzyga na niekorzyść.
     *
     * @param  list<array{url: string, reason: string}>  $rejections
     */
    private function allCardsUnreachable(array $rejections): bool
    {
        if ($rejections === []) {
            return false;
        }
        foreach ($rejections as $row) {
            if ($row['reason'] !== CandidateRejection::FETCH_FAILED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Każda znaleziona karta odpadła przez WAF sklepu, którego nie przeszedł też reader
     * (hahn-kolb: Akamai, 403 nawet dla przeglądarki). To blokada stała — karta istnieje,
     * ale automat jej nie przeczyta. Zwraca adresy tych kart, żeby człowiek mógł je otworzyć.
     *
     * @param  list<array{url: string, reason: string}>  $rejections
     * @return list<string>
     */
    /**
     * Adresy za zaporą nikt nie zweryfikował — to kandydaci z wyszukiwarki, nie karty.
     * Dla AlphaTec 58-301 były to 58-530w i 58-201, dla pasa 200 cm rękawica 23-200.
     * Na przód idą te z kodem produktu w adresie; reszta jest podpisana jako niepewna.
     *
     * @param  list<string>  $urls
     */
    private function walledCardsMessage(Product $product, array $urls): string
    {
        $coded = [];
        $other = [];
        foreach ($urls as $url) {
            if ($this->identity->hayHasProductCode(mb_strtolower($url), $product)) {
                $coded[] = $url;
            } else {
                $other[] = $url;
            }
        }
        $head = $coded !== []
            ? 'Karta produktu '.$product->sku.' prawdopodobnie tu: '.implode(', ', array_slice($coded, 0, 2))
            : 'Wyszukiwarka wskazała dla '.$product->sku.' tylko niepewnych kandydatów: '
                .implode(', ', array_slice($other, 0, 2)).' (kod produktu nie stoi w adresie)';

        $reader = '';
        if ($this->walledReaderDetails !== []) {
            arsort($this->walledReaderDetails);
            $parts = [];
            foreach (array_slice($this->walledReaderDetails, 0, 2, true) as $detail => $n) {
                $parts[] = $detail.' ×'.$n;
            }
            $reader = ' ('.implode(', ', $parts).')';
        }

        return $head.' — sklep blokuje pobieranie automatyczne, reader też nie przeszedł'.$reader.'.'
            .' Otwórz w przeglądarce, sprawdź, czy to ten produkt, i wpisz opis ręcznie.';
    }

    private function walledCardUrls(array $rejections): array
    {
        if ($rejections === []) {
            return [];
        }
        $urls = [];
        foreach ($rejections as $row) {
            if ($row['reason'] !== CandidateRejection::BOT_WALL) {
                return [];
            }
            $urls[] = $row['url'];
            if (isset($row['detail']) && is_string($row['detail']) && $row['detail'] !== '') {
                $this->walledReaderDetails[$row['detail']] = ($this->walledReaderDetails[$row['detail']] ?? 0) + 1;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Powody odmowy readera zebrane przez walledCardUrls w tym przebiegu —
     * „reader: limit 429 ×8” w komunikacie mówi, czy pomoże klucz API, czy nic.
     *
     * @var array<string, int>
     */
    private array $walledReaderDetails = [];

    /**
     * Listing / zła karta u producenta — szukaj dalej w zmapowanych sklepach.
     *
     * @param  list<array<string, mixed>>  $searchResults
     * @param  array<string, mixed>  $fetched
     * @return array{0: list<array{url?: string, text?: string}>, 1: array<string, mixed>, 2: list<array<string, mixed>>}
     */
    /**
     * Ostatnia droga: zwykłe szukanie w internecie, z pominięciem indeksu.
     *
     * Gdy indeks podsunął kartę dopasowaną słabo, a jej treść produktu nie
     * potwierdziła, wyszukiwarka nie była dotąd pytana ani razu — samo
     * istnienie trafienia w indeksie zamykało tę drogę. Akcesoria typu
     * AC01P-00022-00-N0C kończyły przez to na „wpisz ręcznie” bez jednego
     * zapytania do internetu.
     *
     * Gdy wyszukiwarka karty znalazła, ale żadna nie odpowiedziała (blokada sklepu,
     * timeout), czwarty element mówi o tym wprost — taki koniec to awaria do
     * ponowienia, nie dowód, że karty nie ma.
     *
     * @param  list<array<string, mixed>>  $searchResults  adresy już sprawdzone
     * @param  array<string, mixed>  $fetched
     * @param  list<string>  $mfrDomains
     *                                    Piąty element to adresy kart za WAF-em (403 + padnięty reader): taka blokada jest
     *                                    stała, więc produkt idzie do ręki z adresem, a nie do ponowienia.
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>, 2: list<array<string, mixed>>, 3: bool, 4: list<string>}
     */
    private function fetchCardsFromOpenWeb(
        Product $product,
        array $searchResults,
        array $fetched,
        array $mfrDomains
    ): array {
        $seen = [];
        foreach ($searchResults as $row) {
            $url = mb_strtolower((string) ($row['url'] ?? ''));
            if ($url !== '') {
                $seen[$url] = true;
            }
        }
        foreach ($fetched['pages'] ?? [] as $page) {
            $url = mb_strtolower(is_array($page) ? (string) ($page['url'] ?? '') : '');
            if ($url !== '') {
                $seen[$url] = true;
            }
        }

        $pack = $this->search->searchWebWithoutLocalIndex($product);
        $fresh = [];
        foreach ($this->dropOutsideListSources($this->dropBlockedSourceHosts($pack['results'], $product), $product) as $row) {
            $url = mb_strtolower((string) ($row['url'] ?? ''));
            if ($url !== '' && ! isset($seen[$url])) {
                $fresh[] = $row;
            }
        }
        if ($fresh === []) {
            $this->attemptLog()->add('search', 'internet bez indeksu: brak nowych adresów');

            return [[], $fetched, [], false, []];
        }

        $this->attemptLog()->add(
            'search',
            'internet bez indeksu: '.count($fresh).' nowych adresów',
            urls: array_slice(array_column($fresh, 'url'), 0, 5)
        );
        $webFetched = $this->pages->fetch($fresh, (string) $product->sku, 3, $mfrDomains, $product);
        // Zdjęcia i dokumenty zbieramy tak samo jak ścieżka zmapowanych sklepów.
        $this->mergeDocumentLabels($fetched, $webFetched);
        foreach (['image_urls', 'trusted_image_urls', 'document_urls'] as $key) {
            foreach ($webFetched[$key] ?? [] as $url) {
                if (is_string($url) && $url !== '') {
                    $fetched[$key][] = $url;
                }
            }
        }
        $pages = $this->keepConfirmedCardPages($product, $webFetched['pages'] ?? []);
        if ($pages === []) {
            $rejections = $this->logCardRejections($product, $webFetched);

            return [[], $fetched, [], $this->allCardsUnreachable($rejections), $this->walledCardUrls($rejections)];
        }

        return [$pages, $fetched, $fresh, false, []];
    }

    private function fetchMappedRetailerCards(Product $product, array $searchResults, array $fetched): array
    {
        $tried = [];
        foreach ($searchResults as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url !== '') {
                $tried[] = $url;
            }
        }
        foreach ($fetched['pages'] ?? [] as $page) {
            $url = is_array($page) ? (string) ($page['url'] ?? '') : '';
            if ($url !== '') {
                $tried[] = $url;
            }
        }

        $shopResults = $this->dropOutsideListSources(
            $this->dropBlockedSourceHosts($this->search->searchMappedRetailers($product, $tried), $product),
            $product
        );
        if ($shopResults === []) {
            return [[], $fetched, []];
        }

        $this->attemptLog()->add(
            'search',
            'zmapowane sklepy po niepotwierdzonej karcie',
            urls: array_values(array_filter(array_column($shopResults, 'url')))
        );
        $shopFetched = $this->pages->fetch($shopResults, (string) $product->sku, 3, [], $product);
        $this->mergeDocumentLabels($fetched, $shopFetched);
        foreach (['image_urls', 'trusted_image_urls', 'document_urls'] as $key) {
            foreach ($shopFetched[$key] ?? [] as $url) {
                if (! is_string($url) || $url === '') {
                    continue;
                }
                $fetched[$key][] = $url;
            }
        }

        return [
            $this->keepConfirmedCardPages($product, $shopFetched['pages'] ?? []),
            $fetched,
            $shopResults,
        ];
    }

    private function supplementDescriptionFromOtherSites(
        Product $product,
        array $searchResults,
        array $pageSnippets,
        array $extracted,
        string $description,
        bool $listedOnly = false,
    ): array {
        $used = [];
        foreach ($pageSnippets as $page) {
            $u = mb_strtolower((string) ($page['url'] ?? ''));
            if ($u !== '') {
                $used[$u] = true;
            }
        }

        $candidates = $this->dropOutsideListSources($this->dropBlockedSourceHosts($searchResults, $product), $product);
        // Strony cennika z pliku zamiast listy „Strony wyszukiwarka”: najpierw one (w kolejności z cennika), w trybie
        // „tylko” wyłącznie one; w trybie „najpierw” dalej jak bez ustawień.
        $listSites = $this->listSources;
        if ($listSites !== null) {
            $onList = [];
            $offList = [];
            foreach ($candidates as $i => $row) {
                $position = $listSites->position((string) ($row['url'] ?? ''));
                if ($position !== null) {
                    $onList[] = ['row' => $row, 'rank' => $position, 'position' => $i];
                } else {
                    $offList[] = $row;
                }
            }
            usort($onList, static fn (array $a, array $b): int => [$a['rank'], $a['position']] <=> [$b['rank'], $b['position']]);
            if ($listSites->onlyMode()) {
                $offList = array_values(array_filter(
                    $offList,
                    static fn (array $row): bool => $product->isTrustedShopUrl((string) ($row['url'] ?? ''))
                ));
            }
            $candidates = [...array_column($onList, 'row'), ...$offList];
        }
        // pula opisu zawężona do stron z listy „Strony wyszukiwarka” — uzupełnienie też tylko z nich
        $listed = $listedOnly
            ? app(CatalogSearchHostService::class)->listedHosts(array_map(
                static fn (array $row): string => (string) ($row['url'] ?? ''),
                $candidates
            ))
            : [];
        $extraResults = [];
        foreach ($candidates as $row) {
            $u = mb_strtolower((string) ($row['url'] ?? ''));
            if ($u === '' || isset($used[$u])) {
                continue;
            }
            $onListSite = $listSites?->covers((string) ($row['url'] ?? '')) ?? false;
            if ($listedOnly && ! $onListSite && ! isset($listed[ManufacturerSite::normalizeHost((string) parse_url($u, PHP_URL_HOST))])
                && ! $product->isTrustedShopUrl((string) ($row['url'] ?? ''))) {
                continue;
            }
            // uzupełnienie opisu wyłącznie ze sklepów — nie z karty producenta (strona cennika spoza znanych domen
            // producenta to sklep, choćby nazwą przypominała markę)
            if (ProductImageDownloader::looksLikeImageUrl((string) ($row['url'] ?? ''))
                || ($this->listSiteShopPosition((string) ($row['url'] ?? ''), $product) === null
                    && $this->manufacturers->isManufacturerUrl((string) ($row['url'] ?? ''), $product))) {
                continue;
            }
            $extraResults[] = $row;
            if (count($extraResults) >= 4) {
                break;
            }
        }

        $imageUrls = [];
        $trustedImageUrls = [];
        $documentUrls = [];
        if ($extraResults !== []) {
            $extraFetched = $this->pages->fetch($extraResults, (string) $product->sku, 3, [], $product);
            // Druga tura przechodzi TĘ SAMĄ bramkę tożsamości co pierwsza. Bez niej karta
            // innego wyrobu (zestaw SECURA 3100 przy nagłowiu, karta półmaski przy pierścieniu
            // zaczepowym) dokładała „bogatszy” opis i podmieniała ten z właściwej karty.
            // Etap 3 (W1): także strona innego wyrobu marki i ze sprzeczną cechą bezpieczeństwa nie idzie do modelu.
            $extraCards = $this->withoutForeignOrConflictingPages($product, $this->keepConfirmedCardPages($product, $extraFetched['pages']));
            if ($listSites !== null) {
                // strona cennika idzie przed innymi tylko jako strona tego wariantu (jak w priceListSitePagesFirst)
                $extraCards = array_values(array_filter(
                    $extraCards,
                    fn (array $page): bool => ! $listSites->covers((string) ($page['url'] ?? ''))
                        || $this->supplementPageNamesCardVariant($product, $page)
                ));
            }
            $extraPages = $extraCards !== [] ? $this->sanitizePagesWithLlm($product, $extraCards) : [];
            foreach ($extraPages as $page) {
                $pageSnippets[] = $page;
            }
            foreach ($extraFetched['image_urls'] as $url) {
                $imageUrls[] = $url;
            }
            foreach ($extraFetched['trusted_image_urls'] as $url) {
                $trustedImageUrls[] = $url;
            }
            foreach ($extraFetched['document_urls'] as $url) {
                $documentUrls[] = $url;
            }

            if ($extraPages !== []) {
                $extraExtracted = $this->extractWithLlm(
                    $product,
                    array_slice($extraResults, 0, 4),
                    $extraPages
                );
                $extraDesc = $this->modelDescription($extraExtracted);
                if (! $this->isUsableProductDescription($extraDesc, $product, array_column($extraPages, 'url'))) {
                    $extraDesc = '';
                }
                if ($this->isRicherDescription($extraDesc, $description)) {
                    $description = $extraDesc;
                }
                $extracted = $this->mergeExtracted($extracted, $extraExtracted, $product);
            }
        }

        return [
            'description' => $description,
            'extracted' => $extracted,
            'pages' => $pageSnippets,
            'image_urls' => $imageUrls,
            'trusted_image_urls' => $trustedImageUrls,
            'document_urls' => $documentUrls,
        ];
    }

    private function isRicherDescription(string $candidate, string $current): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '' || $this->looksLikeMissingCardMeta($candidate)
            || $this->looksLikeRawLocaleDump($candidate)
            || $this->looksLikeShopChromeDescription($candidate)
            || ProductPageFetcher::looksLikeShopOfferDump($candidate)) {
            return false;
        }
        if ($this->looksLikeRawLocaleDump($current) && ! $this->looksLikeRawLocaleDump($candidate)) {
            return true;
        }
        if ($current === '' || $this->looksLikeMissingCardMeta($current) || $this->looksLikeThinDescription($current)) {
            return ! $this->looksLikeThinDescription($candidate) || mb_strlen($candidate) > mb_strlen($current) + 40;
        }
        if ($this->looksLikeThinDescription($candidate)
            || $this->looksLikeShopChromeDescription($candidate)
            || ProductPageFetcher::looksLikeShopOfferDump($candidate)) {
            return false;
        }

        // Dłuższy tekst nie jest „lepszy” sam z siebie — karta całego zestawu zawsze bije
        // kartę pojedynczej części. Kompletnego opisu z właściwej karty nie podmieniamy;
        // dłuższy kandydat wygrywa tylko z opisem bez cech technicznych i norm.
        return $this->looksLikeIncompleteDescription($current)
            && mb_strlen($candidate) >= mb_strlen($current) + 60;
    }

    /** Opis bez cech technicznych / norm — warto doszukać na innych stronach. */
    private function looksLikeIncompleteDescription(string $description): bool
    {
        $d = trim($description);
        if (ProductPageFetcher::looksLikeTruncatedShopTeaser($d)
            || ProductPageFetcher::looksLikeShopOfferDump($d)) {
            return true;
        }
        if (mb_strlen($d) < 180) {
            return true;
        }
        $low = mb_strtolower($d);
        $hasTech = (bool) preg_match(
            '#(en\s*\d|iso\s*\d|norm|skór|skor|podeszw|podnosek|nitryl|lateks|kevlar|dyneema|bamboo|ochron|wodoodpor|antypośliz|src|hro|\bs1\b|\bs3\b|\bo1\b)#iu',
            $low
        );
        $hasPurpose = (bool) preg_match(
            '#(przeznacz|zastosow|branż|montaż|przemysł|warsztat|budown|logist|spożyw|chemicz|cięcie|przecię)#iu',
            $low
        );

        return ! $hasTech || ! $hasPurpose || substr_count($d, '.') < 3;
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function looksLikeSparsePayload(array $extracted): bool
    {
        $norms = $this->stringList($extracted['norms'] ?? null);
        $materials = $this->stringList($extracted['materials'] ?? null);
        $useCases = $this->stringList($extracted['use_cases'] ?? null);
        $features = $this->stringList($extracted['features'] ?? null);

        $filled = 0;
        foreach ([$norms, $materials, $useCases, $features] as $list) {
            if ($list !== []) {
                $filled++;
            }
        }

        return $filled < 2;
    }

    /**
     * Zdjęcia, których adres sam nazywa produkt (kod albo model w nazwie pliku). Tylko one
     * trafiają na kartę bez obejrzenia przez model — reszta, łącznie z „og:image” sklepu,
     * idzie do weryfikacji wizualnej, bo bywa logo, budynkiem firmy albo innym wyrobem.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function provenProductImages(array $urls, Product $product): array
    {
        return array_values(array_filter(
            $urls,
            fn ($url): bool => is_string($url) && $this->identity->imageUrlMentionsProduct($url, $product)
        ));
    }

    /**
     * Grupa na karcie bierze się z nazwy wyrobu przy imporcie cennika. Nazwa bywa jednak sama kodem,
     * a wtedy dopiero ściągnięty opis mówi, co to za wyrób — i to jest ten moment, żeby kategorię
     * doprecyzować. Kategoria, która już jest ścieżką z drzewa sklepu, zostaje nietknięta, także ta
     * wskazana ręcznie; poprawiamy tylko to, czego wcześniej nie dało się rozpoznać.
     *
     * Błąd tego kroku nie może przerwać wzbogacania — opis jest już zapisany.
     */
    private function refineCategoryFromDescription(Product $product, string $description): void
    {
        try {
            $path = app(PrestaCategoryRewriteService::class)->betterPathFor($product, $description);
            if ($path !== null) {
                $product->update(['category' => $path, 'category_source' => Product::CATEGORY_SOURCE_PRESTA_REWRITE]);
            }
        } catch (Throwable $e) {
            Log::info('Kategoria z opisu pominięta', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Etykiety linków do plików („Karta produktu”, „Karta gwarancyjna”) zebrane przy pobieraniu stron.
     * Po samym adresie nie da się odróżnić gwarancji od karty technicznej, a przy dobieraniu kolejnych
     * kart etykiety z pierwszego pobrania nie mogą przepaść.
     *
     * @param  array<string, mixed>  $fetched
     * @param  array<string, mixed>  $source
     */
    private function mergeDocumentLabels(array &$fetched, array $source): void
    {
        $labels = $source['document_labels'] ?? null;
        if (! is_array($labels)) {
            return;
        }
        $known = is_array($fetched['document_labels'] ?? null) ? $fetched['document_labels'] : [];
        foreach ($labels as $url => $label) {
            if (is_string($url) && is_string($label) && $url !== '' && ! isset($known[$url])) {
                $known[$url] = $label;
            }
        }
        $fetched['document_labels'] = $known;
    }

    /**
     * Źródło docelowe karty i jego rodzaj. Adres wskazany ręcznie bije wszystko, bo to decyzja
     * człowieka; dalej strona producenta, a sklepy dopiero po niej. Zwraca [null, null], gdy karta
     * powstała bez źródeł — brak rozstrzygnięcia jest informacją, nie wolno go zgadywać.
     *
     * @param  list<string>  $sourceUrls
     * @param  list<string>  $mfrDomains
     * @return array{0: string|null, 1: string|null}
     */
    private function primarySource(array $sourceUrls, Product $product, array $mfrDomains): array
    {
        $urls = array_values(array_filter($sourceUrls, static fn ($url): bool => is_string($url) && $url !== ''));
        if ($urls === []) {
            return [null, null];
        }

        foreach ($urls as $url) {
            if ($product->isTrustedShopUrl($url)) {
                return [$url, 'manual'];
            }
        }
        foreach ($urls as $url) {
            // strona cennika z pliku spoza znanych domen producenta to sklep (listSiteShopPosition)
            if ($this->listSiteShopPosition($url, $product) === null
                && $this->manufacturers->isManufacturerUrl($url, $product, $mfrDomains)) {
                return [$url, 'manufacturer'];
            }
        }
        foreach ($urls as $url) {
            if ($this->catalogPdf()->isConfiguredCatalogUrl($url)) {
                return [$url, 'catalog'];
            }
        }

        return [$urls[0], 'shop'];
    }

    /**
     * Karta producenta idzie na początek listy — model dostaje do opisu pierwsze 5 stron,
     * a budżet 20 000 znaków wyczerpują po kolei. Przy MAPIE karty sklepów zjadały cały
     * budżet i parametry (grubość, kategoria, normy) brały się z hurtowni zamiast z mapa-pro.
     * Landing serii bez treści zostaje z tyłu — liczy się karta z opisem, nie sama domena.
     *
     * @param  list<array{url: string, text: string}>  $pages
     * @param  list<string>  $mfrDomains
     * @return list<array{url: string, text: string}>
     */
    private function orderPagesForDescription(array $pages, Product $product, array $mfrDomains): array
    {
        $ranks = CatalogHostPriority::map();
        $manufacturer = [];
        $listSites = [];
        $ranked = [];
        $rest = [];
        foreach ($pages as $position => $page) {
            $url = (string) ($page['url'] ?? '');
            $text = trim((string) ($page['text'] ?? ''));
            $listPosition = $url !== '' ? $this->listSiteShopPosition($url, $product) : null;
            if ($url !== '' && $listPosition === null && mb_strlen($text) >= self::MFR_CARD_MIN_CHARS
                && $this->manufacturers->isManufacturerUrl($url, $product, $mfrDomains)) {
                $manufacturer[] = $page;

                continue;
            }
            // awans tylko dla strony tego wariantu (kod karty) — strona innego wariantu na tym hoście idzie dalej jak sklep
            if ($listPosition !== null && $this->supplementPageNamesCardVariant($product, $page)) {
                $listSites[] = ['page' => $page, 'rank' => $listPosition, 'position' => $position];

                continue;
            }
            $host = preg_replace('/^www\./', '', mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? '')));
            $rank = $ranks[$host] ?? null;
            if ($rank !== null) {
                $ranked[] = ['page' => $page, 'rank' => $rank, 'position' => $position];

                continue;
            }
            $rest[] = $page;
        }
        // Hierarchia źródeł opisu: producent, potem strony z ręczną rangą (1 najpierw),
        // na końcu pozostałe. Bez rang kolejność jest dokładnie ta, co przed zmianą. Strony cennika z pliku (listSources)
        // idą zaraz za producentem, w kolejności z listy cennika.
        usort($ranked, static fn (array $a, array $b): int => [$a['rank'], $a['position']] <=> [$b['rank'], $b['position']]);
        usort($listSites, static fn (array $a, array $b): int => [$a['rank'], $a['position']] <=> [$b['rank'], $b['position']]);

        return array_values(array_merge($manufacturer, array_column($listSites, 'page'), array_column($ranked, 'page'), $rest));
    }

    /**
     * Kolumna products.norms = lista norm zapisanego właśnie opisu (null przy pustej) — jak w ProductEnrichmentResetter,
     * kolumna należy do opisu i razem z nim się zmienia.
     *
     * @param  list<string>  $norms
     */
    /** @return bool kolumna zapisana (false = bez zmiany) */
    private function writeNormsColumn(Product $product, array $norms): bool
    {
        $column = ProductNormsColumn::fromList($norms);
        if ($product->norms === $column) {
            return false;
        }
        if (trim((string) $product->norms) !== '') {
            $this->attemptLog()->add('desc', 'normy karty: „'.$product->norms.'” → „'.($column ?? 'brak').'”');
        }
        $product->norms = $column;
        $product->save();

        return true;
    }

    /**
     * Marka z `enrichment.manufacturer_only_sources` z kartą producenta w puli: zostają wyłącznie strony producenta
     * (karta HTML, karta PDF z jego witryny, blok katalogu PDF) i adres wskazany przez człowieka. Tester (AJ GROUP 906,
     * 24.09.2026): behapownia.pl dokładała swoją ogólną listę rozmiarów (34…74 i XXS…6XL) do XS/48…4XL/62 producenta —
     * sama kolejność stron (orderPagesForDescription) nie wystarczała, bo sklep dalej trafiał do modelu i do rozmiarów.
     *
     * Hosty producenta wyłącznie z konfiguracji (isOfficialCatalogUrl): domeny odgadnięte z wyników wyszukiwania
     * dopasowują markę po fragmencie nazwy hosta i uznałyby za producenta sklep z „pros” w adresie.
     * Bez karty producenta w puli nic się nie zmienia — sklepy zostają źródłem.
     *
     * Ta sama reguła dla każdej marki, gdy człowiek zapisał link na stronę producenta i ta strona oddała treść
     * (trustedManufacturerCardInPool). Automat na plastry CEDERROTH 51011006 (28.09.2026): link do cederroth.com,
     * a opis i „Źródła” brały się jeszcze z dwóch sklepów z indeksu. Gdy strona nic nie oddała, sklepy zostają.
     *
     * $sourceHierarchy (zwykłe pobieranie opisu, m.in. cenniki z pliku — decyzja właściciela 01.10.2026): reguła dla
     * każdej marki (`enrichment.manufacturer_first_every_brand`), a bez karty producenta strony z listy „Strony
     * wyszukiwarka” przed resztą (listedSitePagesFirst). Uzupełnianie opisów B2B woła bez niej — tam przy Bolle
     * właściciel chciał 28.09 wszystkie sklepy.
     *
     * Strony cennika z pliku (listSources, tylko w enrichProduct) działają wyłącznie w gałęzi bez karty producenta, przed
     * listą „Strony wyszukiwarka”: strona cennika z treścią i kodem karty → pula = strony cennika + katalog PDF + adres
     * zaufany (list_sites = true). Bez takiej strony tryb „tylko” zostawia katalog PDF, adres zaufany i stronę producenta
     * z konfiguracji (pula bywa pusta), a tryb „najpierw” idzie dawną drogą (listedSitePagesFirst). Karta producenta tnie
     * pulę jak dotąd.
     *
     * Etap 3 (W2, decyzja właściciela 08.10.2026): marka z listy przy zwykłym pobieraniu ($sourceHierarchy) BEZ karty
     * producenta w puli nie bierze już opisu ze sklepów — zostają katalog PDF i adres wskazany przez człowieka
     * (cut = true, missing = true); strony cennika z pliku i lista „Strony wyszukiwarka” nie otwierają dla niej sklepów.
     * Pusta pula z missing = brak strony producenta (enrichProduct: ManufacturerPageMissingException, model nie jest
     * wołany). Uzupełnianie opisów B2B ($sourceHierarchy = false) — bez zmian.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array{pages: list<array<string, mixed>>, cut: bool, listed: bool, list_sites: bool, missing: bool}
     */
    private function manufacturerOnlyPages(Product $product, array $pages, bool $sourceHierarchy = true): array
    {
        if ($pages === []) {
            return ['pages' => $pages, 'cut' => false, 'listed' => false, 'list_sites' => false, 'missing' => false];
        }
        $byLink = $this->trustedManufacturerCardInPool($product, $pages);
        $hasCard = $byLink;
        $strict = $this->identity->usesManufacturerSourcesOnly($product);
        $everyBrand = $sourceHierarchy && (bool) config('enrichment.manufacturer_first_every_brand', false);
        if (! $hasCard && ($everyBrand || $strict)) {
            $hasCard = $this->officialCardInPool($product, $pages);
        }
        if (! $hasCard) {
            if (! $sourceHierarchy) {
                return ['pages' => $pages, 'cut' => false, 'listed' => false, 'list_sites' => false, 'missing' => false];
            }
            if ($strict) {
                return ['pages' => $this->manufacturerSourcesWithoutCard($product, $pages), 'cut' => true, 'listed' => false, 'list_sites' => false, 'missing' => true];
            }
            if ($this->listSources !== null) {
                $fromList = $this->priceListSitePagesFirst($product, $pages);
                if ($fromList !== null) {
                    return ['pages' => $fromList, 'cut' => false, 'listed' => true, 'list_sites' => true, 'missing' => false];
                }
                if ($this->listSources->onlyMode()) {
                    return ['pages' => $this->withoutPagesOutsideListSites($product, $pages), 'cut' => false, 'listed' => true, 'list_sites' => true, 'missing' => false];
                }
            }
            $listed = $this->listedSitePagesFirst($product, $pages);

            return ['pages' => $listed['pages'], 'cut' => false, 'listed' => $listed['cut'], 'list_sites' => false, 'missing' => false];
        }
        $kept = [];
        $dropped = [];
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '' && ($this->identity->isOfficialCatalogUrl($url, $product)
                || $this->catalogPdf()->isConfiguredCatalogUrl($url)
                || $product->isTrustedShopUrl($url))) {
                $kept[] = $page;

                continue;
            }
            $dropped[] = $url;
        }
        if ($dropped !== []) {
            $this->attemptLog()->add(
                'page',
                ($byLink ? 'zapisany link na stronę producenta' : 'tylko strony producenta')
                    .' — pominięte strony sklepów: '.count($dropped),
                urls: array_values(array_filter($dropped))
            );
        }

        return ['pages' => $kept, 'cut' => true, 'listed' => false, 'list_sites' => false, 'missing' => false];
    }

    /**
     * Strona z treścią karty (≥ MFR_CARD_MIN_CHARS) na hoście producenta z konfiguracji (isOfficialCatalogUrl — nie na
     * domenie odgadniętej z wyników).
     *
     * @param  list<array<string, mixed>>  $pages
     */
    private function officialCardInPool(Product $product, array $pages): bool
    {
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '' && mb_strlen(trim((string) ($page['text'] ?? ''))) >= self::MFR_CARD_MIN_CHARS
                && $this->identity->isOfficialCatalogUrl($url, $product)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Karta producenta w puli tak, jak liczy ją manufacturerOnlyPages: zapisany link na stronę producenta z treścią
     * albo strona z treścią na jego hoście z konfiguracji.
     *
     * @param  list<array<string, mixed>>  $pages
     */
    private function manufacturerCardInPool(Product $product, array $pages): bool
    {
        return $this->trustedManufacturerCardInPool($product, $pages) || $this->officialCardInPool($product, $pages);
    }

    /**
     * Marka „tylko od producenta” bez jego karty (etap 3, W2): zostają blok katalogu PDF i adres wskazany przez
     * człowieka, sklepy odpadają (wpis w przebiegu).
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function manufacturerSourcesWithoutCard(Product $product, array $pages): array
    {
        $kept = [];
        $dropped = [];
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '' && ($this->catalogPdf()->isConfiguredCatalogUrl($url) || $product->isTrustedShopUrl($url))) {
                $kept[] = $page;

                continue;
            }
            $dropped[] = $url;
        }
        if ($dropped !== []) {
            $this->attemptLog()->add(
                'page',
                'tylko strony producenta — karty producenta brak, pominięte strony sklepów: '.count($dropped),
                urls: array_values(array_filter($dropped))
            );
        }

        return $kept;
    }

    /**
     * Etap 3 (W1): strona innego wyrobu marki albo ze sprzeczną cechą bezpieczeństwa odpada z puli — razem ze swoimi
     * zdjęciami i plikami (biorą się ze stron opisu). Inny wyrób: arbiter najdłuższego kodu (SourceIdentity::
     * pageCodeRelation — „1011 R” na stronie przy karcie 1011, „102” z numeru wpisu przy stronie modelu 1102, etykieta
     * „REF/Indeks” z innym kodem producenta; tylko profile z longest_code_wins). Sprzeczna cecha: kV, klasa izolacji,
     * klasa filtra, gaz z nazwy karty bez części wspólnej z adresem i tytułem strony (SafetyFeatures; brak cechy w źródle
     * to nie sprzeczność). Adres wskazany przez człowieka i blok katalogu PDF zwolnione. Tytuł, mikrodane i surowy tekst
     * strony biorą się z dziennika przebiegu — pula po scaleniu (mergePageSnippets) ich nie niesie.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function withoutForeignOrConflictingPages(Product $product, array $pages): array
    {
        if ($pages === []) {
            return [];
        }
        $profile = $this->profiles()->for($product);
        $card = SafetyFeatures::ofCard($product);
        $logged = [];
        foreach ($this->pages->runLog() as $page) {
            $url = is_array($page) ? (string) ($page['url'] ?? '') : '';
            if ($url !== '') {
                $logged[Product::normalizeShopUrl($url)] = $page;
            }
        }
        $kept = [];
        $dropped = [];
        $why = [];
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url === '' || $product->isTrustedShopUrl($url) || $this->catalogPdf()->isConfiguredCatalogUrl($url)) {
                $kept[] = $page;

                continue;
            }
            $judged = $page;
            $fromLog = $logged[Product::normalizeShopUrl($url)] ?? null;
            if (is_array($fromLog)) {
                $judged = [...$page, ...$fromLog];
            }
            $reason = null;
            $relation = $this->sourceIdentity()->pageCodeRelation($product, $judged, $profile);
            if (($relation['verdict'] ?? null) === CardCodeArbiter::FOREIGN) {
                $code = trim((string) ($relation['code'] ?? ''));
                $reason = 'inny wyrób marki'.($code !== '' ? ' ('.$code.')' : '');
            } else {
                $found = [SafetyFeatures::inUrl($url), SafetyFeatures::in((string) ($judged['title'] ?? ''))];
                $final = trim((string) ($judged['final_url'] ?? ''));
                if ($final !== '' && $final !== $url) {
                    $found[] = SafetyFeatures::inUrl($final);
                }
                // suma cech po kluczach (SafetyFeatures::merge) — „+” na tablicach zgubiłby cechy z tytułu
                $conflict = SafetyFeatures::conflict($card, SafetyFeatures::merge(...$found));
                if ($conflict !== null) {
                    $reason = 'sprzeczna cecha: '.$conflict;
                }
            }
            if ($reason === null) {
                $kept[] = $page;

                continue;
            }
            $dropped[] = $url;
            $why[] = $reason;
        }
        if ($dropped !== []) {
            $this->attemptLog()->add(
                'page',
                'strony innego wyrobu albo ze sprzeczną cechą bezpieczeństwa — pominięte: '.count($dropped),
                urls: $dropped,
                why: $why,
            );
        }

        return $kept;
    }

    /**
     * Etap 3 (W5): zdjęcie z kodem innej karty marki w nazwie pliku albo adresie (SourceIdentity::fileCodeRelation —
     * „…-model-1011-r.jpg” przy karcie 1011) albo ze sprzeczną cechą bezpieczeństwa w adresie („…-20kv.jpg” przy 30 kV)
     * odpada. Zdjęcie bez kodu i bez cechy zostaje — o nim decydują dalsze bramki jak dotąd.
     *
     * @param  list<mixed>  $urls
     * @return list<string>
     */
    private function withoutForeignImages(Product $product, array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $profile = $this->profiles()->for($product);
        $card = SafetyFeatures::ofCard($product);
        $kept = [];
        $dropped = [];
        $why = [];
        foreach ($urls as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }
            $relation = $this->sourceIdentity()->fileCodeRelation($product, $url, $profile);
            $reason = null;
            if (($relation['verdict'] ?? null) === CardCodeArbiter::FOREIGN) {
                $code = trim((string) ($relation['code'] ?? ''));
                $reason = 'zdjęcie innego wyrobu marki'.($code !== '' ? ' ('.$code.')' : '');
            } else {
                $conflict = SafetyFeatures::conflict($card, SafetyFeatures::inUrl($url));
                if ($conflict !== null) {
                    $reason = 'sprzeczna cecha: '.$conflict;
                }
            }
            if ($reason === null) {
                $kept[] = $url;

                continue;
            }
            $dropped[] = $url;
            $why[] = $reason;
        }
        if ($dropped !== []) {
            $this->attemptLog()->add('image', 'zdjęcia innego wyrobu albo ze sprzeczną cechą — pominięte: '.count($dropped), urls: $dropped, why: $why);
        }

        return array_values(array_unique($kept));
    }

    /**
     * Etap 3 (W6): pliki PDF wyrobu.
     * (a) plik z kodem innej karty marki w adresie albo opisie linku (fileCodeRelation = foreign) odpada — także plik ze
     *     strony opisu (CEDERROTH „51011003-v03.pdf” przy 51011013);
     * (b) plik z numerem wpisu sklepu (pdf.php?id_product=N, ShopEntryId::entryId) na hoście strony opisu zostaje tylko
     *     wtedy, gdy N to numer wpisu którejś strony opisu na tym hoście (AJ GROUP id_product=105 przy stronie 104);
     * (c) — w enrichProduct: kod wyrobu szukany w adresie bez numerów wpisów sklepu;
     * (d) marka „tylko od producenta” z pulą zawężoną do producenta ($manufacturerOnly): tylko pliki ze stron opisu,
     *     z hostów producenta albo katalogu (PDF-y MAPA z cas-technik.eu z wyników wyszukiwania odpadają).
     *
     * @param  list<string>  $urls
     * @param  array<string, string>  $labels  adres pliku => opis linku
     * @param  list<array<string, mixed>>  $descPages  strony opisu
     * @param  array<string, true>  $fromDescriptionPages  pliki wymienione przez strony opisu
     * @return list<string>
     */
    private function withoutForeignDocuments(
        Product $product,
        array $urls,
        array $labels,
        array $descPages,
        bool $manufacturerOnly = false,
        array $fromDescriptionPages = [],
    ): array {
        if ($urls === []) {
            return [];
        }
        $profile = $this->profiles()->for($product);
        $hostOf = static fn (string $url): string => (string) preg_replace(
            '/^www\./',
            '',
            mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''))
        );
        // numery wpisów sklepu stron opisu, po hoście — tylko hosty, których strony opisu same niosą numer wpisu
        // (porównanie ma sens tylko w obrębie jednego sklepu; „file.php?id=55” producenta bez numerów stron zostaje)
        $entryIds = [];
        foreach ($descPages as $page) {
            foreach (array_unique(array_filter([(string) ($page['url'] ?? ''), (string) ($page['final_url'] ?? '')])) as $address) {
                $host = $hostOf($address);
                $id = ShopEntryId::entryId($address);
                if ($host !== '' && $id !== null) {
                    $entryIds[$host][$id] = true;
                }
            }
        }
        $strictPool = $manufacturerOnly && $this->identity->usesManufacturerSourcesOnly($product);
        $kept = [];
        $dropped = [];
        $why = [];
        foreach ($urls as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }
            $reason = null;
            $relation = $this->sourceIdentity()->fileCodeRelation($product, $url.' '.($labels[$url] ?? ''), $profile);
            $host = $hostOf($url);
            $entry = ShopEntryId::entryId($url);
            if (($relation['verdict'] ?? null) === CardCodeArbiter::FOREIGN) {
                $code = trim((string) ($relation['code'] ?? ''));
                $reason = 'plik innego wyrobu marki'.($code !== '' ? ' ('.$code.')' : '');
            } elseif ($entry !== null && $host !== '' && isset($entryIds[$host]) && ! isset($entryIds[$host][$entry])) {
                $reason = 'plik wpisu sklepu '.$entry.' spoza stron opisu';
            } elseif ($strictPool && ! isset($fromDescriptionPages[$url])
                && ! ($profile?->ownsUrl($url) ?? false)
                && ! $this->identity->isOfficialCatalogUrl($url, $product)
                && ! $this->catalogPdf()->isConfiguredCatalogUrl($url)) {
                $reason = 'tylko strony producenta — plik spoza stron opisu i hostów producenta';
            }
            if ($reason === null) {
                $kept[] = $url;

                continue;
            }
            $dropped[] = $url;
            $why[] = $reason;
        }
        if ($dropped !== []) {
            $this->attemptLog()->add('docs', 'pliki innego wyrobu albo spoza stron opisu — pominięte: '.count($dropped), urls: $dropped, why: $why);
        }

        return array_values(array_unique($kept));
    }

    /**
     * Etap 3 (W3): marka „tylko od producenta” bez jego karty w puli — druga próba na hostach producenta z innymi
     * zapisami kodu (ManufacturerCodeForms według profilu: „SBA01B” → „SBA01”). Dla każdego zapisu kopia karty z tym
     * SKU (nigdy nie zapisywana) szuka w indeksie hostów producenta, a bez trafienia w wyszukiwarce (site:). Zostają strony
     * na hoście producenta, które werdykt tożsamości uznaje za twarde dla kopii i które nie są stroną innej karty marki
     * (arbiter, cechy bezpieczeństwa — withoutForeignOrConflictingPages na prawdziwej karcie). Werdykt karty dla takiej
     * strony to najwyżej soft (SourceIdentity: kod karty w innym zapisie) — opis idzie do przeglądu jako identity_soft.
     * Awaria wyszukiwarki zapamiętana w manufacturerRetryOutage (wtedy „failed”, nie manufacturer_missing).
     *
     * @return list<array<string, mixed>>
     */
    private function retryManufacturerHosts(Product $product): array
    {
        $profile = $this->profiles()->for($product);
        if ($profile === null || $profile->hosts === []) {
            return [];
        }
        $forms = app(ManufacturerCodeForms::class)->alternatives($product, $profile);
        if ($forms === []) {
            $this->attemptLog()->add('search', 'druga próba na stronach producenta — brak innych zapisów kodu '.$product->sku);

            return [];
        }
        $sku = mb_strtolower(trim((string) $product->sku));
        foreach ($forms as $form) {
            $code = trim((string) ($form['code'] ?? ''));
            $rule = trim((string) ($form['rule'] ?? ''));
            if ($code === '' || mb_strtolower($code) === $sku) {
                continue;
            }
            $label = 'druga próba: kod '.$code.($rule !== '' ? ' ('.$rule.')' : '');
            // kopia tylko do szukania i werdyktu — nigdy nie zapisywana
            $clone = clone $product;
            $clone->sku = $code;
            $hits = $this->search->catalogHitsOnHosts($clone, $profile->hosts, $label);
            if ($hits === []) {
                $hits = $this->search->searchOnHosts($clone, $profile->hosts, $label);
                foreach ($this->search->lastHostSearchErrors() as $error) {
                    if ($this->searchFailedDueToEngineOutage($error)) {
                        $this->manufacturerRetryOutage ??= $error;
                    }
                }
            }
            $hits = array_values(array_filter(
                $this->withoutRejectedSources($hits, $product),
                static fn (mixed $row): bool => is_array($row) && $profile->ownsUrl((string) ($row['url'] ?? ''))
            ));
            if ($hits === []) {
                $this->attemptLog()->add('search', $label.' — brak strony na hostach producenta');

                continue;
            }
            $fetched = $this->pages->fetch($hits, $code, 3, $profile->hosts, $clone);
            $found = [];
            $skipped = [];
            $why = [];
            foreach ($fetched['pages'] as $page) {
                $url = is_array($page) ? (string) ($page['url'] ?? '') : '';
                if ($url === '' || ! $profile->ownsUrl($url)) {
                    continue;
                }
                $verdict = $this->sourceIdentity()->judgePage($clone, $page, $profile);
                if (($verdict['verdict'] ?? null) !== SourceIdentity::HARD) {
                    $skipped[] = $url;
                    $why[] = 'bez kodu '.$code.': '.(string) ($verdict['reason'] ?? '');

                    continue;
                }
                if ($this->withoutForeignOrConflictingPages($product, [$page]) === []) {
                    continue;
                }
                $found[] = $page;
            }
            if ($skipped !== []) {
                $this->attemptLog()->add('page', $label.' — strony bez potwierdzenia kodu', urls: $skipped, why: $why);
            }
            $this->attemptLog()->add('search', $label.' — stron producenta: '.count($found), urls: array_column($found, 'url'));
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * Etap 3 (W4): wyjątek końca przebiegu bez strony producenta przy marce „tylko od producenta”. Awaria wyszukiwarki
     * (w pierwszym szukaniu albo w drugiej próbie) to nie brak strony — zwykły ProductSourcesNotFoundException z frazą
     * awarii, przebieg kończy się w „failed” i nic nie jest cofane.
     */
    private function manufacturerPageMissing(Product $product, string $searchEmptyDetail): ProductSourcesNotFoundException
    {
        $outage = $this->engineOutageDetail($searchEmptyDetail) ?? $this->manufacturerRetryOutage;
        if ($outage !== null) {
            return new ProductSourcesNotFoundException(
                'Wyszukiwarka nie odpowiedziała przy produkcie '.$product->sku
                    .' — nie wiadomo, czy strona producenta istnieje. Ponów po przywróceniu wyszukiwarki. '.$outage
            );
        }
        $hosts = [];
        foreach ($this->profiles()->for($product)?->hosts ?? [] as $host) {
            $host = (string) preg_replace('/^www\./', '', mb_strtolower(trim((string) $host)));
            if ($host !== '') {
                $hosts[$host] = $host;
            }
        }

        return new ManufacturerPageMissingException(
            'Strony producenta nie znaleziono (hosty: '.($hosts !== [] ? implode(', ', array_slice(array_values($hosts), 0, 4)) : 'brak w konfiguracji')
                .') — marka tylko od producenta, sklepy pominięte. Wskaż adres w Do przeglądu.'
        );
    }

    /**
     * Etap 3 (W4, decyzja właściciela 08.10.2026): przy pełnym pobraniu bez strony producenta opis karty marki „tylko od
     * producenta”, który przyszedł spoza hostów producenta (sklep), wraca do historii wersji
     * (DescriptionVersionStore::withdrawCurrent: wersja → superseded, opis i normy karty puste, powód przeglądu
     * manufacturer_missing; „Przywróć wersję” go oddaje). Opis ze strony producenta, z katalogu PDF, z adresu wskazanego
     * przez człowieka i bez znanego źródła zostaje; wersję chronioną i opis z B2B odmawia cofnąć sam magazyn wersji.
     * Zdjęć i plików nie usuwamy. Błąd cofania nie zmienia wyniku przebiegu (karta i tak idzie do przeglądu).
     */
    private function withdrawShopDescription(Product $product): bool
    {
        try {
            $product->refresh();
            if (! $product->hasDescriptionText()) {
                return false;
            }
            $source = $this->shopDescriptionSource($product);
            if ($source === null) {
                return false;
            }
            $host = (string) (parse_url($source, PHP_URL_HOST) ?? $source);
            $version = $this->versions()->withdrawCurrent(
                $product,
                'brak strony producenta — opis ze sklepu '.$host.' cofnięty (marka tylko od producenta)'
            );
            if ($version === null) {
                $this->attemptLog()->add('desc', 'opis ze sklepu zostaje — wersja chroniona albo opis z B2B', urls: [$source]);

                return false;
            }
            $this->attemptLog()->add('desc', 'opis ze sklepu cofnięty do historii wersji — marka tylko od producenta, strony producenta brak (zdjęcia i pliki zostają)', urls: [$source]);
            $product->refresh();

            return true;
        } catch (Throwable $e) {
            Log::warning('Withdrawing shop description failed', ['product_id' => $product->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Adres źródła obecnego opisu karty, gdy to sklep: spoza hostów producenta (profil i konfiguracja), katalogu PDF
     * i adresu wskazanego przez człowieka. null = opis ze źródła producenta, wskazany ręcznie albo bez znanego źródła.
     * Czyta enrichment_payload karty (primary_source_url, a bez niego pierwszy z source_urls).
     */
    private function shopDescriptionSource(Product $product): ?string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        if (($payload['primary_source_kind'] ?? null) === 'manual') {
            return null;
        }
        $source = $payload['primary_source_url'] ?? null;
        if (! is_string($source) || trim($source) === '') {
            $source = null;
            foreach ((array) ($payload['source_urls'] ?? []) as $url) {
                if (is_string($url) && trim($url) !== '') {
                    $source = $url;
                    break;
                }
            }
        }
        if ($source === null) {
            return null;
        }
        $source = trim($source);

        return $this->isManufacturerSourceUrl($product, $source) ? null : $source;
    }

    /** Strona producenta (host z profilu albo z konfiguracji), blok katalogu PDF albo adres wskazany przez człowieka. */
    private function isManufacturerSourceUrl(Product $product, string $url): bool
    {
        return $product->isTrustedShopUrl($url)
            || $this->catalogPdf()->isConfiguredCatalogUrl($url)
            || ($this->profiles()->for($product)?->ownsUrl($url) ?? false)
            || $this->identity->isOfficialCatalogUrl($url, $product);
    }

    /**
     * Etap 3: marka „tylko od producenta”, a nowy opis ma źródło u producenta (strona producenta — także z drugiej próby,
     * werdykt soft — katalog PDF albo adres wskazany przez człowieka): obecny opis ze sklepu nie jest bazą dla decide().
     * Bez tego decide() porównywał werdykt soft strony producenta z hard sklepu (roboczystyl przy SBA01B, członkowie
     * Coby z opisem lidera z coba.com) i opis producenta zostawał tylko propozycją.
     */
    private function shopBaseYieldsTo(Product $product, ?string $newSourceUrl): bool
    {
        return $newSourceUrl !== null && trim($newSourceUrl) !== ''
            && $this->identity->usesManufacturerSourcesOnly($product)
            && $this->isManufacturerSourceUrl($product, trim($newSourceUrl));
    }

    /**
     * W transakcji publishDescription, pod blokadą wiersza karty: bieżąca wersja opisu ze sklepu → superseded jako
     * cofnięta (karta bez zmian — nowy opis zapisuje zaraz publishRun). null = karta bez opisu, opis spoza sklepu,
     * wersja chroniona albo opis z B2B (decyzja idzie wtedy jak dotąd).
     */
    private function supersedeShopBase(Product $product): ?ProductDescriptionVersion
    {
        /** @var Product|null $locked */
        $locked = Product::query()->lockForUpdate()->find($product->id);
        if ($locked === null || ! $locked->hasDescriptionText()) {
            return null;
        }
        $source = $this->shopDescriptionSource($locked);
        if ($source === null) {
            return null;
        }
        $version = $this->versions()->supersedeAsWithdrawn($locked, 'opis ze sklepu zastąpiony stroną producenta');
        if ($version === null) {
            $this->attemptLog()->add('desc', 'opis ze sklepu zostaje bazą — wersja chroniona albo opis z B2B', urls: [$source]);
        }

        return $version;
    }

    /**
     * Powód i chwila przeglądu karty, która czeka na decyzję w „Do przeglądu” z propozycją opisu (worse_version albo
     * rejected_source i wersja proposed bez decyzji); null = bez czekającej propozycji. Stan z bazy — model w pamięci
     * przebiegu bywa sprzed zapisu propozycji.
     *
     * @return array{review_reason: string, review_since: CarbonInterface}|null
     */
    private function waitingProposalReview(Product $product): ?array
    {
        $row = Product::query()->whereKey($product->id)->first(['id', 'review_reason', 'review_since']);
        if ($row === null || ! in_array($row->review_reason, [Product::REVIEW_WORSE_VERSION, Product::REVIEW_REJECTED_SOURCE], true)) {
            return null;
        }
        $waiting = ProductDescriptionVersion::query()
            ->where('product_id', $product->id)
            ->where('status', ProductDescriptionVersion::STATUS_PROPOSED)
            ->whereNull('decision')
            ->exists();

        return $waiting ? ['review_reason' => (string) $row->review_reason, 'review_since' => $row->review_since ?? now()] : null;
    }

    /**
     * Opis od modelu jako zwykły tekst (ProductDescriptionText::plain) bez urwanego końca (etap 3, W7): ostatni akapit
     * kończący się na „:”, „,”, „(”, myślnik albo bez kropki jest obcinany do ostatniego pełnego zdania — ślad „desc”.
     * Gdy z akapitu nic by nie zostało, tekst zostaje bez zmian.
     */
    private function modelText(string $raw): string
    {
        $text = ProductDescriptionText::plain($raw);
        if ($text === '') {
            return '';
        }
        $tail = ProductDescriptionText::withoutUnfinishedTail($text);
        if (($tail['cut'] ?? '') === '') {
            return $text;
        }
        $this->attemptLog()->add('desc', 'urwany koniec opisu obcięty: „'.mb_substr((string) $tail['cut'], 0, 200).'”');

        return (string) $tail['text'];
    }

    /**
     * Etap 3 (W7, §1.5): opis z jawnym kodem filtra (P1–P3, FFP1–3) albo gazu (A2, ABEK…) sprzecznym z nazwą karty
     * („Pochłaniacz 3033 E2” z opisem tylko o klasie A2) jest odrzucany — model opisał inny wyrób. kV i klasy izolacji
     * w opisie nie porównujemy (strona rodziny podaje wszystkie warianty); brak cechy w opisie to nie sprzeczność.
     */
    private function descriptionContradictsCard(Product $product, string $description): bool
    {
        if (trim($description) === '') {
            return false;
        }
        $card = SafetyFeatures::ofCard($product);
        $found = SafetyFeatures::in($description);
        $found['kv'] = [];
        $found['insulation'] = [];
        $conflict = SafetyFeatures::conflict($card, $found);
        if ($conflict === null) {
            return false;
        }
        $this->attemptLog()->add('desc', 'opis sprzeczny z nazwą karty ('.$conflict.') — odrzucony');

        return true;
    }

    /** Powód wycięcia zdania, które powtarza polecenie (PromptEcho) — rozdziela dropped_meta_sentences od norm. */
    private const PROMPT_ECHO_REASON = 'powtórzenie polecenia';

    /**
     * Etap 3 (W7) przed sitem norm: „EN ISO 388” → „EN 388” w opisie (ślad), zdania powtarzające polecenie
     * (PromptEcho: „Produkt należy do kategorii PPE (obuwie, rękawice, odzież), a tekst opisuje…”) i zdania ze sprzecznym
     * oznaczeniem normy (NormListSanity::sentenceProblems: „EN ISO 13999” z literą, typ EN ISO 374-1 sprzeczny z liczbą
     * liter) wypadają. Opis za krótki po wycięciu — wyjątek jak przy normach spoza źródeł.
     *
     * @return array{description: string, dropped_norm_claims: list<string>, dropped_meta_sentences: list<string>}
     */
    private function withoutPromptEchoAndNormProblems(string $description): array
    {
        $fixed = (string) preg_replace('/\bEN[\s\-]*ISO[\s\-]*388(?!\d)/u', 'EN 388', $description);
        if ($fixed !== $description) {
            $this->attemptLog()->add('desc', 'poprawiono „EN ISO 388” na „EN 388” w opisie');
        }
        $filtered = SourceClaimGuard::filterSentences($fixed, static function (string $sentence): array {
            if (PromptEcho::isEcho($sentence)) {
                return [self::PROMPT_ECHO_REASON];
            }

            return NormListSanity::sentenceProblems($sentence);
        });
        $meta = [];
        $norms = [];
        $echoPrefix = self::PROMPT_ECHO_REASON.': ';
        foreach ($filtered['dropped'] as $dropped) {
            if (str_starts_with($dropped, $echoPrefix)) {
                $meta[] = mb_substr(mb_substr($dropped, mb_strlen($echoPrefix)), 0, 300);
            } else {
                $norms[] = mb_substr($dropped, 0, 300);
            }
        }
        if ($filtered['dropped'] !== []) {
            $this->attemptLog()->add('desc', 'usunięto zdania powtarzające polecenie albo ze sprzecznym oznaczeniem normy: '.mb_substr(implode(' | ', $filtered['dropped']), 0, 400));
            if (! Product::isDescriptionText($filtered['text'])) {
                throw new RuntimeException('Opis po usunięciu zdań powtarzających polecenie albo ze sprzecznym oznaczeniem normy jest za krótki: '.mb_substr(implode(' | ', $filtered['dropped']), 0, 300));
            }
        }

        return [
            'description' => $filtered['text'],
            'dropped_norm_claims' => array_values(array_unique($norms)),
            'dropped_meta_sentences' => array_values(array_unique($meta)),
        ];
    }

    /**
     * Etap 3 (W7) po sicie norm: pola norm (norms, attributes.normy_en) przez NormListSanity::clean — poziom, który do
     * normy nie należy, zdjęty („EN 388 15 gauge” → „EN 388”), „Kategoria 2” przeniesiona do certyfikatów, pozycja bez
     * oznaczenia normy albo sprzeczna („EN ISO 13999 D”, „EN ISO 374-1 Type B JKLOPT”, „ATEX…”) odrzucona — poprawki
     * i odrzucenia do dropped_norm_claims z przedrostkiem „pole norm: ”. Pozycje list powtarzające polecenie
     * (PromptEcho) wypadają do dropped_meta_sentences.
     *
     * @param  array<string, mixed>  $extracted
     * @return array{extracted: array<string, mixed>, dropped_norm_claims: list<string>, dropped_meta_sentences: list<string>}
     */
    private function withSaneNormLists(array $extracted): array
    {
        $meta = [];
        foreach (['features', 'norms', 'certificates', 'materials', 'use_cases', 'specs'] as $key) {
            if (! is_array($extracted[$key] ?? null)) {
                continue;
            }
            $extracted[$key] = array_values(array_filter($extracted[$key], static function (mixed $item) use (&$meta): bool {
                if (is_string($item) && PromptEcho::isEcho($item)) {
                    $meta[] = mb_substr($item, 0, 300);

                    return false;
                }

                return true;
            }));
        }

        $notes = [];
        $moved = [];
        $clean = static function (array $list) use (&$notes, &$moved): array {
            $items = array_values(array_filter($list, static fn (mixed $v): bool => is_string($v) && trim($v) !== ''));
            if ($items === []) {
                return [];
            }
            $result = NormListSanity::clean($items);
            foreach ([...$result['fixed'], ...$result['dropped']] as $note) {
                $notes[] = 'pole norm: '.$note;
            }
            $moved = [...$moved, ...$result['certificates']];

            return array_values($result['norms']);
        };
        if (is_array($extracted['norms'] ?? null)) {
            $extracted['norms'] = $clean($extracted['norms']);
        }
        if (is_array($extracted['attributes'] ?? null) && is_array($extracted['attributes']['normy_en'] ?? null)) {
            $extracted['attributes']['normy_en'] = $clean($extracted['attributes']['normy_en']);
        }
        if ($moved !== []) {
            $certificates = is_array($extracted['certificates'] ?? null) ? $extracted['certificates'] : [];
            $extracted['certificates'] = array_values(array_unique([...$certificates, ...$moved]));
        }
        $notes = array_values(array_unique(array_map(static fn (string $n): string => mb_substr($n, 0, 300), $notes)));
        if ($notes !== [] || $meta !== []) {
            $this->attemptLog()->add('desc', 'pole norm i listy: '.mb_substr(implode(' | ', [...$notes, ...$meta]), 0, 400));
        }

        return [
            'extracted' => $extracted,
            'dropped_norm_claims' => $notes,
            'dropped_meta_sentences' => array_values(array_unique($meta)),
        ];
    }

    /**
     * Bez karty producenta w puli: gdy któraś strona z treścią leży na liście „Strony wyszukiwarka”, opis powstaje
     * tylko ze stron z tej listy (decyzja właściciela 01.10.2026 — „producent, potem strony z listy, reszta internetu
     * dopiero gdy z listy nic nie ma”). Zostają też katalog PDF i adres wskazany przez człowieka.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array{pages: list<array<string, mixed>>, cut: bool} cut = pula zawężona do stron z listy
     */
    private function listedSitePagesFirst(Product $product, array $pages): array
    {
        $hostOf = static fn (string $url): string => (string) preg_replace(
            '/^www\./',
            '',
            mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''))
        );
        $hosts = [];
        foreach ($pages as $page) {
            $host = $hostOf((string) ($page['url'] ?? ''));
            if ($host !== '') {
                $hosts[$host] = $host;
            }
        }
        $listed = app(CatalogSearchHostService::class)->listedHosts(array_values($hosts));
        if ($listed === []) {
            return ['pages' => $pages, 'cut' => false];
        }
        $hasListedCard = false;
        foreach ($pages as $page) {
            if (isset($listed[$hostOf((string) ($page['url'] ?? ''))])
                && mb_strlen(trim((string) ($page['text'] ?? ''))) >= self::MFR_CARD_MIN_CHARS) {
                $hasListedCard = true;
                break;
            }
        }
        if (! $hasListedCard) {
            return ['pages' => $pages, 'cut' => false];
        }
        $kept = [];
        $dropped = [];
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '' && (isset($listed[$hostOf($url)])
                || $this->catalogPdf()->isConfiguredCatalogUrl($url)
                || $product->isTrustedShopUrl($url))) {
                $kept[] = $page;

                continue;
            }
            $dropped[] = $url;
        }
        if ($dropped !== []) {
            $this->attemptLog()->add(
                'page',
                'strony z listy „Strony wyszukiwarka” — pominięte strony spoza listy: '.count($dropped),
                urls: array_values(array_filter($dropped))
            );
        }

        return ['pages' => $kept, 'cut' => true];
    }

    /**
     * Ustawienia „Źródła opisów” cennika z pliku dla tej karty (slot ceny „file”) i domeny producenta znane przed
     * przebiegiem. Bez ustawień (karta bez slotu, niezapisana, cennik bez stron) — null i przebieg jak dotąd.
     */
    private function priceListSourcesFor(Product $product): ?PriceListSourceSettings
    {
        $this->listSourcesManufacturerDomains = [];
        $settings = app(PriceListCards::class)->sourceSettingsFor($product);
        if ($settings === null) {
            return null;
        }
        $this->listSourcesManufacturerDomains = $this->manufacturers->assignedDomainsFor($product);
        $this->attemptLog()->add(
            'search',
            'strony cennika '.$settings->manufacturer.' — '
                .($settings->onlyMode() ? 'tylko producent i strony cennika' : 'najpierw strony cennika, potem dotychczasowa kolejność')
                .': '.implode(', ', $settings->hosts)
        );

        return $settings;
    }

    /**
     * Pozycja strony na liście cennika z pliku (0 = najważniejsza), gdy adres leży na hoście cennika, a ten host nie
     * jest domeną producenta (konfiguracja albo domena znana przed przebiegiem). null = bez ustawień, spoza listy albo
     * strona producenta — ta idzie gałęzią producenta jak dotąd.
     */
    private function listSiteShopPosition(string $url, Product $product): ?int
    {
        $position = $this->listSources?->position($url);
        if ($position === null || $this->isKnownManufacturerUrl($url, $product)) {
            return null;
        }

        return $position;
    }

    /**
     * Strona producenta przypisanego świadomie: domena z konfiguracji (isOfficialCatalogUrl) albo przypisana producentowi
     * w Administracji → „Strony wyszukiwarka” (assignedDomainsFor). Domeny wykryte automatem („discovered”, odgadnięte
     * z wyników przez discoverFromResults) tu nie wchodzą.
     */
    private function isKnownManufacturerUrl(string $url, Product $product): bool
    {
        if ($this->identity->isOfficialCatalogUrl($url, $product)) {
            return true;
        }
        $host = preg_replace('/^www\./', '', mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''))) ?? '';
        if ($host === '') {
            return false;
        }
        foreach ($this->listSourcesManufacturerDomains as $domain) {
            $domain = preg_replace('/^www\./', '', mb_strtolower(trim((string) $domain))) ?? '';
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }

    /**
     * discoverFromResults uznaje host za domenę marki po samej nazwie („portwest-sklep.pl” przy Portwest). Host cennika
     * z pliku, którego nie znaliśmy jako domeny producenta przed przebiegiem, to w tym przebiegu sklep — inaczej jego
     * strona szłaby gałęzią producenta (fetch dokumentów, źródło „manufacturer”).
     *
     * @param  list<string>  $domains
     * @return list<string>
     */
    private function withoutListSitesAsManufacturer(Product $product, array $domains): array
    {
        if ($this->listSources === null) {
            return $domains;
        }
        $kept = [];
        $dropped = [];
        foreach ($domains as $domain) {
            $url = 'https://'.preg_replace('/^www\./', '', mb_strtolower(trim((string) $domain))).'/';
            if ($this->listSiteShopPosition($url, $product) !== null) {
                $dropped[] = (string) $domain;

                continue;
            }
            $kept[] = $domain;
        }
        $message = 'strony cennika '.$this->listSources->manufacturer.' — host cennika to sklep, nie domena producenta: '
            .implode(', ', array_values(array_unique($dropped)));
        // wołane dwa razy w przebiegu (wyniki wyszukiwania, potem dokumenty) — ten sam wpis raz
        if ($dropped !== [] && ! in_array($message, $this->attemptLog()->messagesOfType('search', includeReplayed: false), true)) {
            $this->attemptLog()->add('search', $message);
        }

        return array_values($kept);
    }

    /**
     * Wyniki ze stron cennika z pliku przed wynikami zwykłego szukania: najpierw indeks lokalny ograniczony do hostów
     * cennika, a gdy nie dał trafienia z kodem karty — site: na pierwszych MAX_SITE_HOSTS hostach. Te same filtry co
     * zwykłe wyniki (listing, hosty wykluczone), bez powtórzeń adresów. Drugi element: błędy wyszukiwarki z site:.
     *
     * @param  array{results: list<array<string, mixed>>, errors?: list<string>}  $pack
     * @return array{0: array{results: list<array<string, mixed>>, errors?: list<string>}, 1: list<string>}
     */
    private function withPriceListSiteResults(Product $product, array $pack): array
    {
        $settings = $this->listSources;
        if ($settings === null) {
            return [$pack, []];
        }
        $label = 'strony cennika '.$settings->manufacturer;
        $hits = $this->listSiteHits($product, $this->search->catalogHitsOnHosts($product, $settings->hosts, $label));
        $errors = [];
        if (! $this->hitsCarryProductCode($hits, $product)) {
            $found = $this->search->searchOnHosts($product, array_slice($settings->hosts, 0, self::MAX_SITE_HOSTS), $label);
            $errors = $this->search->lastHostSearchErrors();
            $hits = [...$hits, ...$this->listSiteHits($product, $found)];
        }

        $seen = [];
        $fresh = [];
        foreach ($hits as $row) {
            $key = mb_strtolower((string) ($row['url'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $fresh[] = $row;
        }
        $rest = [];
        foreach (is_array($pack['results'] ?? null) ? $pack['results'] : [] as $row) {
            $key = mb_strtolower((string) ($row['url'] ?? ''));
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            $rest[] = $row;
        }
        $pack['results'] = [...$fresh, ...$rest];
        $this->attemptLog()->add(
            'search',
            $label.' — '.count($fresh).' adresów'.($errors !== [] ? ' (błędy wyszukiwarki: '.count($errors).')' : ''),
            urls: array_column($fresh, 'url')
        );

        return [$pack, $errors];
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    private function listSiteHits(Product $product, array $hits): array
    {
        $settings = $this->listSources;
        if ($settings === null || $hits === []) {
            return [];
        }

        return array_values(array_filter(
            $this->dropBlockedSourceHosts($this->search->dropListingResults($hits, $product), $product),
            static fn (array $row): bool => $settings->covers((string) ($row['url'] ?? ''))
        ));
    }

    /** @param  list<array<string, mixed>>  $hits */
    private function hitsCarryProductCode(array $hits, Product $product): bool
    {
        foreach ($hits as $row) {
            $hay = mb_strtolower(rawurldecode((string) ($row['url'] ?? '')).' '.(string) ($row['title'] ?? ''));
            if (trim($hay) !== '' && $this->identity->hayHasProductCode($hay, $product)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bez karty producenta: strona z hostu cennika z treścią (≥ MFR_CARD_MIN_CHARS) i z kodem tej karty (bramka wariantu
     * supplementPageNamesCardVariant) → zostają strony cennika tego wariantu, katalog PDF i adres zaufany. null = takiej
     * strony nie ma (decyzja o reszcie należy do trybu) albo w puli jest karta znanego producenta (isKnownManufacturerUrl,
     * z treścią) — ta ma pierwszeństwo także wtedy, gdy jego domenę zna tylko Administracja, a nie konfiguracja.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>|null
     */
    private function priceListSitePagesFirst(Product $product, array $pages): ?array
    {
        $settings = $this->listSources;
        if ($settings === null) {
            return null;
        }
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '' && mb_strlen(trim((string) ($page['text'] ?? ''))) >= self::MFR_CARD_MIN_CHARS
                && $this->isKnownManufacturerUrl($url, $product)) {
                $this->attemptLog()->add(
                    'page',
                    'strony cennika '.$settings->manufacturer.' — w puli jest karta producenta, strony cennika bez pierwszeństwa',
                    urls: [$url]
                );

                return null;
            }
        }
        $variantOk = [];
        $hasCard = false;
        $otherVariant = [];
        foreach ($pages as $i => $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url === '' || ! $settings->covers($url)) {
                continue;
            }
            if (! $this->supplementPageNamesCardVariant($product, $page)) {
                $otherVariant[] = $url;

                continue;
            }
            $variantOk[$i] = true;
            if (mb_strlen(trim((string) ($page['text'] ?? ''))) >= self::MFR_CARD_MIN_CHARS) {
                $hasCard = true;
            }
        }
        if ($otherVariant !== []) {
            $this->attemptLog()->add(
                'page',
                'strony cennika '.$settings->manufacturer.' — strona innego wariantu albo bez kodu karty, bez pierwszeństwa',
                urls: $otherVariant
            );
        }
        if (! $hasCard) {
            return null;
        }
        $kept = [];
        $dropped = [];
        foreach ($pages as $i => $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '' && (isset($variantOk[$i])
                || $this->catalogPdf()->isConfiguredCatalogUrl($url)
                || $product->isTrustedShopUrl($url))) {
                $kept[] = $page;

                continue;
            }
            $dropped[] = $url;
        }
        // kolejność bez zmian: pierwsza pula przyszła już z orderPagesForDescription (strony cennika po pozycji) i z blokiem
        // katalogu PDF na początku (withCatalogPages — katalog jest równorzędny karcie producenta)
        $this->attemptLog()->add(
            'page',
            'strony cennika '.$settings->manufacturer.' — opis ze stron cennika'
                .($dropped !== [] ? ', pominięte inne strony: '.count($dropped) : ''),
            urls: $dropped !== [] ? array_values(array_filter($dropped)) : array_column($kept, 'url')
        );

        return $kept;
    }

    /**
     * Tryb „tylko producent i strony cennika” bez strony z cennika: zostają katalog PDF, strona znanego producenta
     * (isKnownManufacturerUrl) i adres zaufany — sklepy spoza listy nie są źródłem. Pula bywa pusta (enrichProduct
     * kończy wtedy przebieg bez wołania modelu).
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function withoutPagesOutsideListSites(Product $product, array $pages): array
    {
        $kept = [];
        $dropped = [];
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '' && ($this->catalogPdf()->isConfiguredCatalogUrl($url)
                || $this->isKnownManufacturerUrl($url, $product)
                || $product->isTrustedShopUrl($url))) {
                $kept[] = $page;

                continue;
            }
            $dropped[] = $url;
        }
        $this->attemptLog()->add(
            'page',
            'strony cennika '.($this->listSources?->manufacturer ?? '').' — brak strony wyrobu na stronach cennika'
                .($dropped !== [] ? ', pominięte strony spoza cennika: '.count($dropped) : ''),
            urls: array_values(array_filter($dropped))
        );

        return $kept;
    }

    /**
     * Tryb „tylko producent i strony cennika”: ścieżki zapasowe (kolejne partie indeksu, zmapowane sklepy, internet
     * bez indeksu, uzupełnienie opisu) nie pobierają stron spoza hostów cennika, strony znanego producenta
     * (isKnownManufacturerUrl), adresu zaufanego i katalogu PDF. W trybie „najpierw” i bez ustawień — bez zmian.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function dropOutsideListSources(array $results, Product $product): array
    {
        $settings = $this->listSources;
        if ($settings === null || ! $settings->onlyMode()) {
            return $results;
        }
        $kept = [];
        $dropped = [];
        foreach ($results as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url !== '' && ($settings->covers($url)
                || $this->isKnownManufacturerUrl($url, $product)
                || $product->isTrustedShopUrl($url)
                || $this->catalogPdf()->isConfiguredCatalogUrl($url))) {
                $kept[] = $row;

                continue;
            }
            $dropped[] = ['url' => $url, 'reason' => CandidateRejection::OUTSIDE_LIST_SOURCES];
        }
        if ($dropped !== []) {
            $this->attemptLog()->addRejections('strony cennika '.$settings->manufacturer, $dropped);
        }

        return $kept;
    }

    private function listSitesNotFoundMessage(Product $product, string $searchEmptyDetail): string
    {
        $settings = $this->listSources;
        $manufacturer = $settings !== null ? $settings->manufacturer : (string) $product->manufacturer;
        $outage = $this->engineOutageDetail($searchEmptyDetail);
        if ($outage !== null) {
            return 'Wyszukiwarka nie odpowiedziała przy produkcie '.$product->sku
                .' — nie wiadomo, czy strona wyrobu jest na stronach cennika '.$manufacturer
                .'. Ponów po przywróceniu wyszukiwarki. '.$outage;
        }
        $hosts = $settings !== null ? array_slice($settings->hosts, 0, 4) : [];

        return 'Nie znaleziono strony wyrobu '.$product->sku.' na stronach cennika '.$manufacturer
            .($hosts !== [] ? ' ('.implode(', ', $hosts).')' : '')
            .' — tryb „tylko producent i strony cennika”. Opis wpisz ręcznie albo dopisz stronę w Cenniki → Z pliku.';
    }

    /**
     * Link wybrany przez człowieka leży na domenie producenta z konfiguracji (isOfficialCatalogUrl — nie na domenie
     * odgadniętej z wyników) i ta strona jest w puli z treścią karty. Link z synchronizacji B2B tu nie wchodzi
     * (trustedShopUrl), a strona pusta albo niepobrana zostawia sklepy jako źródło.
     *
     * @param  list<array<string, mixed>>  $pages
     */
    private function trustedManufacturerCardInPool(Product $product, array $pages): bool
    {
        $trusted = $product->trustedShopUrl();
        if ($trusted === null || ! $this->identity->isOfficialCatalogUrl($trusted, $product)) {
            return false;
        }
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '' && $product->isTrustedShopUrl($url)
                && mb_strlen(trim((string) ($page['text'] ?? ''))) >= self::MFR_CARD_MIN_CHARS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Blok z katalogu PDF producenta przypisany numerowi katalogowemu wyrobu.
     * Katalog czyta się z cache na dysku, ale ani brak sieci, ani PDF bez warstwy tekstowej
     * nie mogą przerwać wzbogacania — wtedy karta powstaje bez tego źródła.
     *
     * @return list<array{url: string, text: string, title: string}>
     */
    private function manufacturerCatalogPages(Product $product): array
    {
        try {
            $pages = $this->catalogPdf()->pagesFor($product);
        } catch (Throwable $e) {
            Log::info('Katalog PDF producenta pominięty', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
        if ($pages !== []) {
            $this->attemptLog()->add(
                'page',
                'katalog PDF producenta — blok przy nr kat. '.$product->sku,
                urls: array_column($pages, 'url')
            );
        }

        return $pages;
    }

    /** Dłuższy tekst to nie karta jednego wyrobu, tylko katalog albo broszura rodziny. */
    private const PDF_CARD_MAX_CHARS = 6000;

    /**
     * Karta produktu PDF ze strony producenta („Pobierz kartę produktu w pliku PDF”) jako dodatkowa strona źródłowa.
     * Karta AJ GROUP 202: PDF podaje kolory „w białe paski”, rozmiary do 120/75, tabelę wymiarów i normy — czytnik HTML
     * tego nie niósł. Link pochodzi wyłącznie z potwierdzonej karty wyrobu na hoście producenta (ProductPageFetcher);
     * tu dochodzi kontrola treści tymi samymi regułami co dla strony HTML, razem z regułą wariantu po ukośniku.
     * Brak pliku, brak tekstu albo wątpliwość = karta powstaje bez tego źródła.
     *
     * @param  list<mixed>  $documentUrls
     * @return list<array{url: string, text: string, title: string}>
     */
    private function manufacturerPdfCardPages(Product $product, array $documentUrls): array
    {
        try {
            foreach ($documentUrls as $url) {
                if (! is_string($url) || ! ProductDocumentDownloader::looksLikeGeneratedCardUrl($url)) {
                    continue;
                }
                $raw = $this->documents->readGeneratedCard($product, $url);
                $length = $raw === null ? 0 : mb_strlen($raw);
                // tekst ucięty na limicie odczytu = plik dłuższy niż karta
                if ($raw === null || $length < 400 || $length > self::PDF_CARD_MAX_CHARS || $length >= B2bDocumentText::LIMIT) {
                    return [];
                }
                $confirmed = $this->identity->pageHasSkuOrNameAndManufacturer($url, '', $raw, $product)
                    || (! $this->identity->requiresExactSkuOrNameOnCard($product)
                        && $this->identity->isConfirmedProductCard($url, '', $raw, $product));
                if (! $confirmed) {
                    return [];
                }
                $this->attemptLog()->add('page', 'karta produktu PDF ze strony producenta', urls: [$url]);

                return [[
                    'url' => $url,
                    'text' => $this->pdfCardTextForExtraction($raw),
                    'title' => 'Karta produktu PDF producenta — '.$product->sku,
                ]];
            }
        } catch (Throwable $e) {
            Log::info('Karta PDF producenta pominięta', ['product_id' => $product->id, 'sku' => $product->sku, 'error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Treść karty bez stopki firmowej i bez wiersza „link do produktu”: adres kończy się wyborem jednego koloru
     * i rozmiaru (#/kolor-czerwony_w_biale_paski/rozmiar-75_75), co podsuwałoby modelowi jeden wariant zamiast
     * listy z karty. Ligatury druku („specyﬁczne”) rozwijamy; reszta dosłownie — także „-50ºC”.
     */
    private function pdfCardTextForExtraction(string $raw): string
    {
        $lines = [];
        $inLink = false;
        foreach (preg_split('/\R/u', B2bDocumentText::forCard($raw)) ?: [] as $line) {
            // nagłówek wydruku: data złożenia pliku i numer strony („20-09-2026 1/1”) — nie dotyczy wyrobu
            if (preg_match('/^\d{2}-\d{2}-\d{4}\s+\d+\/\d+$/u', $line) === 1) {
                continue;
            }
            if (preg_match('/^link do produktu\b/iu', $line) === 1) {
                $inLink = true;

                continue;
            }
            // zawinięty adres: kolejne wiersze bez spacji, wyglądające na kawałek adresu
            if ($inLink && preg_match('/^\S*[\/_#]\S*$/u', $line) === 1) {
                continue;
            }
            $inLink = false;
            $lines[] = $line;
        }

        return strtr(trim(implode("\n", $lines)), ['ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬀ' => 'ff', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl']);
    }

    /**
     * Katalog producenta wchodzi do puli stron opisowych ZAWSZE, gdy niesie blok przy numerze
     * katalogowym wyrobu — jest źródłem równorzędnym karcie producenta, bo to ten sam autor
     * i ten sam poziom wiarygodności; dla marek bez kart HTML (SECURA) jest jedynym źródłem
     * opisu producenta. Nie zastępuje jednak karty producenta: idzie ZA nią, a przed sklepami,
     * bo karta wyrobu jest dokładniejsza niż akapit w broszurze całej marki. Pominięcie katalogu,
     * gdy karta istnieje, kosztowałoby fakty, których karta nie podaje (zastosowania, normy),
     * a ryzyka nie ma: blok jest przypięty do dokładnego kodu, nie do nazwy.
     *
     * @param  list<array{url: string, text: string}>  $pages
     * @param  list<array{url: string, text: string, title: string}>  $catalogPages
     * @param  list<string>  $mfrDomains
     * @return list<array{url: string, text: string}>
     */
    private function withCatalogPages(array $pages, array $catalogPages, Product $product, array $mfrDomains): array
    {
        if ($catalogPages === []) {
            return $pages;
        }

        $seen = [];
        foreach ($pages as $page) {
            $seen[mb_strtolower(trim((string) ($page['url'] ?? '')))] = true;
        }
        $fresh = [];
        foreach ($catalogPages as $page) {
            $url = mb_strtolower(trim((string) ($page['url'] ?? '')));
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $fresh[] = $page;
        }
        if ($fresh === []) {
            return $pages;
        }

        $at = 0;
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            $text = trim((string) ($page['text'] ?? ''));
            if ($url === '' || mb_strlen($text) < self::MFR_CARD_MIN_CHARS
                || ! $this->manufacturers->isManufacturerUrl($url, $product, $mfrDomains)) {
                break;
            }
            $at++;
        }

        return array_values(array_merge(
            array_slice($pages, 0, $at),
            $fresh,
            array_slice($pages, $at)
        ));
    }

    /**
     * @param  list<array{url: string, text: string}>  $pages
     * @param  list<array{url: string, text: string, title: string}>  $catalogPages
     * @return list<string>
     */
    private function catalogUrlsAmongPages(array $pages, array $catalogPages): array
    {
        $used = [];
        foreach ($pages as $page) {
            $used[mb_strtolower(trim((string) ($page['url'] ?? '')))] = true;
        }

        $out = [];
        foreach ($catalogPages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '' && isset($used[mb_strtolower(trim($url))])) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{url: string, text: string}>  $primary
     * @param  list<array{url: string, text: string}>  $extra
     * @return list<array{url: string, text: string}>
     */
    private function mergePageSnippets(array $primary, array $extra): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($primary, $extra) as $page) {
            $url = mb_strtolower(trim((string) ($page['url'] ?? '')));
            $text = trim((string) ($page['text'] ?? ''));
            if ($url === '' || $text === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = $this->copyPageMeta($page, ['url' => (string) $page['url'], 'text' => $text]);
        }

        return $out;
    }

    /**
     * Uzupełnij puste listy z dosłownych faktów w tekście stron / opisu (bez zmyślania).
     *
     * @param  array<string, mixed>  $extracted
     * @param  list<array{url: string, text: string}>  $pages
     * @return array<string, mixed>
     */
    private function enrichStructuredFieldsFromPages(array $extracted, array $pages, string $description = ''): array
    {
        $hay = $description;
        foreach ($pages as $page) {
            $hay .= "\n".(string) ($page['text'] ?? '');
        }
        $hay = trim($hay);
        if ($hay === '') {
            return $extracted;
        }

        $norms = $this->stringList($extracted['norms'] ?? null);
        if ($norms === []) {
            // Sklep obuwniczy trzyma na kazdej karcie slowniczek klas (S1, S3, EN 20345,
            // EN 13832…) — z takiej strony pas 150 cm dostal siedem norm obuwniczych.
            // Kilka roznych norm naraz z surowego tekstu to lista standardow sklepu,
            // nie normy tego produktu; bez modelu nie umiemy ich rozdzielic.
            $fromText = $this->extractNormsFromText($hay);
            $extracted['norms'] = count($fromText) >= self::NORMS_GLOSSARY_THRESHOLD ? [] : $fromText;
        }

        $materials = $this->stringList($extracted['materials'] ?? null);
        if ($materials === []) {
            $extracted['materials'] = $this->extractMaterialsFromText($hay);
        }

        $useCases = $this->stringList($extracted['use_cases'] ?? null);
        if ($useCases === []) {
            $extracted['use_cases'] = $this->extractUseCasesFromText($hay);
        }

        $specs = $this->stringList($extracted['specs'] ?? null);
        if ($specs === []) {
            $fromSpec = $this->extractLabeledList($hay, 'Specyfikacja');
            if ($fromSpec !== []) {
                $extracted['specs'] = $fromSpec;
            }
        }
        $features = $this->stringList($extracted['features'] ?? null);
        if ($features === []) {
            $fromFeat = $this->extractLabeledList($hay, 'Cechy(?: produktu)?');
            if ($fromFeat !== []) {
                $extracted['features'] = $fromFeat;
            }
        }

        $attrs = is_array($extracted['attributes'] ?? null) ? $extracted['attributes'] : [];
        $category = is_string($attrs['kategoria_bhp'] ?? null) ? $attrs['kategoria_bhp'] : null;
        $claimed = is_string($attrs['rozmiar'] ?? null) ? $attrs['rozmiar'] : null;
        $label = (new ProductSizeVariant)->labelFromTexts($claimed, $hay, $category);
        if ($label !== null) {
            $attrs['rozmiar'] = $label;
            $extracted['attributes'] = $attrs;
        }

        return $extracted;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $specs
     * @param  list<string>  $optionSizes
     * @return array{attributes: array<string, mixed>, packaging: string|null}
     */
    private function applyExtractedSizes(
        Product $product,
        array $attributes,
        array $specs,
        string $description,
        array $optionSizes = [],
    ): array {
        $sizes = new ProductSizeVariant;
        $category = is_string($attributes['kategoria_bhp'] ?? null) ? $attributes['kategoria_bhp'] : null;
        $fromShop = $sizes->filterByCategory($optionSizes, $category);
        if (count($fromShop) < 2 && count($optionSizes) >= 2 && ($category === null || trim($category) === '')) {
            $fromShop = $optionSizes;
        }
        if (count($fromShop) >= 2) {
            $label = $sizes->formatPackaging($fromShop);
            $attributes['rozmiar'] = $label;
            $packaging = null;
            if ($sizes->shouldFillPackaging($product->packaging, $fromShop)) {
                $packaging = $label;
            }

            return ['attributes' => $attributes, 'packaging' => $this->packagingThatFitsColumn($packaging)];
        }
        $blob = implode("\n", array_merge($specs, [$description]));
        $label = $sizes->labelFromTexts(
            is_string($attributes['rozmiar'] ?? null) ? $attributes['rozmiar'] : null,
            $blob,
            $category
        );
        if ($label === null) {
            // Parser czyta rozmiary po słowie kluczowym („Rozmiary: …”). Gdy karta podaje sam
            // token („XXL”), nic nie znajdzie — a dotąd ten pusty wynik i tak nadpisywał wartość
            // z karty. Sięgamy wtedy po to, co mamy: najpierw wskazanie modelu, potem rozmiar
            // pozycji cennika. Kategoria nadal odsiewa bzdury w rodzaju „1-5XL” przy obuwiu.
            $claimed = is_string($attributes['rozmiar'] ?? null) ? $attributes['rozmiar'] : null;
            $label = $sizes->bareLabelForCategory($claimed, $category)
                ?? $sizes->bareLabelForCategory((string) $product->packaging, $category);
        }
        $found = $label !== null ? $sizes->parseSizeList($label) : [];
        if ($found === [] && $label !== null) {
            $found = $sizes->parseSizesFromText($label);
        }
        $attributes['rozmiar'] = $label;
        $packaging = null;
        if ($found !== [] && $sizes->shouldFillPackaging($product->packaging, $found)) {
            $packaging = $sizes->formatPackaging($found);
        }

        return ['attributes' => $attributes, 'packaging' => $this->packagingThatFitsColumn($packaging)];
    }

    /**
     * Kolumna products.packaging ma 120 znaków. Dłuższa lista rozmiarów (RADIM, MOFOS: rozmiary
     * z kilku kart) wywracała zapis gotowego opisu błędem SQL — zostaje wtedy tylko w atrybucie „rozmiar”.
     */
    private function packagingThatFitsColumn(?string $packaging): ?string
    {
        return $packaging !== null && mb_strlen($packaging) <= self::PACKAGING_MAX_LENGTH ? $packaging : null;
    }

    /**
     * @param  list<array{url?: string, text?: string, accessories?: list<array<string, mixed>>}>  $pages
     */
    private function rememberAccessories(Product $product, array $pages): void
    {
        $candidates = [];
        $seen = [];
        foreach ($pages as $page) {
            $rows = $page['accessories'] ?? [];
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $key = mb_strtolower(trim((string) ($row['sku'] ?? '')).'|'.trim((string) ($row['name'] ?? '')));
                if ($key === '|' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $candidates[] = $row;
            }
        }
        if ($candidates === []) {
            return;
        }
        try {
            $this->accessories()->matchFromPages($product, $candidates);
        } catch (Throwable) {
        }
    }

    /**
     * @param  list<array{url?: string, text?: string, option_sizes?: list<string>}>  $pages
     * @return list<string>
     */
    private function collectOptionSizes(array $pages, ?string $category = null): array
    {
        $lists = [];
        foreach ($pages as $page) {
            $row = $page['option_sizes'] ?? [];
            if (! is_array($row)) {
                continue;
            }
            $tokens = [];
            foreach ($row as $size) {
                if (is_string($size) && trim($size) !== '') {
                    $tokens[] = $size;
                }
            }
            if (count($tokens) >= 2) {
                $lists[] = $tokens;
            }
        }

        return (new ProductSizeVariant)->pickBestSizeList($lists, $category);
    }

    /**
     * @param  array<string, mixed>|null  $attributes
     */
    private function sizeCategoryHint(Product $product, ?array $attributes = null): ?string
    {
        if (is_string($attributes['kategoria_bhp'] ?? null) && trim((string) $attributes['kategoria_bhp']) !== '') {
            return (string) $attributes['kategoria_bhp'];
        }
        if (is_string($product->category) && trim($product->category) !== '') {
            return $product->category;
        }
        $name = mb_strtolower((string) $product->name);
        if (str_contains($name, 'rękaw') || str_contains($name, 'rekaw') || str_contains($name, 'glove')) {
            return 'rekawice';
        }
        if (preg_match('/\b(but|półbut|polbut|obuwie|trzewik|sanda[łl])\w*/u', $name) === 1) {
            return 'obuwie';
        }

        return null;
    }

    /**
     * @param  list<array{url?: string, text?: string, option_sizes?: list<string>}>  $pages
     * @param  list<string>  $sizes
     * @return list<array{url?: string, text?: string, option_sizes?: list<string>}>
     */
    private function rememberOptionSizes(array $pages, array $sizes): array
    {
        if ($pages === []) {
            return [];
        }
        $best = $this->mergeOptionSizeLists($this->collectOptionSizes($pages), $sizes);
        if ($best === []) {
            return $pages;
        }
        $pages[0]['option_sizes'] = $best;

        return $pages;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function mergeOptionSizeLists(array $left, array $right): array
    {
        return count($right) > count($left) ? $right : $left;
    }

    /** @return list<string> */
    private function extractLabeledList(string $text, string $heading): array
    {
        if (preg_match(
            '/(?:^|\n)\s*'.$heading.'\s*:?\s*\n+(.+?)(?=\n\s*(?:[A-ZĄĆĘŁŃÓŚŹŻ][^\n]{0,48}\s*:?\s*\n|#{1,5}\s|\z))/isu',
            $text,
            $m
        ) !== 1) {
            return [];
        }
        $block = trim($m[1]);
        $out = [];
        foreach (preg_split('/\n+|;\s+(?=[A-ZĄĆĘŁŃÓŚŹŻ])/u', $block) ?: [] as $line) {
            $line = trim((string) preg_replace('/^[\-\*•]+\s*/u', '', trim((string) $line)));
            if ($line === '' || mb_strlen($line) < 12) {
                continue;
            }
            $out[] = $line;
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function extractNormsFromText(string $text): array
    {
        $out = [];
        if (preg_match_all(
            '/\bEN(?:\s*ISO)?\s*\d{3,5}(?::\d{4})?(?:\s*[+\-]?\s*[A-Z0-9][A-Z0-9\sXx\/\-]{0,24})?/iu',
            $text,
            $m
        )) {
            foreach ($m[0] as $raw) {
                $norm = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? (string) $raw);
                $norm = rtrim($norm, '.,;:)');
                if (mb_strlen($norm) >= 6) {
                    $out[] = $norm;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function extractMaterialsFromText(string $text): array
    {
        $low = mb_strtolower($text);
        $map = [
            'wiskoza bambusowa' => ['wiskoza bambusowa', 'bamboo viscose', 'viscose bamboo'],
            'Dyneema' => ['dyneema'],
            'szkło' => ['szkło', 'glass fibre', 'glass fiber', 'włókno szklane'],
            'poliamid' => ['poliamid', 'polyamide', 'nylon'],
            'nitryl' => ['nitryl', 'nitrile'],
            'lateks' => ['lateks', 'latex'],
            'HPPE' => ['hppe'],
            'poliuretan' => ['poliuretan', 'polyurethane', 'pu coating'],
            'wysokowydajny winyl (HPV)' => ['hpv', 'high performance vinyl', 'wysokowydajny winyl'],
            'skóra' => ['skóra licowa', 'skóra bydlęca', 'full grain'],
        ];
        $out = [];
        foreach ($map as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($low, $needle)) {
                    $out[] = $label;
                    break;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function extractUseCasesFromText(string $text): array
    {
        $low = mb_strtolower($text);
        $map = [
            'montaż precyzyjny' => ['montaż', 'assembly', 'precyzyj'],
            'przemysł metalowy' => ['metalurg', 'metalow', 'metal industry', 'blachar'],
            'budownictwo' => ['budown', 'construction', 'construction site'],
            'logistyka i magazyn' => ['logist', 'magazyn', 'warehouse', 'handling'],
            'przemysł szklarski' => ['szklar', 'glass industry'],
            'prace z ryzykiem przecięcia' => ['przecię', 'cut resist', 'cięcie', 'cut protection'],
            'warunki suche' => ['warunki suche', 'dry conditions', 'dry environments'],
        ];
        $out = [];
        foreach ($map as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($low, $needle)) {
                    $out[] = $label;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergeExtracted(array $base, array $extra, ?Product $product = null): array
    {
        foreach (['features', 'specs', 'norms', 'certificates', 'materials', 'use_cases', 'image_urls', 'document_urls', 'source_urls'] as $key) {
            $a = $this->stringList($base[$key] ?? null);
            $b = $this->stringList($extra[$key] ?? null);
            $merged = array_values(array_unique(array_merge($a, $b)));
            // Normy z dwóch kart różnią się zwykle samym zapisem (rok, poprawka, spacja)
            // — bez kanonizacji karta dostawała każdą z nich po dwa razy.
            $base[$key] = in_array($key, ['norms', 'certificates'], true)
                ? NormCode::dedupe($merged)
                : $merged;
        }
        if (is_array($extra['attributes'] ?? null)) {
            $base['attributes'] = $this->bhpAttributes->normalize(
                array_merge(
                    is_array($base['attributes'] ?? null) ? $base['attributes'] : [],
                    $extra['attributes']
                ),
                [
                    'materials' => $this->stringList($base['materials'] ?? null),
                    'norms' => $this->stringList($base['norms'] ?? null),
                    'specs' => $this->stringList($base['specs'] ?? null),
                    'certificates' => $this->stringList($base['certificates'] ?? null),
                    // tożsamość wyrobu, cennik i tabelka dostawcy — bez nich klasa z drugiej tury stron
                    // (cudzy wariant) wygrywała z klasą z nazwy
                    'category' => (string) $product?->categoryAsEvidence(),
                    'sku' => (string) ($product?->sku ?? ''),
                    'name' => (string) ($product?->name ?? ''),
                    // kolumna norm to wynik poprzedniego wzbogacania, nie tożsamość — patrz payloadFromExtraction
                    'norms_column' => '',
                    'shop_fields' => (string) ($product?->shop_fields_summary ?? ''),
                    'price_list' => is_array($product?->price_list_attributes) ? $product->price_list_attributes : [],
                ]
            );
        }
        if ((float) ($extra['confidence'] ?? 0) > (float) ($base['confidence'] ?? 0)) {
            $base['confidence'] = $extra['confidence'];
        }

        return $base;
    }

    /**
     * PDF z domeny producenta lub CDN z kodem SKU w nazwie (np. uvex → cloudfront …/60028_….pdf).
     *
     * @param  list<string>  $urls
     * @param  list<string>  $domains
     * @return list<string>
     */
    private function preferManufacturerDocuments(array $urls, Product $product, array $domains): array
    {
        $urls = array_values(array_unique(array_filter($urls, static fn ($u): bool => is_string($u) && str_starts_with($u, 'http'))));
        $mfr = [];
        $skuHit = [];
        $otherPdf = [];
        foreach ($urls as $url) {
            if ($this->manufacturers->isManufacturerUrl($url, $product, $domains)) {
                $mfr[] = $url;
            } elseif ($this->pdfUrlMentionsProduct($url, $product)) {
                $skuHit[] = $url;
            } elseif (ProductDocumentDownloader::looksLikePdfUrl($url)) {
                $otherPdf[] = $url;
            }
        }

        // producent → PDF z SKU w URL → dopiero potem pozostałe PDF (sklep/dystrybutor)
        if ($mfr !== []) {
            return $mfr;
        }
        if ($skuHit !== []) {
            return $skuHit;
        }

        return $otherPdf;
    }

    private function pdfUrlMentionsProduct(string $url, Product $product): bool
    {
        if (! ProductDocumentDownloader::looksLikePdfUrl($url)) {
            return false;
        }
        $hay = mb_strtolower(urldecode($url));
        $sku = mb_strtolower(trim((string) $product->sku));
        // dokładny kod albo prefiks (uvex: SKU 60549 w URL …6054907…)
        if ($sku !== '' && (
            preg_match('/(?<![0-9])'.preg_quote($sku, '/').'(?![0-9])/u', $hay)
            || (preg_match('/^\d{4,}$/', $sku) === 1 && preg_match('/(?<![0-9])'.preg_quote($sku, '/').'\d*/u', $hay))
        )) {
            return true;
        }
        // uvex / ATG: art. 60549 często jako 60549_ / 60549- w nazwie pliku
        if ($sku !== '' && preg_match('/^\d{4,}$/', $sku)
            && (str_contains($hay, $sku.'_') || str_contains($hay, $sku.'-') || str_contains($hay, '/'.$sku.'.'))) {
            return true;
        }
        $name = mb_strtolower(trim((string) $product->name));
        if ($name !== '' && preg_match('/\b(\d{1,2}-\d{3})\b/', $sku.' '.$name, $m)) {
            return (bool) preg_match('/(?<![0-9])'.preg_quote($m[1], '/').'(?![0-9])/u', $hay);
        }
        // nazwa handlowa w URL (np. c300-dry, c300dry)
        $tokens = preg_split('/\s+/u', $name) ?: [];
        $nameSlug = implode('-', array_filter(array_map(
            static fn (string $t): string => (string) preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($t)),
            array_slice($tokens, 0, 3)
        )));
        if ($nameSlug !== '' && mb_strlen($nameSlug) >= 5 && str_contains($hay, $nameSlug)) {
            return true;
        }
        $compact = preg_replace('/[^a-z0-9]+/i', '', $name) ?? '';
        $hayCompact = preg_replace('/[^a-z0-9]+/i', '', $hay) ?? '';

        return $compact !== '' && mb_strlen($compact) >= 6 && str_contains($hayCompact, $compact);
    }

    /** Publiczna także dla raportu products:audit-source-identity (zapisane opisy „brak danych o produkcie…”). */
    public function looksLikeMissingCardMeta(string $description): bool
    {
        $d = mb_strtolower($description);

        return str_contains($d, 'nie znaleziono')
            || str_contains($d, 'nie udało się znaleźć')
            || str_contains($d, 'brak szczegółowej karty')
            || str_contains($d, 'na podstawie samej nazwy')
            || str_contains($d, 'wyniki wyszukiwania wskazują')
            // audyt 22.09.2026: „Brak danych o produkcie… Wyniki wyszukiwania dotyczą…” (8 opisów)
            || str_contains($d, 'brak danych o produkcie')
            || str_contains($d, 'wyniki wyszukiwania dotyczą')
            || str_contains($d, "you don't have permission to access")
            || str_contains($d, 'you do not have permission')
            || str_contains($d, 'access denied')
            || str_contains($d, '403 forbidden');
    }

    /** Krótki slogan sklepu / og:description zamiast pełnego opisu technicznego. */
    private function looksLikeThinDescription(string $description): bool
    {
        $d = trim($description);
        if (mb_strlen($d) < 180) {
            return true;
        }
        if (ProductPageFetcher::looksLikeTruncatedShopTeaser($d)) {
            return true;
        }
        $low = mb_strtolower($d);
        if ($this->looksLikeShopChromeDescription($d) || $this->looksLikeOffTopicDescription($d)
            || $this->looksLikeLinkDump($d) || $this->looksLikeCategoryIndexDescription($d)
            || ProductPageFetcher::looksLikeCompanyImprint($d)) {
            return true;
        }

        return str_contains($low, 'sprawdź na')
            || str_contains($low, 'kup online')
            || str_contains($low, 'ceny i opinie')
            || (substr_count($d, '.') <= 1 && mb_strlen($d) < 280);
    }

    /** Menu sklepu przepisane jako lista odnośników („Popular Styles”), nie opis. */
    private function looksLikeLinkDump(string $description): bool
    {
        $links = preg_match_all('#\]\(https?://#i', $description);
        if ($links !== false && $links >= 2) {
            return true;
        }

        $urls = preg_match_all('#https?://#i', $description);

        return $urls !== false && $urls >= 3;
    }

    /** Skopiowany chrome sklepu zamiast opisu produktu. */
    private function looksLikeRawLocaleDump(string $description): bool
    {
        return ProductPageFetcher::looksLikeRawLocaleDump($description)
            || ProductPageFetcher::looksLikeCookieConsent($description);
    }

    /**
     * Opis produktu jest po polsku i jest opisem, nie zrzutem strony. Fallback z karty
     * przepuszczał angielski tekst coba.com, bo niósł kody wariantów i słowo „Matting”,
     * a z cache SKU kopiował się dalej na rodzeństwo.
     */
    private function looksLikeForeignOrPartsTableDump(string $description): bool
    {
        return ProductDescriptionText::looksLikeForeignOrPartsTableDump($description);
    }

    private function looksLikeShopChromeDescription(string $description): bool
    {
        if ($this->looksLikeRawLocaleDump($description)
            || ProductPageFetcher::looksLikeShopOfferDump($description)) {
            return true;
        }
        $low = mb_strtolower($description);
        $hits = 0;
        foreach ([
            'logowanie', 'rejestracja', 'do koszyka', 'obserwowane', 'realizuj zamówienie',
            'polityka prywatności', 'łatwy zwrot', 'jesteś tutaj', 'wyszukiwanie zaawansowane',
            'odstąpienie od umowy', 'kup za punkty', 'sprawdź status zamówienia',
            'administrator danych osobowych', 'przetwarzamy je w celu', 'widżet języka',
            'so finden sie uns', 'google-maps', 'impressum', 'herausgeber',
            'czas wysyłki', 'indywidualna wycena', 'zapytaj o wycenę',
            'polityka bezpieczeństwa', 'zasady dostawy', 'zasady zwrotu',
            'zoom_out_map', 'chevron_left',
        ] as $needle) {
            if (str_contains($low, $needle)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    private function looksLikeOffTopicDescription(string $description): bool
    {
        $low = mb_strtolower($description);
        foreach ([
            'real estate', 'nieruchomoś', 'leasing opportunity', 'investment or leasing',
            'office, industrial or commercial', 'multi-family housing',
            'powierzchni biurow', 'wynajmu nieruchomości', 'cushman', 'colliers', 'cbre',
            'oreillyauto', 'crankcase', 'breather hose', 'standard ignition',
            'reversible ratchet', 'socket wrenches', 'hand tools >',
            'vde approved', 'vde/1000v',
        ] as $needle) {
            if (str_contains($low, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Spis kategorii sklepu / menu BHP przepisane jako „opis”. */
    private function looksLikeCategoryIndexDescription(string $description): bool
    {
        $text = trim($description);
        if ($text === '') {
            return false;
        }
        $low = mb_strtolower($text);
        if (preg_match_all(
            '/\b(polski|english|deutsch|français|francais|italiano|español|espanol|nederlands)\b/u',
            $low
        ) >= 5) {
            return true;
        }

        $chunks = preg_split('/(?:\r\n|\n|\r|(?<=\s)[\*\-•]\s+)/u', $text) ?: [];
        $headings = 0;
        $families = [];
        foreach ($chunks as $chunk) {
            $line = Utf8Trim::trim((string) $chunk, " \t-*•");
            if ($line === '' || mb_strlen($line) > 70) {
                continue;
            }
            if (preg_match('/[.!?]{1}.{12,}/u', $line) === 1) {
                continue;
            }
            $norm = mb_strtolower($line);
            foreach ([
                'buty', 'obuwie', 'rekawic', 'kask', 'amortyzator', 'linki asekur',
                'zestawy asekur', 'szelk', 'odziez', 'spodnie', 'kurtk', 'chodnik',
                'dywanik', 'sprzet', 'urzadzenia', 'splopl',
            ] as $needle) {
                if (! str_contains($norm, $needle)) {
                    continue;
                }
                $headings++;
                $families[$needle] = true;
                break;
            }
        }

        return $headings >= 6 && count($families) >= 3;
    }

    /**
     * @param  list<string>  $sourceUrls  potwierdzone karty, z których model napisał opis — tylko dla
     *                                    opisu modelu; tekst karty (fallbacki) sprawdzamy bez nich
     */
    private function isUsableProductDescription(string $description, Product $product, array $sourceUrls = []): bool
    {
        $d = trim($description);
        if ($d === '' || $this->looksLikeMissingCardMeta($d) || $this->looksLikeThinDescription($d)
            || $this->looksLikeRawLocaleDump($d) || ProductPageFetcher::looksLikeShopOfferDump($d)
            || $this->looksLikeForeignOrPartsTableDump($d)) {
            return false;
        }
        if ($product->trustedShopUrl() !== null) {
            return true;
        }

        return $this->descriptionMentionsProduct($d, $product, $sourceUrls);
    }

    /**
     * @param  list<array{url?: string, text?: string}>  $pages
     * @return list<array{url: string, text: string}>
     */
    private function keepConfirmedCardPages(Product $product, array $pages): array
    {
        $out = [];
        // Przed bramkami tożsamości: pobrana strona mogła przyjść innym adresem i przekierować na stronę odrzuconą przez
        // handlowca (final_url) — kandydatów przed pobraniem filtruje dropBlockedSourceHosts.
        foreach ($this->withoutRejectedSources($pages, $product) as $page) {
            $url = (string) ($page['url'] ?? '');
            $text = (string) ($page['text'] ?? '');
            $title = (string) ($page['title'] ?? '');
            if ($url === '') {
                continue;
            }
            // Sam fragment z wyszukiwarki (strona się nie otworzyła) nie jest kartą. Potwierdzony
            // samym adresem ucinał dalsze szukanie, a model nie miał z czego napisać opisu.
            if (! empty($page['snippet_only']) && mb_strlen(trim($text)) < self::SNIPPET_CARD_MIN_CHARS) {
                continue;
            }
            $named = $this->identity->pageHasSkuOrNameAndManufacturer($url, $title, $text, $product);
            $exact = $this->identity->requiresExactSkuOrNameOnCard($product);
            $ok = $product->isTrustedShopUrl($url)
                || $named
                || (! $exact && $this->identity->isConfirmedProductCard($url, $title, $text, $product));
            if (! $ok) {
                continue;
            }
            if ($text === '') {
                $text = $title !== '' ? $title : $url;
            }
            $out[] = $this->copyPageMeta($page, ['url' => $url, 'text' => $text, 'title' => $title]);
        }

        return $out;
    }

    /**
     * @param  list<array{url?: string, text?: string}>  $pages
     */
    private function sourceUrlIsConfirmedCard(string $url, array $pages, Product $product): bool
    {
        if ($product->isTrustedShopUrl($url)) {
            return true;
        }
        foreach ($pages as $page) {
            if (mb_strtolower((string) ($page['url'] ?? '')) === mb_strtolower($url)) {
                return $this->identity->isConfirmedProductCard(
                    $url,
                    '',
                    (string) ($page['text'] ?? ''),
                    $product
                );
            }
        }

        return $this->identity->isConfirmedProductCard($url, '', '', $product);
    }

    /**
     * Opis wolno przypisać dopiero wtedy, gdy sam nazywa produkt po kodzie albo modelu.
     * Bez tego karta obcego produktu z tej samej branży przechodziła jako nasza.
     */
    /**
     * @param  list<string>  $sourceUrls  potwierdzone karty, z których model napisał opis. Karta
     *                                    z domeny producenta potwierdza markę: model pisze
     *                                    „Deckplate”, „COBAstat”, a nie osobne „Coba” (batch #300).
     *                                    Publiczna także dla raportu products:audit-source-identity.
     */
    public function descriptionMentionsProduct(string $description, Product $product, array $sourceUrls = []): bool
    {
        $hay = mb_strtolower($description);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
        $officialSource = '';
        foreach ($sourceUrls as $url) {
            if (is_string($url) && $url !== '' && $this->identity->isOfficialCatalogUrl($url, $product)) {
                $officialSource = $url;
                break;
            }
        }
        $brandConfirmed = $officialSource !== '' || $this->identity->hayHasBrand($hay, $product);
        // Canis: opis z kodem „3420-115” (MERU) bez naszego „3420-007” to opis cudzego modelu,
        // choćby marka i rodzaj się zgadzały.
        if ($this->identity->textNamesAnotherGroupedCode($description, $product)) {
            return false;
        }

        if ($this->identity->hayHasProductCode($hay, $product)) {
            if ($this->identity->pageAgreesWithBrandAndName($hay, $officialSource, $product)
                && $this->identity->hayHasRequiredTypeFromName($hay, $product)) {
                return true;
            }

            // Sklep nie pisze CABINAID — T-31 + tablica + AED wystarcza w opisie.
            return $this->identity->skuIsSharedShortCode($product)
                && $this->identity->hayHasRequiredTypeFromName($hay, $product)
                && $this->identity->hayHasSpecificNameToken($hay, $product);
        }
        if ($this->identity->looksLikeUnrelatedSignage($hay, $product)) {
            return false;
        }
        if ($this->identity->hayHasShopIdentity($hay, $hayCompact, $product)
            && $brandConfirmed) {
            return $this->identity->hayHasRequiredTypeFromName($hay, $product);
        }
        // sklep bez SKU, ale z pełną nazwą („Dywanik elektroizolacyjny 20 KV”). Nazwę liczymy bez
        // wymiarów: model dostaje ją w zapytaniu i przepisuje „Rib Mat Czarny 0.9m x 15.3m (12.5mm)”
        // do opisu cudzej karty — sam przepisany wymiar robił z krótkiej nazwy „pełną frazę” (batch #301).
        $withoutDimensions = clone $product;
        $withoutDimensions->name = trim((string) preg_replace(
            '/~?\d+(?:[.,]\d+)?\s*(?:mm|cm|m|kg|g|ml|l)\b|\(\s*\)|\s+x\s+(?=\s|$)/iu',
            ' ',
            (string) $product->name
        ));
        if ($this->identity->hayHasDistinctiveNamePhrase($description, $withoutDimensions)) {
            return $this->identity->hayHasRequiredTypeFromName($hay, $product);
        }

        $tokens = $this->discriminativeNameTokens($product);
        $score = 0;
        foreach ($tokens as $token => $weight) {
            if ($this->hayHasNameToken($hay, (string) $token)) {
                $score += $weight;
            }
        }
        // Sama marka nie wystarcza — pod „Urgent” idzie pół katalogu — ale razem
        // ze słowem z nazwy domyka potwierdzenie.
        if ($score > 0 && $brandConfirmed) {
            $score++;
        }
        if ($score >= 2) {
            return $this->identity->hayHasRequiredTypeFromName($hay, $product);
        }

        // Nazwa z samych słów ogólnych („Rękawice robocze”) nie da się potwierdzić kodem —
        // wtedy o zgodności decyduje marka razem z rodzajem środka ochrony.
        if ($tokens === []) {
            return $this->matchesBrandAndFamily($hay, $product);
        }

        return false;
    }

    /**
     * Słowa z nazwy, które faktycznie odróżniają model: numer serii i oznaczenia z cyfrą
     * ważą podwójnie, zwykłe słowo pojedynczo, a branżowe ogólniki wcale.
     *
     * @return array<string, int>
     */
    private function discriminativeNameTokens(Product $product): array
    {
        $tokens = [];
        // Wymiary z cennika („0.9m x 15.3m (12.5mm)”) nie odróżniają modelu: po podziale na
        // kropce „15” i „12” ważyły jak kod, a model przepisuje nazwę z zapytania do opisu.
        // Opis ReGen 100 z cudzej karty przechodził jako „Rib Mat” (batch #301).
        $name = preg_replace('/~?\d+(?:[.,]\d+)?\s*(?:mm|cm|m|kg|g|ml|l)\b/iu', ' ', mb_strtolower((string) $product->name)) ?? '';
        foreach (preg_split('/[\s\-®™\/_,.()]+/u', $name) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || in_array($token, self::GENERIC_NAME_TOKENS, true)) {
                continue;
            }
            // „150cm” wyglada jak kod (cyfry + litery), a jest wymiarem — pas 150 cm
            // dostal przez to karte sznurowadel 150 cm i jej normy obuwnicze
            if ($this->identity->isBareMeasurement($token)) {
                continue;
            }
            if (preg_match('/^\d{2,}$/u', $token) === 1
                || preg_match('/^(?=.*\d)(?=.*\p{L})[\p{L}\d]{3,}$/u', $token) === 1) {
                $tokens[$token] = 2;
            } elseif (preg_match('/^\p{L}{4,}$/u', $token) === 1) {
                $tokens[$token] = 1;
            }
        }

        return $tokens;
    }

    private function matchesBrandAndFamily(string $hay, Product $product): bool
    {
        $brand = mb_strtolower($this->identity->shortBrand((string) $product->manufacturer));
        if ($brand === '' || ! $this->hayHasToken($hay, $brand)) {
            return false;
        }

        $family = $this->assortment->family(
            trim($product->name.' '.$product->sku.' '.(string) $product->category)
        );

        return $family === null || $family === $this->assortment->family($hay);
    }

    private function hayHasToken(string $hay, string $token): bool
    {
        $token = mb_strtolower(trim($token));
        if (mb_strlen($token) < 2) {
            return false;
        }

        return preg_match('/(^|[^\p{L}\d])'.preg_quote($token, '/').'([^\p{L}\d]|$)/iu', $hay) === 1;
    }

    /**
     * Słowo z nazwy w opisie także w innej formie: „Guma nitrylowa” → „arkusz gumy nitrylowej”.
     * Model pisze po polsku z odmianą; dokładna forma zerowała dobry opis NIS000 (batch #301).
     * Rdzeń tylko dla długich słów z samych liter — kody i liczby muszą pasować dokładnie.
     */
    private function hayHasNameToken(string $hay, string $token): bool
    {
        if ($this->hayHasToken($hay, $token)) {
            return true;
        }
        $token = mb_strtolower(trim($token));
        if (mb_strlen($token) < 7 || preg_match('/^\p{L}+$/u', $token) !== 1) {
            return false;
        }
        $stem = mb_substr($token, 0, mb_strlen($token) - 2);

        return preg_match('/(^|[^\p{L}\d])'.preg_quote($stem, '/').'\p{L}{0,4}([^\p{L}\d]|$)/iu', $hay) === 1;
    }

    private function isJunkImageUrl(string $url): bool
    {
        if (ProductImageDownloader::isManufacturerSiteGraphicUrl($url) || ProductImageDownloader::isSiteIdentityGraphicUrl($url)) {
            return true;
        }
        $u = mb_strtolower($url);
        $blocked = [
            'logo', 'icon', 'sprite', 'favicon', 'banner', 'payment',
            'dhl', 'inpost', 'poczta', 'ups', 'fedex', 'dpd', 'gls',
            'koszyk', 'wallet', 'payu', 'przelewy', 'blik',
            'ochronki na buty', 'shoe-cover', 'shoe_cover', 'nakladki', 'folie-na',
            'placeholder', 'blank', 'pixel', 'bg_environment', 'environment_oily', '.svg',
            '/upload/img/seo/', '/upload/img/icons/', '/upload/img/logo/',
            'loader', 'spinner', 'loading', 'preloader', 'ajax-loader', 'load.gif',
            'loading.gif', 'loader-1', 'loader-2', 'progress.gif',
            'menue-', 'menu-', '/01_menue', 'menue-pics', 'world-map', 'sitemap',
            'beer', 'fox-deluxe', 'sustainability_report', 'lego',
            '#screenshot', 'screenshot',
            // materiały marketingowe z serwera mediów producenta: baner branżowy
            // i przewodnik po asortymencie ładowały się jako poprawne PNG/JPG
            'feature-image', 'reference-guide',
        ];
        foreach ($blocked as $needle) {
            if (str_contains($u, $needle)) {
                return true;
            }
        }

        return preg_match('#(?<![a-z])cart(?![a-z])#', $u) === 1;
    }

    public function markBatchItem(
        ProductEnrichmentBatch $batch,
        bool $success,
        ?Product $product = null,
        ?string $itemStatus = null,
        ?string $message = null,
    ): void {
        if ($batch->status === ProductEnrichmentBatch::STATUS_CANCELLED || $batch->isCancelled()) {
            if ($product !== null) {
                $this->recordBatchProduct(
                    $batch,
                    $product,
                    ProductEnrichmentBatchItem::STATUS_CANCELLED,
                    $message ?? 'Anulowano przez użytkownika',
                );
            }

            return;
        }

        if ($success) {
            $batch->increment('done');
        } else {
            $batch->increment('failed');
        }
        $batch->refresh();
        $batch->refreshStatus();
        if ($product !== null) {
            $this->recordBatchProduct(
                $batch,
                $product,
                $itemStatus ?? ($success
                    ? ProductEnrichmentBatchItem::STATUS_DONE
                    : ProductEnrichmentBatchItem::STATUS_FAILED),
                $message ?? (is_string($product->enrichment_error) ? $product->enrichment_error : null),
            );
        }
    }

    /**
     * @param  iterable<int, Product>  $products
     * @param  list<ModelGroup>  $groups  plan partii po modelach (etap 2): klucz modelu i lider grupy zamrożone w pozycjach —
     *                                    karta bez klucza (grupa z key '') dostaje model_key null i jest własnym liderem;
     *                                    bez planu obie kolumny zostają puste
     */
    public function seedBatchItems(ProductEnrichmentBatch $batch, iterable $products, array $groups = []): void
    {
        if (! Schema::hasTable('product_enrichment_batch_items')) {
            return;
        }
        $now = now();
        $rows = [];
        $withPrevious = Schema::hasColumn('product_enrichment_batch_items', 'previous_status');
        $withModel = $groups !== [] && self::batchItemsHaveModelColumns();
        /** @var array<int, array{key: ?string, leader_id: int}> $modelOf */
        $modelOf = [];
        foreach ($groups as $group) {
            foreach ($group->memberIds as $memberId) {
                $modelOf[(int) $memberId] = ['key' => $group->key !== '' ? mb_substr($group->key, 0, 160) : null, 'leader_id' => (int) $group->leaderId];
            }
        }
        foreach ($products as $product) {
            $row = [
                'batch_id' => $batch->id,
                'product_id' => $product->id,
                'sku' => mb_substr((string) $product->sku, 0, 100),
                'name' => mb_substr((string) $product->name, 0, 255),
                'status' => ProductEnrichmentBatchItem::STATUS_QUEUED,
                'message' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($withPrevious) {
                // Karta już w kolejce innej partii nie ma stanu do przywrócenia — anulowanie zrobi to, co przed 07.10.2026.
                $previous = (string) $product->enrichment_status;
                $known = in_array($previous, self::RESTORABLE_STATUSES, true);
                $row['previous_status'] = $known ? $previous : null;
                $row['previous_error'] = $known ? $product->enrichment_error : null;
            }
            if ($withModel) {
                $model = $modelOf[(int) $product->id] ?? null;
                $row['model_key'] = $model['key'] ?? null;
                $row['model_leader_id'] = $model['leader_id'] ?? null;
                $row['model_leader_version_id'] = null;
            }
            $rows[] = $row;
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            ProductEnrichmentBatchItem::query()->insertOrIgnore($chunk);
        }
    }

    public function recordBatchProduct(
        ProductEnrichmentBatch $batch,
        Product $product,
        string $status,
        ?string $message = null,
    ): void {
        if (! Schema::hasTable('product_enrichment_batch_items')) {
            return;
        }
        ProductEnrichmentBatchItem::query()->updateOrCreate(
            [
                'batch_id' => $batch->id,
                'product_id' => $product->id,
            ],
            [
                'sku' => mb_substr((string) $product->sku, 0, 100),
                'name' => mb_substr((string) $product->name, 0, 255),
                'status' => $status,
                'message' => $message !== null && $message !== ''
                    ? mb_substr($message, 0, 500)
                    : null,
            ]
        );
    }

    /**
     * @return array{
     *     batch: array<string, mixed>,
     *     items: list<array<string, mixed>>,
     *     counts: array<string, int>
     * }
     */
    public function batchItemLog(
        ProductEnrichmentBatch $batch,
        string $sort = 'status',
        ?string $status = null,
    ): array {
        $query = ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id);
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        $items = $query->get();
        if ($sort === 'updated') {
            $items = $items->sortByDesc(static fn (ProductEnrichmentBatchItem $row): string => (string) $row->updated_at);
        } else {
            $items = $items->sortBy(function (ProductEnrichmentBatchItem $row): array {
                $rank = ProductEnrichmentBatchItem::STATUS_SORT[$row->status] ?? 9;

                return [$rank, mb_strtolower($row->sku)];
            });
        }

        $counts = [];
        foreach (ProductEnrichmentBatchItem::STATUS_SORT as $key => $_) {
            $counts[$key] = 0;
        }
        foreach (ProductEnrichmentBatchItem::query()->where('batch_id', $batch->id)->get(['status']) as $row) {
            $counts[$row->status] = ($counts[$row->status] ?? 0) + 1;
        }

        return [
            'items' => $items->values()->map(static fn (ProductEnrichmentBatchItem $row): array => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'sku' => $row->sku,
                'name' => $row->name,
                'status' => $row->status,
                'message' => $row->message,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ])->all(),
            'counts' => $counts,
        ];
    }

    public function cancelOpenBatchItems(ProductEnrichmentBatch $batch, string $message): void
    {
        if (! Schema::hasTable('product_enrichment_batch_items')) {
            return;
        }
        ProductEnrichmentBatchItem::query()
            ->where('batch_id', $batch->id)
            ->whereIn('status', [
                ProductEnrichmentBatchItem::STATUS_QUEUED,
                ProductEnrichmentBatchItem::STATUS_RUNNING,
            ])
            ->update([
                'status' => ProductEnrichmentBatchItem::STATUS_CANCELLED,
                'message' => mb_substr($message, 0, 500),
            ]);
    }

    /**
     * Batch zostaje na running, gdy job wrócił przy statusie manual/done bez zliczenia.
     * Zamykamy tylko stare partie bez jobów w kolejce — świeżo zlecone jeszcze nie mają wierszy.
     */
    public function finalizeIfJobsGone(ProductEnrichmentBatch $batch): ProductEnrichmentBatch
    {
        if (! in_array($batch->status, [
            ProductEnrichmentBatch::STATUS_QUEUED,
            ProductEnrichmentBatch::STATUS_RUNNING,
        ], true)) {
            return $batch;
        }

        $processed = $batch->done + $batch->failed;
        if ($processed >= $batch->total && $batch->total > 0) {
            $batch->refreshStatus();

            return $batch->refresh();
        }

        // Job enrichmentu trwa do ~7 min (timeout 420 s + slot). 90 s zamykało
        // batch w trakcie pobierania i UI gubił SKU / pasek.
        $staleAfter = now()->subMinutes(12);
        if ($batch->updated_at !== null && $batch->updated_at->gt($staleAfter)) {
            return $batch;
        }

        if ($this->batchHasPendingJobs((int) $batch->id)) {
            return $batch;
        }

        if (Product::query()->whereIn('enrichment_status', [
            Product::ENRICHMENT_RUNNING,
            Product::ENRICHMENT_QUEUED,
        ])->exists()) {
            return $batch;
        }

        $left = max(0, $batch->total - $processed);
        if ($left > 0) {
            $batch->increment('done', $left);
            $batch->refresh();
        }

        $batch->update([
            'message' => "OK {$batch->done} · pominięto już obsłużone (ręcznie/gotowe)",
            'current_sku' => null,
            'current_name' => null,
        ]);
        $batch->refreshStatus();

        return $batch->refresh();
    }

    public function batchHasPendingJobs(int $batchId): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return $this->jobsPayloadQuery($batchId)->exists();
    }

    /**
     * Payload w tabeli jobs to JSON — cudzysłowy w serializacji PHP są jako \".
     * Szukanie dosłownego s:7:"batchId" nic nie znajdowało i UI znikał po 90 s.
     */
    private function jobsPayloadQuery(int $batchId): Builder
    {
        $id = (string) $batchId;

        return DB::table('jobs')->where(function ($q) use ($id): void {
            $q->where('payload', 'like', '%batchId%;i:'.$id.';%')
                ->orWhere('payload', 'like', '%batchId%:'.$id.'%');
        });
    }

    /**
     * Karta z przerwanej partii (anulowanie, „Zatrzymaj wszystko”) wraca do stanu sprzed kolejki zapisanego w pozycji
     * partii: gotowy opis zostaje „gotowy”, karta bez opisu — „bez opisu”, z poprzednim komunikatem. Do 07.10.2026
     * każda taka karta dostawała „błąd: Anulowano przez użytkownika” (Coba: 137 kart, z opisem i bez). Bez zapisanego
     * stanu (partia sprzed zmiany, karta była już w innej kolejce) — jak dotąd: błąd z komunikatem.
     * Zmienia tylko karty w kolejce albo w przebiegu: wynik, który partia zdążyła zapisać, zostaje.
     */
    public function restoreAfterCancel(int $batchId, int $productId, string $message): bool
    {
        if ($this->waitsInAnotherOpenBatch($batchId, $productId)) {
            // karta czeka też w innej, nieanulowanej partii — jej job ją opisze (albo przywróci, gdy i ta zostanie
            // anulowana); stan sprzed kolejki zdjąłby kartę z tamtej kolejki bez śladu w jej pozycji
            return false;
        }
        $item = self::batchItemsHavePreviousStatus()
            ? ProductEnrichmentBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('product_id', $productId)
                ->first(['previous_status', 'previous_error'])
            : null;
        $previous = $item?->previous_status;
        $update = in_array($previous, self::RESTORABLE_STATUSES, true)
            ? ['enrichment_status' => $previous, 'enrichment_error' => $item?->previous_error]
            : ['enrichment_status' => Product::ENRICHMENT_FAILED, 'enrichment_error' => $message];

        return Product::query()
            ->whereKey($productId)
            ->whereIn('enrichment_status', [Product::ENRICHMENT_QUEUED, Product::ENRICHMENT_RUNNING])
            ->update($update) > 0;
    }

    private function waitsInAnotherOpenBatch(int $batchId, int $productId): bool
    {
        if (! Schema::hasTable('product_enrichment_batch_items')) {
            return false;
        }
        $otherBatches = ProductEnrichmentBatchItem::query()
            ->where('product_id', $productId)
            ->where('batch_id', '!=', $batchId)
            ->whereIn('status', [ProductEnrichmentBatchItem::STATUS_QUEUED, ProductEnrichmentBatchItem::STATUS_RUNNING])
            ->pluck('batch_id');
        if ($otherBatches->isEmpty()) {
            return false;
        }

        return ProductEnrichmentBatch::query()
            ->whereIn('id', $otherBatches)
            ->whereIn('status', [ProductEnrichmentBatch::STATUS_QUEUED, ProductEnrichmentBatch::STATUS_RUNNING])
            ->get()
            ->contains(static fn (ProductEnrichmentBatch $batch): bool => ! $batch->isCancelled());
    }

    /**
     * Kolumna z migracji 07.10.2026 — po pierwszym „jest” bez kolejnych zapytań (stopAll i cancelBatch wołają przywracanie
     * w pętli). „Brak” nie jest zapamiętywany: pracownik kolejki uruchomiony przed migracją zobaczy kolumnę po wdrożeniu.
     */
    private static function batchItemsHavePreviousStatus(): bool
    {
        static $has = false;
        if (! $has) {
            $has = Schema::hasColumn('product_enrichment_batch_items', 'previous_status');
        }

        return $has;
    }

    /** Kolumny opisu wspólnego modelu w pozycjach partii (migracja etapu 2) — bez nich plan po modelach nie jest zapisywany. */
    private static function batchItemsHaveModelColumns(): bool
    {
        static $has = false;
        if (! $has) {
            $has = Schema::hasTable('product_enrichment_batch_items')
                && Schema::hasColumn('product_enrichment_batch_items', 'model_leader_version_id');
        }

        return $has;
    }

    /**
     * Natychmiastowe zatrzymanie batcha: flaga + usunięcie oczekujących jobów z kolejki.
     *
     * @return array{batch: ProductEnrichmentBatch, removed_jobs: int, marked_products: int}
     */
    public function cancelBatch(ProductEnrichmentBatch $batch): array
    {
        $batch->markCancelledFlag();

        $removedJobs = 0;
        $markedProducts = 0;

        if (Schema::hasTable('jobs')) {
            $rows = $this->jobsPayloadQuery((int) $batch->id)
                ->orderBy('id')
                ->get(['id', 'payload', 'reserved_at']);

            foreach ($rows as $row) {
                $productId = $this->productIdFromJobPayload((string) $row->payload);
                if ($productId !== null && $row->reserved_at === null) {
                    $markedProducts += (int) $this->restoreAfterCancel((int) $batch->id, $productId, 'Anulowano przez użytkownika');
                }

                DB::table('jobs')->where('id', $row->id)->delete();
                $removedJobs++;
            }
        }
        // Karty partii, które czekały bez joba w tabeli (zlecenie zgubione, prefetch przerwany w locie — pozycja „running”,
        // karta wciąż „queued”) — zostawały w kolejce na zawsze. Karta w przebiegu opisu („running”) ma swój job
        // (abandonCancelled), dlatego tylko karty „queued”: ich opisu nikt jeszcze nie przejął.
        if (Schema::hasTable('product_enrichment_batch_items')) {
            $waiting = ProductEnrichmentBatchItem::query()
                ->where('batch_id', $batch->id)
                ->whereIn('status', [ProductEnrichmentBatchItem::STATUS_QUEUED, ProductEnrichmentBatchItem::STATUS_RUNNING])
                ->whereHas('product', static fn ($q) => $q->where('enrichment_status', Product::ENRICHMENT_QUEUED))
                ->pluck('product_id');
            foreach ($waiting as $productId) {
                $markedProducts += (int) $this->restoreAfterCancel((int) $batch->id, (int) $productId, 'Anulowano przez użytkownika');
            }
        }

        $batch->refresh();
        $processed = $batch->done + $batch->failed;
        $remaining = max(0, $batch->total - $processed);
        if ($remaining > 0) {
            $batch->increment('failed', $remaining);
            $batch->refresh();
        }

        $this->cancelOpenBatchItems($batch, 'Anulowano przez użytkownika');
        $batch->update([
            'status' => ProductEnrichmentBatch::STATUS_CANCELLED,
            'message' => 'Anulowano · OK '.$batch->done.' / usunięto z kolejki '.$removedJobs,
            'current_sku' => null,
            'current_name' => null,
        ]);

        return [
            'batch' => $batch->refresh(),
            'removed_jobs' => $removedJobs,
            'marked_products' => $markedProducts,
        ];
    }

    /**
     * Zatrzymuje WSZYSTKIE pobierania opisów — nie tylko chowa batch z listy.
     *
     * @return array{removed_jobs: int, marked_products: int, cancelled_batches: int}
     */
    public function stopAllEnrichment(): array
    {
        $removedJobs = 0;
        if (Schema::hasTable('jobs')) {
            $ids = DB::table('jobs')
                ->where(function ($q): void {
                    $q->where('payload', 'like', '%EnrichProductJob%')
                        ->orWhere('payload', 'like', '%PrefetchProductSourcesJob%');
                })
                ->pluck('id');
            foreach ($ids as $id) {
                DB::table('jobs')->where('id', $id)->delete();
                $removedJobs++;
            }
        }

        ProductEnrichmentBatch::haltAllWorkers();
        foreach (ProductEnrichmentBatch::query()
            ->where('updated_at', '>', now()->subDay())
            ->pluck('id') as $batchId) {
            Cache::put(ProductEnrichmentBatch::cancelCacheKey((int) $batchId), true, now()->addDay());
        }

        $cancelledBatches = 0;
        $open = ProductEnrichmentBatch::query()
            ->whereIn('status', [
                ProductEnrichmentBatch::STATUS_QUEUED,
                ProductEnrichmentBatch::STATUS_RUNNING,
            ])
            ->get();

        // karty otwartych partii wracają do stanu sprzed kolejki (restoreAfterCancel), reszta „w kolejce / w przebiegu”
        // bez zapisanego stanu — jak dotąd: błąd z komunikatem
        $markedProducts = 0;
        if (Schema::hasTable('product_enrichment_batch_items') && $open->isNotEmpty()) {
            $items = ProductEnrichmentBatchItem::query()
                ->whereIn('batch_id', $open->pluck('id'))
                ->whereIn('status', [
                    ProductEnrichmentBatchItem::STATUS_QUEUED,
                    ProductEnrichmentBatchItem::STATUS_RUNNING,
                ])
                ->orderBy('id')
                ->get(['batch_id', 'product_id']);
            foreach ($items as $item) {
                $markedProducts += (int) $this->restoreAfterCancel(
                    (int) $item->batch_id,
                    (int) $item->product_id,
                    'Zatrzymano wszystkie pobierania opisów'
                );
            }
        }
        $markedProducts += Product::query()
            ->whereIn('enrichment_status', [
                Product::ENRICHMENT_QUEUED,
                Product::ENRICHMENT_RUNNING,
            ])
            ->update([
                'enrichment_status' => Product::ENRICHMENT_FAILED,
                'enrichment_error' => 'Zatrzymano wszystkie pobierania opisów',
            ]);
        foreach ($open as $batch) {
            $processed = $batch->done + $batch->failed;
            $remaining = max(0, $batch->total - $processed);
            if ($remaining > 0) {
                $batch->increment('failed', $remaining);
                $batch->refresh();
            }
            $this->cancelOpenBatchItems($batch, 'Zatrzymano wszystkie pobierania opisów');
            $batch->update([
                'status' => ProductEnrichmentBatch::STATUS_CANCELLED,
                'message' => 'Zatrzymano wszystko · usunięto jobów '.$removedJobs,
                'current_sku' => null,
                'current_name' => null,
            ]);
            $cancelledBatches++;
        }

        return [
            'removed_jobs' => $removedJobs,
            'marked_products' => (int) $markedProducts,
            'cancelled_batches' => $cancelledBatches,
        ];
    }

    /**
     * Produkt zabity w locie (limit czasu, padnięty worker, zerwane MySQL) zostawał
     * w „running” na zawsze — nic go nie odblokowywało, a taki produkt wstrzymywał
     * domykanie wszystkich partii, bo finalizeIfJobsGone czeka na pusty stan.
     *
     * @return int liczba zwolnionych produktów
     */
    public function releaseStaleRunningProducts(): int
    {
        $stale = Product::query()
            ->where('enrichment_status', Product::ENRICHMENT_RUNNING)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_RUNNING_AFTER_MINUTES))
            ->pluck('id');

        $released = 0;
        foreach ($stale as $productId) {
            if ($this->productHasPendingJob((int) $productId)) {
                continue;
            }
            $released += Product::query()
                ->whereKey($productId)
                ->where('enrichment_status', Product::ENRICHMENT_RUNNING)
                ->update([
                    'enrichment_status' => Product::ENRICHMENT_FAILED,
                    'enrichment_error' => 'Przebieg przerwany — proces zniknął bez zapisania wyniku.',
                ]);
        }
        // członkowie modelu bez lidera (etap 2) — osobny licznik w dzienniku, wynik jak dotąd: zwolnione karty
        $this->handOverOrphanedModelMembers();

        return $released;
    }

    /**
     * Zabity lider modelu (etap 2 opisów z cenników): członkowie modelu z pozycją partii „queued” bez własnego zadania,
     * których lider w tej partii już nie żyje — ani pozycja z kartą nie czekają/nie trwają (sama pozycja „running” po
     * zniknięciu zadania lidera nie liczy się: kartę zwolnił releaseStaleRunningProducts), ani nie ma zadania (prefetch,
     * przebieg lidera, opis członków). Pozycja z numerem wersji lidera → zadanie opisu członków od nowa
     * (ApplyModelDescriptionJob); bez numeru, ale z wersją lidera z tej partii (latestOfRun) → to samo z jej numerem;
     * dopiero bez wersji → sztafeta (kolejny członek liderem, prefetch). Pozycja musi stać bez zmiany STALE_RUNNING_AFTER_MINUTES: numer
     * wersji wpisuje EnrichProductJob tuż przed zleceniem zadania, więc świeża pozycja znaczy zadanie w drodze. Raz na
     * (partia, lider) przez 10 min (Cache::add) — kolejne odpytania panelu (activeBatches) nie dublują zlecenia.
     * Partia przerwana: członków przywraca anulowanie, nie sztafeta.
     *
     * @return int ilu członków obsłużono
     */
    public function handOverOrphanedModelMembers(): int
    {
        if (! self::batchItemsHaveModelColumns()) {
            return 0;
        }
        // tylko partie otwarte (wołane przy każdym odpytaniu panelu — po batch_id zapytanie trafia w indeks pozycji)
        $openBatchIds = ProductEnrichmentBatch::query()
            ->whereIn('status', [ProductEnrichmentBatch::STATUS_QUEUED, ProductEnrichmentBatch::STATUS_RUNNING])
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($openBatchIds === []) {
            return 0;
        }
        $rows = ProductEnrichmentBatchItem::query()
            ->whereIntegerInRaw('batch_id', $openBatchIds)
            ->where('status', ProductEnrichmentBatchItem::STATUS_QUEUED)
            ->whereNotNull('model_leader_id')
            ->whereColumn('model_leader_id', '!=', 'product_id')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_RUNNING_AFTER_MINUTES))
            ->orderBy('batch_id')
            ->orderBy('model_leader_id')
            ->orderBy('product_id')
            ->get(['batch_id', 'product_id', 'model_leader_id', 'model_leader_version_id']);
        if ($rows->isEmpty()) {
            return 0;
        }

        $handled = 0;
        /** @var array<int, ProductEnrichmentBatch|null> $batches */
        $batches = [];
        foreach ($rows->groupBy(static fn (ProductEnrichmentBatchItem $row): string => $row->batch_id.':'.$row->model_leader_id) as $group) {
            $batchId = (int) $group->first()->batch_id;
            $leaderId = (int) $group->first()->model_leader_id;
            if (! array_key_exists($batchId, $batches)) {
                $batches[$batchId] = ProductEnrichmentBatch::query()->find($batchId);
            }
            $batch = $batches[$batchId];
            if ($batch === null || $batch->isCancelled()) {
                continue;
            }
            $memberIds = [];
            $versionId = 0;
            foreach ($group as $row) {
                // członek z własnym zadaniem (czekanie na wyszukiwarkę, ponowienie) to nie osierocony członek
                if ($this->productHasPendingJob((int) $row->product_id)) {
                    continue;
                }
                $memberIds[] = (int) $row->product_id;
                $versionId = max($versionId, (int) ($row->model_leader_version_id ?? 0));
            }
            if ($memberIds === []) {
                continue;
            }
            // Lider jeszcze żyje: pozycja czeka albo trwa I karta lidera też (zadanie lidera, które zniknęło, zostawia
            // pozycję „running”, a kartę zwalnia releaseStaleRunningProducts do „failed” — sama pozycja to za mało), albo
            // zadanie prefetchu/przebiegu w kolejce, albo opis członków już zlecony.
            $openItem = [ProductEnrichmentBatchItem::STATUS_QUEUED, ProductEnrichmentBatchItem::STATUS_RUNNING];
            $openCard = [Product::ENRICHMENT_QUEUED, Product::ENRICHMENT_RUNNING];
            $leaderStatus = (string) ProductEnrichmentBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('product_id', $leaderId)
                ->value('status');
            $leaderCard = (string) Product::query()->whereKey($leaderId)->value('enrichment_status');
            if ((in_array($leaderStatus, $openItem, true) && in_array($leaderCard, $openCard, true))
                || $this->productHasPendingJob($leaderId)
                || $this->modelJobPending($batchId, $leaderId)) {
                continue;
            }
            if (! Cache::add('enrichment:model-handover:'.$batchId.':'.$leaderId, 1, now()->addMinutes(10))) {
                continue;
            }
            if ($versionId <= 0) {
                // lider zdążył zapisać wersję w tej partii (zapis albo propozycja), ale worker padł przed wpisaniem jej
                // członkom — ta wersja jest podstawą, sztafeta dopiero bez niej
                $run = ProductDescriptionVersion::latestOfRun($leaderId, $batchId);
                if ($run !== null) {
                    $versionId = (int) $run->id;
                    ProductEnrichmentBatchItem::query()
                        ->where('batch_id', $batchId)
                        ->whereIntegerInRaw('product_id', $memberIds)
                        ->update(['model_leader_version_id' => $versionId]);
                }
            }
            if ($versionId > 0) {
                ApplyModelDescriptionJob::dispatch($batchId, $leaderId, $versionId);
            } else {
                $next = $this->planner()->nextLeader($batchId, $leaderId);
                if ($next === null) {
                    continue;
                }
                PrefetchProductSourcesJob::dispatch($next, $batchId, (bool) $batch->force);
            }
            $handled += count($memberIds);
            Log::info('Model members handed over after lost leader', [
                'batch_id' => $batchId,
                'leader_id' => $leaderId,
                'leader_version_id' => $versionId > 0 ? $versionId : null,
                'members' => $memberIds,
            ]);
        }

        return $handled;
    }

    private function productHasPendingJob(int $productId): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        // payload to JSON z serializacją PHP w środku — cudzysłowy bywają jako \"
        return DB::table('jobs')
            ->where('payload', 'like', '%productId%;i:'.$productId.';%')
            ->exists();
    }

    /** Zadanie opisu członków modelu (ApplyModelDescriptionJob) tej partii i tego lidera czeka w kolejce. */
    private function modelJobPending(int $batchId, int $leaderId): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return $this->jobsPayloadQuery($batchId)
            ->where('payload', 'like', '%leaderId%;i:'.$leaderId.';%')
            ->exists();
    }

    public function enrichmentProductCounts(): array
    {
        return [
            'queued_products' => Product::query()
                ->where('enrichment_status', Product::ENRICHMENT_QUEUED)
                ->count(),
            'running_products' => Product::query()
                ->where('enrichment_status', Product::ENRICHMENT_RUNNING)
                ->count(),
        ];
    }

    public function assertBatchNotCancelled(?int $batchId): void
    {
        if ($batchId === null || $batchId <= 0) {
            return;
        }

        $batch = ProductEnrichmentBatch::query()->find($batchId);
        if ($batch !== null && $batch->isCancelled()) {
            throw new EnrichmentCancelledException('Enrichment anulowany przez użytkownika.');
        }
    }

    private function productIdFromJobPayload(string $payload): ?int
    {
        $plain = str_replace('\\', '', $payload);
        if (preg_match('/productId";i:(\d+);/', $plain, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Wstępna analiza AI: z surowej treści strony zostawia tylko fakty o produkcie.
     *
     * @param  list<array{url: string, text: string}>  $pageSnippets
     * @return list<array{url: string, text: string}>
     */
    private function sanitizePagesWithLlm(Product $product, array $pageSnippets): array
    {
        if ($pageSnippets === []) {
            return [];
        }
        $pageSnippets = $this->dropBinaryPageSnippets($pageSnippets);
        if ($pageSnippets === []) {
            return [];
        }

        $pageCount = count($pageSnippets);
        $compact = $pageCount > 4
            ? $this->fitPagesToBudget($pageSnippets, 6, 8000, 20000)
            : $this->fitPagesToBudget($pageSnippets, 4, 8000, 20000);
        if ($compact === []) {
            return $pageSnippets;
        }

        $pagesJson = json_encode(self::withoutCodeOnlyMeta($compact), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $parsed = $this->llm->chatJsonEnrichment([
                [
                    'role' => 'system',
                    'content' => <<<'SYS'
Jesteś filtrem treści produktu BHP. Dostajesz surowy tekst ze stron sklepów.
Zadanie: WSTĘPNA ANALIZA — wyrzuć śmieci sklepowe, zostaw wyłącznie informacje o produkcie.

WYRZUĆ całkowicie: logowanie, rejestracja, konto, obserwowane, koszyk, suma, zamówienie, menu kategorii, breadcrumby („jesteś tutaj”), wyszukiwanie, telefon/e-mail sklepu, wysyłka, koszty dostawy, płatności, prowizje, regulamin, polityka prywatności, odstąpienie od umowy, zwroty 14/30 dni, punkty lojalnościowe, porównanie, cookies, baner CMP / OneTrust / CCPA / „When you visit our website, we store cookies”, cenniki wariantów (EU 35 - 309 zł), etykietę „Wariant”, tabele doboru rozmiaru, gwarancję sklepu, „Natychmiast do wysyłki”, ceny marketingowe bez kontekstu produktu.

ZOSTAW fakty o produkcie: przeznaczenie, materiały, normy, parametry, zastosowania.
Zapisz je jako zwykły tekst z akapitami. Bez HTML, CSS, class/id i bez sklejania całej karty w jedną ścianę.
Nie streszczaj do sloganu ani og:description. Nie urywaj na „(Zobacz…”, „czytaj dalej”, „rozwiń”.
Wyrzuć same odnośniki typu „Zobacz klasyfikację…”, ale zostaw treść, która jest po nich.
Nie powtarzaj tego samego faktu.

JĘZYK: źródła bywają po francusku, niemiecku, czesku, hiszpańsku, chińsku czy angielsku. ZAWSZE tłumacz fakty na polski.
Nigdy nie przepisuj zdań w języku oryginału — nazwy własne modeli i oznaczenia norm zostaw bez zmian.

Zwróć TYLKO JSON — bez pola thought/reasoning. Pierwszy znak to {.
{"pages":[{"url":"…","text":"fakty o produkcie po polsku, akapity — bez HTML"}]}
Karta jest TYM produktem tylko gdy w tytule, URL albo opisie jest producent ORAZ (SKU albo nazwa produktu). Inaczej "text":"".
Nie zmyślaj cech. Cennik rozmiarów możesz pominąć, ale gdy to ten produkt zostaw nazwę, SKU, materiały i normy — nie zwracaj pustego text.
Nie pisz w tekście, czy źródło pasuje do produktu ani jak sprawdzałeś warunki.
Jeśli na stronie nie ma faktów o produkcie i nie ma SKU/nazwy → "text":"".
Jeśli nazwa to PPE (obuwie, rękawice, odzież…), a tekst dotyczy odczynnika / numeru CAS / wzoru chemicznego — "text":"".
SYS,
                ],
                [
                    'role' => 'user',
                    'content' => "SKU: {$product->sku}\nProducent: {$product->manufacturer}\nNazwa: {$product->name}"
                        .$this->manufacturerModelHint($product)."\n\nStrony:\n{$pagesJson}",
                ],
            ], 0.0, 4000);
        } catch (Throwable $e) {
            Log::info('AI page sanitize failed, using heuristic text', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return $this->stripShopUiFromPages($pageSnippets);
        }

        $byUrl = [];
        $judged = [];
        foreach ($parsed['pages'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            $text = trim((string) ($row['text'] ?? ''));
            if ($url !== '') {
                $judged[mb_strtolower($url)] = true;
            }
            if ($url === '' || $text === '' || $this->looksLikeShopChromeDescription($text)
                || $this->looksLikeOffTopicDescription($text)) {
                continue;
            }
            $byUrl[mb_strtolower($url)] = ['url' => $url, 'text' => mb_substr($text, 0, 8000)];
        }

        $cleaned = [];
        foreach ($compact as $orig) {
            $key = mb_strtolower((string) ($orig['url'] ?? ''));
            if ($key !== '' && isset($byUrl[$key])) {
                $cleaned[] = $this->copyPageMeta($orig, $byUrl[$key]);
            }
        }
        if ($cleaned === [] && $byUrl !== []) {
            $cleaned = array_values($byUrl);
        }
        if ($cleaned !== []) {
            return $cleaned;
        }

        $fallback = $this->stripShopUiFromPages($pageSnippets);
        if (! $this->filterJudgedEveryPage($compact, $judged)) {
            return $fallback;
        }
        // Filtr ocenił każdą stronę i nie znalazł faktów o wyrobie. Surowy tekst wraca tylko ze strony, która sama
        // nazywa wyrób — inaczej komunikat „Ten serwis nie jest dostępny dla Twojej przeglądarki” szedł do modelu
        // jako źródło, a model pisał opis z samej nazwy (karta 57476, 23.09.2026).
        $kept = $this->stripShopUiFromPages(array_values(array_filter(
            $pageSnippets,
            fn (array $page): bool => $this->identity->pageHasSkuOrNameAndManufacturer(
                (string) ($page['url'] ?? ''),
                (string) ($page['title'] ?? ''),
                (string) ($page['text'] ?? ''),
                $product
            )
        )));
        if (count($kept) < count($fallback)) {
            $this->attemptLog()->add(
                'drop',
                'filtr stron: brak faktów o wyrobie, a strona go nie nazywa',
                urls: array_values(array_diff(array_column($fallback, 'url'), array_column($kept, 'url')))
            );
        }

        return $kept;
    }

    /**
     * Czy odpowiedź filtra niesie werdykt dla każdej wysłanej strony. Odpowiedź bez listy stron albo z innymi
     * adresami to nie ocena, tylko nieczytelna odpowiedź — wtedy zostaje surowy tekst (jak przy awarii filtra).
     *
     * @param  list<array{url?: string}>  $sent
     * @param  array<string, true>  $judged
     */
    private function filterJudgedEveryPage(array $sent, array $judged): bool
    {
        if ($sent === [] || $judged === []) {
            return false;
        }
        foreach ($sent as $page) {
            $key = mb_strtolower((string) ($page['url'] ?? ''));
            if ($key === '' || ! isset($judged[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{url: string, text: string}>  $pages
     * @return list<array{url: string, text: string}>
     */
    private function stripShopUiFromPages(array $pages): array
    {
        $out = [];
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            $text = ProductDescriptionText::stripShopUi(trim((string) ($page['text'] ?? '')));
            if ($url === '' || $text === '' || ProductPageFetcher::looksLikeShopOfferDump($text)
                || $this->looksLikeShopChromeDescription($text)) {
                continue;
            }
            $out[] = $this->copyPageMeta($page, ['url' => $url, 'text' => $text]);
        }

        return $out;
    }

    /**
     * @param  list<array{url: string, text: string}>  $pages
     * @return list<array{url: string, text: string}>
     */
    private function dropBinaryPageSnippets(array $pages): array
    {
        $out = [];
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            $text = (string) ($page['text'] ?? '');
            if (ProductImageDownloader::looksLikeImageUrl($url) || ProductPageFetcher::looksLikeBinaryMedia($text)) {
                continue;
            }
            $out[] = $page;
        }

        return $out;
    }

    /**
     * Strony do polecenia modelu bez pól tylko dla kodu (adres po przekierowaniach i kody z mikrodanych — werdykt
     * tożsamości, etap 1). copyPageMeta niesie je przez filtr stron, a polecenie zostaje takie jak przed nimi.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private static function withoutCodeOnlyMeta(array $pages): array
    {
        return array_map(static function (array $page): array {
            unset($page['final_url'], $page['markup_codes']);

            return $page;
        }, $pages);
    }

    /**
     * llama.cpp dzieli kontekst na równoległe sloty, więc jeden prompt ma do dyspozycji
     * ułamek okna modelu. Bez twardego budżetu kilka dłuższych kart daje HTTP 400.
     *
     * @param  list<array{url: string, text: string}>  $pages
     * @return list<array{url: string, text: string}>
     */
    private function fitPagesToBudget(array $pages, int $maxPages, int $perPage, int $total): array
    {
        $out = [];
        $left = $total;
        foreach (array_slice($pages, 0, $maxPages) as $page) {
            if ($left <= 0) {
                break;
            }
            $text = trim((string) ($page['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $limit = min($perPage, $left);
            $block = mb_strlen($text) > $limit ? $this->normsBlockBeyondBudget($text, $limit) : '';
            $text = $block !== ''
                ? $block."\n\n".mb_substr($text, 0, max(0, $limit - mb_strlen($block) - 2))
                : mb_substr($text, 0, $limit);
            $left -= mb_strlen($text);
            $out[] = $this->copyPageMeta($page, [
                'url' => (string) ($page['url'] ?? ''),
                'text' => $text,
            ]);
        }

        return $out;
    }

    /**
     * Normy i oznaczenia ochrony, które na długiej stronie stoją za granicą przycięcia — dosłownie, na początek tekstu dla
     * modelu. Strona Ansell HyFlex 11-202 (53 tys. znaków) ma sekcję „Normy i certyfikaty” od ok. 23 tys. znaku, jako podpisy
     * obrazków („![Image 10: EN 388:2016 +A1:2018](…) 1X42C”); model widział 8000 znaków i zapisał tylko EN 407 i ANSI ze wstępu.
     * Bez adresów obrazków i bez własnych słów — tylko linie z kodem normy, poziomem ANSI albo kategorią ŚOI.
     */
    private function normsBlockBeyondBudget(string $text, int $cut): string
    {
        $head = mb_strtolower(mb_substr($text, 0, $cut));
        $lines = [];
        $length = 0;
        foreach (preg_split('/\R+/u', mb_substr($text, $cut)) ?: [] as $raw) {
            // „![Image 10: EN 388:2016 +A1:2018](https://…) 1X42C” → „EN 388:2016 +A1:2018 1X42C”
            $line = (string) preg_replace('/!\[(?:Image\s*\d+\s*:\s*)?([^\]]*)\]\([^)]*\)/u', '$1', (string) $raw);
            $line = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $line);
            $line = trim((string) preg_replace('/\s+/u', ' ', $line), " \t|#*-");
            if ($line === '' || mb_strlen($line) > 160) {
                continue;
            }
            $isNorm = preg_match('/\b(?:PN-)?EN(?:\s*ISO)?\s*\d{3,5}\b|\bISO\s*\d{4,5}\b|\bANSI\s*\/?\s*ISEA\b|\b(?:kategori[ai]|category|kat\.)\s*(?:I{1,3}|[123])\b/iu', $line) === 1;
            if (! $isNorm || str_contains($head, mb_strtolower($line)) || in_array($line, $lines, true)) {
                continue;
            }
            if ($length + mb_strlen($line) > 800 || count($lines) >= 12) {
                break;
            }
            $lines[] = $line;
            $length += mb_strlen($line) + 1;
        }

        return $lines === [] ? '' : "Normy i oznaczenia z dalszej części strony:\n".implode("\n", $lines);
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $searchResults
     * @param  list<array{url: string, text: string}>  $pageSnippets
     * @param  string|null  $sourcesNote  opis źródeł zamiast „Wyniki wyszukiwania / Strony (po filtrze AI)” — dla
     *                                    opisu ze źródeł B2B, gdzie ani wyszukiwania, ani filtra nie było
     * @param  array<string, mixed>|null  $modelContext  nota modelu lidera grupy (ModelGroupPlanner::contextFor, etap 2) —
     *                                                   tylko z nią polecenie dostaje listę wariantów; bez niej treść jak dotąd
     * @return array<string, mixed>
     */
    private function extractWithLlm(Product $product, array $searchResults, array $pageSnippets, ?string $sourcesNote = null, ?array $modelContext = null): array
    {
        // Nie ma tekstu źródła — nie ma opisu. Model z samą nazwą z cennika dopisuje wyrobowi cechy z pamięci.
        $withText = array_filter($pageSnippets, static fn ($page): bool => is_array($page) && trim((string) ($page['text'] ?? '')) !== '');
        if ($withText === []) {
            $this->attemptLog()->add('desc', 'brak tekstu źródła — opis nie powstaje');

            return [];
        }
        $compactPages = $this->fitPagesToBudget($pageSnippets, 5, 8000, 20000);
        $compactSources = array_map(static function (array $r): array {
            return [
                'url' => mb_substr((string) ($r['url'] ?? ''), 0, 300),
                'title' => mb_substr((string) ($r['title'] ?? ''), 0, 150),
                'snippet' => mb_substr((string) ($r['snippet'] ?? ''), 0, 200),
            ];
        }, array_slice($searchResults, 0, 8));

        $sourcesJson = json_encode($compactSources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pagesJson = json_encode(self::withoutCodeOnlyMeta($compactPages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->llm->chatJsonEnrichment([
            [
                'role' => 'system',
                'content' => $this->descriptionTemplates()->systemPrompt($product),
            ],
            [
                'role' => 'user',
                'content' => "SKU: {$product->sku}\nProducent: {$product->manufacturer}\nNazwa: {$product->name}"
                    .$this->manufacturerModelHint($product)."\nEAN: ".($product->ean ?? '—')
                    .$this->manufacturerNormsNote($this->manufacturerNormFactsFromPages($pageSnippets, $product))
                    .($modelContext !== null ? $this->modelContextNote($modelContext) : '')
                    .($sourcesNote !== null
                        ? "\n\n{$sourcesNote}\n\nTeksty źródeł:\n{$pagesJson}"
                        : "\n\nWyniki wyszukiwania:\n{$sourcesJson}\n\nStrony (po filtrze AI):\n{$pagesJson}"),
            ],
        ], 0.1, 4500);
    }

    /**
     * Pary z ramki norm na karcie wyrobu w witrynie producenta (ProductPageFetcher::normFacts) jako zawartość
     * kolumny products.manufacturer_norms; null, gdy żadna strona producenta w puli ich nie podaje. Ramka norm
     * u sklepu się nie liczy — sklep bywa źródłem starego zapisu (cas-technik.eu: Butoflex 650 „EN 388 (1.1.2.2)”
     * wobec „1121X” u MAPA).
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>|null
     */
    private function manufacturerNormFactsFromPages(array $pages, Product $product): ?array
    {
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            $facts = $page['norm_facts'] ?? null;
            if ($url === '' || ! is_array($facts) || $facts === []
                || ! $this->manufacturers->isManufacturerUrl($url, $product)) {
                continue;
            }
            $pairs = [];
            foreach ($facts as $fact) {
                if (is_array($fact) && is_string($fact['label'] ?? null)) {
                    $pairs[] = ['label' => $fact['label'], 'value' => is_string($fact['value'] ?? null) ? $fact['value'] : null];
                }
            }
            $column = ManufacturerNormFacts::build(
                $pairs,
                ManufacturerNormFacts::WEB_PAGE_CONNECTOR,
                (string) $product->manufacturer,
                $url,
            );
            if ($column !== null && ManufacturerNormFacts::norms($column) !== []) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Pary ze strony producenta zapisane w products.manufacturer_norms — o ile nie ma tam par łącznika B2B producenta
     * (te są pewniejsze: dane strukturalne, a nie ramka odczytana z HTML). Od tej kolumny liczy się dopasowanie
     * do wymagania przetargu i normy pokazywane na karcie (BhpAttributeNormalizer::forDisplay).
     *
     * Pary bierzemy tylko z karty, która została źródłem opisu i jest kartą producenta — nie z pierwszej strony
     * producenta z ramką norm w puli. W puli bywa strona rodziny albo katalogu, a ramka norm takiej strony opisuje
     * inny wariant (recenzja planu norm, 23.09.2026). Dokładnego kodu wyrobu na stronie tu nie wymagamy: karta
     * przeszła bramkę tożsamości opisu, a producenci (MAPA) kodu z cennika na karcie nie piszą.
     *
     * @param  list<array<string, mixed>>  $pages
     */
    private function storeManufacturerPageNorms(Product $product, array $pages, ?string $primarySourceUrl, ?string $primarySourceKind): bool
    {
        if ($primarySourceKind !== 'manufacturer' || $primarySourceUrl === null || $primarySourceUrl === '') {
            return false;
        }
        $sourcePages = array_values(array_filter(
            $pages,
            fn (array $page): bool => in_array($primarySourceUrl, [
                (string) ($page['url'] ?? ''),
                $this->identity->preferredLocaleUrl((string) ($page['url'] ?? ''), $product),
            ], true)
        ));
        $column = $this->manufacturerNormFactsFromPages($sourcePages, $product);
        if ($column === null
            || ! ManufacturerNormFacts::replaceableFromWebPage($product->manufacturer_norms)
            || ManufacturerNormFacts::sameFacts($product->manufacturer_norms, $column)) {
            return false;
        }

        $product->manufacturer_norms = $column;
        $product->save();
        $this->attemptLog()->add('desc', 'normy z karty producenta: '.implode(', ', ManufacturerNormFacts::norms($column)));

        return true;
    }

    /**
     * Kod EN 388 w opisie inny niż u producenta → kod producenta. Model dostaje normy producenta z pierwszeństwem
     * (manufacturerNormsNote), ale przy dwóch zapisach bywał zapis ze sklepu. Podmieniamy sam dosłowny kod, nie
     * zdanie; zapis słowny („ścieranie 4, przecięcie 1…”) zostaje, tylko trafia do śladu przebiegu.
     *
     * Tylko w obrębie wydania: „EN 388:2003 4542” obok producenta „EN 388:2016 … 4X42C” to inna, prawdziwa wartość —
     * podmiana dałaby „EN 388:2003 4X42C”, czyli fakt zmyślony. Rok podany tylko po jednej stronie rozstrzyga
     * producent (ManufacturerNormFacts::resolveAgainstRows).
     */
    private function alignEn388WithManufacturer(string $description, Product $product): string
    {
        $own = ManufacturerNormFacts::context($product->manufacturer_norms)['en388'] ?? null;
        if ($own === null || $description === '') {
            return $description;
        }
        // Wydanie kodu producenta z etykiety jego pary („EN 388:2016 + A1:2018” → 2016); samo en388 roku nie niesie.
        $ownCode = null;
        foreach (ManufacturerNormFacts::rows($product->manufacturer_norms) as $row) {
            $candidate = En388Code::first($row['label'].' '.$row['value']);
            if ($candidate !== null && ! $candidate->worded && $candidate->compact() === $own) {
                $ownCode = $candidate;
                break;
            }
        }
        $ownCode ??= En388Code::first('EN 388 '.$own);
        if ($ownCode === null) {
            return $description;
        }
        foreach (En388Code::allIn($description) as $code) {
            if ($code->canonical() === $ownCode->canonical()
                || ! En388Code::comparableEditions($code->edition, $ownCode->edition)) {
                continue;
            }
            if ($code->worded || $code->text === '') {
                $this->attemptLog()->add('desc', 'opis podaje poziomy EN 388 inne niż producent ('.$own.') — zapis słowny zostaje');

                continue;
            }
            $description = (string) preg_replace('/'.preg_quote($code->text, '/').'/u', $own, $description, 1);
            $this->attemptLog()->add('desc', 'EN 388 w opisie: '.$code->text.' → '.$own.' (karta producenta)');
        }

        return $description;
    }

    /**
     * Normy producenta dla modelu, z pierwszeństwem przed sklepami. Ramka norm stała w tekście strony, ale model
     * przy dwóch zapisach tej samej normy brał czytelniejszy ze sklepu — nawet gdy był to stary kod.
     *
     * @param  array<string, mixed>|null  $column
     */
    private function manufacturerNormsNote(?array $column): string
    {
        $rows = ManufacturerNormFacts::rows($column);
        if ($rows === []) {
            return '';
        }
        $lines = array_map(
            static fn (array $row): string => '- '.$row['label'].($row['value'] !== '' ? ': '.$row['value'] : ''),
            $rows
        );

        return "\n\nNormy z karty producenta (".ManufacturerNormFacts::sourceUrl($column)."), dosłownie:\n"
            .implode("\n", $lines)
            ."\nTe oznaczenia mają pierwszeństwo: gdy inne źródło podaje dla tej samej normy inny kod, poziom albo"
            .' litery (także stary zapis, np. „EN 374” zamiast „EN 374-1”), pomiń wersję z innego źródła i podaj tę'
            .' od producenta — w opisie i w listach.';
    }

    /**
     * „Oznaczenie modelu u producenta: R259, R-259” — model łączy nasze 259-13 („Ringers 259”)
     * z kartą RINGERS™ R259. Bez tego zwracał pusty opis, bo kodu z cennika nie było na karcie.
     */
    private function manufacturerModelHint(Product $product): string
    {
        $codes = [];
        foreach ($this->identity->modelAliases($product) as $alias) {
            $alias = trim((string) $alias);
            if (preg_match('/^(?:[a-z]{1,4}-?\d{2,6}|\d{2}-\d{3}[a-z]?)$/iu', $alias) === 1) {
                $codes[] = mb_strtoupper($alias);
            }
        }
        $codes = array_values(array_unique($codes));

        return $codes === [] ? '' : "\nOznaczenie modelu u producenta (ten sam produkt): ".implode(', ', $codes);
    }

    /**
     * Listy i atrybuty karty z odpowiedzi modelu — wspólne dla opisu z internetu i opisu ze źródeł B2B.
     *
     * @param  array<string, mixed>  $extracted  po enrichStructuredFieldsFromPages
     * @param  list<array<string, mixed>>  $pageSnippets
     * @return array{lists: array{features: list<string>, norms: list<string>, certificates: list<string>, materials: list<string>, use_cases: list<string>, specs: list<string>, attributes: array<string, mixed>}, packaging: string|null}
     */
    /**
     * Zwykłe wzbogacanie: pozycje list o braku danych („Typ zapięcia: brak danych w źródle”) nie są informacją
     * o wyrobie. Szablon rodziny w bazie bywa starszy i każe je wypisywać (przegląd 22.09.2026: `obuwie`),
     * a zasada brzmi: brak informacji = pomiń. Opis ze źródeł B2B (describeFromB2bSources) wycina je sam
     * przez SourceClaimGuard i zapisuje w dropped_claims — tam tego filtra nie ma, żeby ślad nie zginął.
     *
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    private function withoutMissingDataListItems(array $extracted): array
    {
        foreach (['features', 'norms', 'certificates', 'materials', 'use_cases', 'specs'] as $listKey) {
            if (is_array($extracted[$listKey] ?? null)) {
                $extracted[$listKey] = array_values(array_filter(
                    $this->stringList($extracted[$listKey]),
                    static fn (string $item): bool => ! SourceClaimGuard::statesMissingData($item)
                ));
            }
        }

        return $extracted;
    }

    private function payloadFromExtraction(Product $product, array $extracted, string $description, array $pageSnippets): array
    {
        $features = ProductDescriptionText::dropDuplicatedListItems(
            $this->stringList($extracted['features'] ?? null),
            $description
        );
        // „EN 388”, „EN 388:2016” i „EN388:2016+A1:2018” z trzech kart to jedna norma,
        // a nie trzy pozycje na liście — zwijamy do zapisu najbogatszego w informacje.
        // Normy producenta wyrobu przed zapisem ze sklepu tej samej normy (Butoflex 650: „EN 388 (1.1.2.2)” z cas-technik.eu
        // wobec „1121X” u MAPA) — lista idzie do products.norms, a stamtąd do nagłówka karty.
        $norms = ManufacturerNormFacts::preferOver(
            NormCode::dedupe($this->stringList($extracted['norms'] ?? null)),
            $product->manufacturer_norms
        );
        // Do normalizatora idzie lista ze źródła („Kategoria III” to dowód klasy ŚOI), na kartę — bez oznakowania
        // CE, kategorii i samego rozporządzenia: to nie certyfikaty (CertificateLabels::filter).
        $certificateEvidence = NormCode::dedupe($this->stringList($extracted['certificates'] ?? null));
        $certificates = CertificateLabels::filter($certificateEvidence);
        $materials = ProductDescriptionText::dropDuplicatedListItems(
            $this->stringList($extracted['materials'] ?? null),
            $description
        );
        $useCases = ProductDescriptionText::dropDuplicatedListItems(
            $this->stringList($extracted['use_cases'] ?? null),
            $description
        );
        $specs = ProductDescriptionText::dropDuplicatedListItems(
            $this->stringList($extracted['specs'] ?? null),
            $description
        );

        $attributes = $this->bhpAttributes->normalize(
            is_array($extracted['attributes'] ?? null) ? $extracted['attributes'] : null,
            [
                'materials' => $materials,
                'norms' => $norms,
                'specs' => $specs,
                'certificates' => $certificateEvidence,
                // kategoria-dowód, jak w BhpAttributeNormalizer::forProduct
                'category' => $product->categoryAsEvidence(),
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'description' => $description,
                // Kolumnę norm zapisuje wzbogacanie (ProductEnrichmentResetter kasuje ją razem z opisem), więc tu jest
                // wynikiem POPRZEDNIEGO przebiegu, a normalizer bierze norms_column za tożsamość bez przesiewu. Karta
                // AJ GROUP 906 (24.09.2026): „EN 471 klasa 3, EN 533 indeks 1” z pobrania z 10.09 wracały do normy_en
                // przy każdym „Pobierz ponownie”, choć obie strony źródłowe tych norm nie znają. Nowa lista wchodzi
                // przez 'norms' (przesiewana); kolumna dostaje ją po zapisie.
                'norms_column' => '',
                // te same źródła co BhpAttributeNormalizer::forProduct (cennik, tabelka dostawcy)
                'shop_fields' => (string) ($product->shop_fields_summary ?? ''),
                'price_list' => is_array($product->price_list_attributes) ? $product->price_list_attributes : [],
                // jak w BhpAttributeNormalizer::forProduct — poziomy EN 388 producenta biją opis
                'manufacturer' => ManufacturerNormFacts::context($product->manufacturer_norms),
            ]
        );
        $sized = $this->applyExtractedSizes(
            $product,
            $attributes,
            $specs,
            $description,
            $this->collectOptionSizes($pageSnippets, $this->sizeCategoryHint($product, $attributes))
        );

        return [
            'lists' => [
                'features' => $features,
                'norms' => $norms,
                'certificates' => $certificates,
                'materials' => $materials,
                'use_cases' => $useCases,
                'specs' => $specs,
                'attributes' => $sized['attributes'],
            ],
            'packaging' => $sized['packaging'],
        ];
    }

    /**
     * Opis karty wyłącznie z dwóch źródeł zapisanych przez import B2B: opisu ze sklepu dostawcy i tekstu karty
     * katalogowej PDF (decyzja użytkownika 21.09.2026, łącznik B2bDescribesFromDatasheet). Bez wyszukiwarki, bez cache
     * SKU, bez stron z internetu i bez pobierania plików — tożsamość wyrobu gwarantuje powiązanie B2B, więc nie ma
     * potwierdzania karty ani filtra stron. Niczego nie zapisuje: wynik zapisuje DescribeB2bProductFromDatasheetJob.
     *
     * Tekst PDF bywa poszatkowany (kolumny pomieszane), więc model mógłby skleić poziom normy z sąsiednich kolumn.
     * Każdy kod poziomów (EN 388 „4131A”, EN 407 „X1XXXX”) z opisu i list musi występować w tekście źródeł: z list
     * znika pozycja bez pokrycia, a opis z takim kodem jest odrzucany w całości.
     *
     * PDF-y bywają ubogie (ARTRA 22.09.2026: materiały, podnosek, podeszwa, norma), a model dopisywał ogólną wiedzę —
     * „wodoodporną cholewkę” przy S1 P, „pracę w wysokich temperaturach”, objaśnienie klasy („200 J”, „15 kN”). Stąd
     * zasady EnrichmentDescriptionTemplates::sourcesOnlyRules() — od 22.09.2026 w poleceniu systemowym każdego
     * wzbogacania, tu powtórzone w wiadomości użytkownika z pierwszeństwem przed szablonem rodziny (szablon zapisany
     * w bazie bywa starszy i może kazać wypisywać „brak danych w źródle”) — i SourceClaimGuard: zdanie opisu
     * z twierdzeniem o właściwości bez pokrycia
     * w źródłach wypada (dropped_claims), tak samo pozycja list; opis za krótki po usunięciu jest odrzucany.
     *
     * @return array{description: string, payload: array<string, mixed>, norms: string|null, packaging: string|null, dropped: list<string>, dropped_claims: list<string>}
     *
     * @throws B2bSourcesDescriptionRejected
     */
    public function describeFromB2bSources(Product $product, string $shopText, string $shopUrl, string $sheetText, string $sheetUrl, string $shopFields = ''): array
    {
        $shopText = trim($shopText);
        $sheetText = trim($sheetText);
        $shopFields = trim($shopFields);
        if ($sheetText === '') {
            throw new B2bSourcesDescriptionRejected('brak tekstu karty katalogowej');
        }
        // tabelka ze strony sklepu (normy, pakowanie, rozmiary) należy do źródła „sklep” — dosłownie, jak na stronie
        $shopSource = trim($shopText.($shopFields !== '' ? "\n\nParametry ze strony sklepu:\n".mb_substr($shopFields, 0, 1500) : ''));
        $pages = [
            ['url' => $sheetUrl, 'text' => mb_substr($sheetText, 0, 8000)],
            ...($shopSource !== '' ? [['url' => $shopUrl, 'text' => mb_substr($shopSource, 0, 3000)]] : []),
        ];

        $extracted = $this->extractWithLlm($product, [], $pages, 'Źródła — wyłącznie te dwa teksty, nic spoza nich:'
            ."\n1. Karta katalogowa PDF ze sklepu dostawcy ({$sheetUrl}) — tekst wyciągnięty z PDF, kolumny i wiersze mogą"
            .' być pomieszane; wartości (np. poziomy normy) przypisuj tylko wtedy, gdy przypisanie jest w tekście jednoznaczne.'
            ."\n2. Opis i parametry ze strony sklepu dostawcy (".($shopUrl !== '' ? $shopUrl : 'strona produktu').').'
            ."\n\n".EnrichmentDescriptionTemplates::sourcesOnlyRules(
                'ZASADY TEGO OPISU — mają pierwszeństwo przed instrukcją rodziny i przed zasadami pisania z polecenia systemowego:'
            ));
        $extracted = $this->enrichStructuredFieldsFromPages($extracted, $pages);

        $description = ProductDescriptionText::plain($this->modelDescription($extracted));
        if ($description === '') {
            throw new B2bSourcesDescriptionRejected($this->composeFullDescription($extracted) !== ''
                ? 'model nie potwierdził źródeł (confidence 0)'
                : 'model nie zwrócił opisu');
        }
        if ($this->looksLikeMissingCardMeta($description) || $this->looksLikeRawLocaleDump($description)
            || $this->looksLikeForeignOrPartsTableDump($description)) {
            throw new B2bSourcesDescriptionRejected('opis nie jest opisem wyrobu');
        }
        if (mb_strlen($description) <= mb_strlen($shopText)) {
            throw new B2bSourcesDescriptionRejected('opis nie dłuższy niż opis ze sklepu');
        }

        $sources = self::claimKey($shopSource."\n".$sheetText);
        $unsupported = array_values(array_filter(
            self::sourceClaims($description),
            static fn (string $code): bool => ! str_contains($sources, $code),
        ));
        if ($unsupported !== []) {
            throw new B2bSourcesDescriptionRejected('oznaczenia albo poziomy norm spoza źródeł w opisie: '.implode(', ', $unsupported));
        }

        // twierdzenia o właściwościach bez pokrycia w źródłach (wodoodporność przy S1 P, „200 J” z objaśnienia klasy)
        $guard = new SourceClaimGuard($shopSource."\n".$sheetText);
        $filtered = $guard->filterDescription($description);
        $droppedClaims = $filtered['dropped'];
        $description = $filtered['text'];
        if (! Product::isDescriptionText($description) || mb_strlen($description) <= mb_strlen($shopText)) {
            throw new B2bSourcesDescriptionRejected('po usunięciu twierdzeń spoza źródeł opis za krótki: '
                .mb_substr(implode(' | ', $droppedClaims), 0, 400));
        }

        $fields = $this->payloadFromExtraction($product, $extracted, $description, $pages);
        $dropped = [];
        $supported = static function (string $text) use ($sources, $guard, &$dropped, &$droppedClaims): bool {
            foreach (self::sourceClaims($text) as $code) {
                if (! str_contains($sources, $code)) {
                    $dropped[] = $text;

                    return false;
                }
            }
            if (! $guard->keeps($text)) {
                $claims = $guard->uncoveredClaims($text);
                $droppedClaims[] = ($claims !== [] ? implode(', ', $claims) : 'brak danych').': '.$text;

                return false;
            }

            return true;
        };
        $lists = $fields['lists'];
        foreach (['features', 'norms', 'certificates', 'materials', 'use_cases', 'specs'] as $key) {
            $lists[$key] = array_values(array_filter($lists[$key], $supported));
        }
        foreach ($lists['attributes'] as $key => $value) {
            if (is_string($value) && ! $supported($value)) {
                unset($lists['attributes'][$key]);
            }
        }

        return [
            'description' => mb_substr($description, 0, 10000),
            'payload' => [
                ...$lists,
                'source_urls' => array_values(array_unique(array_filter([$sheetUrl, $shopUrl], static fn (string $u): bool => $u !== ''))),
                'primary_source_url' => $sheetUrl,
                'primary_source_kind' => DescribeB2bProductFromDatasheetJob::PRIMARY_SOURCE_KIND,
                'confidence' => (float) ($extracted['confidence'] ?? 0),
                'from_cache' => false,
            ],
            'norms' => ProductNormsColumn::fromList($lists['norms']),
            'packaging' => $fields['packaging'],
            'dropped' => array_values(array_unique($dropped)),
            'dropped_claims' => array_values(array_unique($droppedClaims)),
        ];
    }

    /**
     * Opis karty z zapisanych źródeł (SourceDocumentStore, etap 1 — w tym etapie wyłącznie tryb cienia:
     * products:redescribe-from-sources, wersje shadow). Uogólnienie describeFromB2bSources: bez wyszukiwarki, bez
     * pobierania stron i plików, niczego nie zapisuje. Model dostaje tekst po filtrze stron, gdy był zapisany (jak
     * w zwykłym przebiegu), inaczej tekst surowy — najwyżej pięć źródeł, z zasadami „tylko źródła”.
     *
     * Kontrola jak w zwykłym przebiegu, nie jak przy B2B: zapisane źródła to te same strony z internetu, więc opis
     * przechodzi isUsableProductDescription, sito norm withoutUnsupportedNormClaims (zdanie albo pozycja listy z kodem
     * normy spoza źródeł wypada) i dowody (EvidenceExtractor) — tekst surowy pozostaje źródłem prawdy. Werdykt
     * tożsamości = werdykt zapisany przy stronie głównego źródła (primarySource po adresach źródeł).
     *
     * @param  list<SourceDoc|array{url: string, text: string, filtered_text?: string|null, final_url?: string|null, norm_facts?: list<mixed>, markup_codes?: list<mixed>, verdict?: string|null, verdict_reason?: string|null}>  $docs
     * @return array{description: string, payload: array<string, mixed>, norms: string|null, packaging: string|null, dropped_norm_claims: list<string>, unverified_claims: list<string>}
     *
     * @throws StoredSourcesDescriptionRejected
     */
    public function describeFromStoredSources(Product $product, array $docs): array
    {
        $pages = [];
        $seen = [];
        foreach ($docs as $doc) {
            $page = $doc instanceof SourceDoc ? $this->storedSourcePage($doc) : $this->givenSourcePage($doc);
            $key = $page !== null ? Product::normalizeShopUrl($page['url']) : '';
            if ($page !== null && ! isset($seen[$key])) {
                $seen[$key] = true;
                $pages[] = $page;
            }
        }
        if ($pages === []) {
            throw new StoredSourcesDescriptionRejected('brak zapisanych źródeł z tekstem');
        }
        $pages = array_slice($pages, 0, 5);
        $modelPages = array_map(static fn (array $page): array => [
            'url' => $page['url'],
            'text' => mb_substr($page['filtered_text'] !== '' ? $page['filtered_text'] : $page['text'], 0, 8000),
            ...($page['norm_facts'] !== [] ? ['norm_facts' => $page['norm_facts']] : []),
        ], $pages);
        $urls = array_column($pages, 'url');

        $list = [];
        foreach ($urls as $i => $url) {
            $list[] = ($i + 1).'. '.$url;
        }
        $extracted = $this->extractWithLlm($product, [], $modelPages, 'Źródła — wyłącznie te zapisane teksty stron wyrobu, nic spoza nich:'
            ."\n".implode("\n", $list)
            ."\n\n".EnrichmentDescriptionTemplates::sourcesOnlyRules(
                'ZASADY TEGO OPISU — mają pierwszeństwo przed instrukcją rodziny i przed zasadami pisania z polecenia systemowego:'
            ));
        $extracted = $this->enrichStructuredFieldsFromPages($extracted, $modelPages);

        $description = ProductDescriptionText::plain($this->modelDescription($extracted));
        if ($description === '') {
            throw new StoredSourcesDescriptionRejected($this->composeFullDescription($extracted) !== ''
                ? 'model nie potwierdził źródeł (confidence 0)'
                : 'model nie zwrócił opisu');
        }
        if (! $this->isUsableProductDescription($description, $product, $urls)) {
            throw new StoredSourcesDescriptionRejected('opis nie jest opisem tego wyrobu (za krótki, zrzut strony albo bez kodu i modelu)');
        }

        $extracted = $this->enrichStructuredFieldsFromPages($extracted, $modelPages, $description);
        $extracted = $this->withoutMissingDataListItems($extracted);
        $rawPages = array_map(static fn (array $page): array => ['url' => $page['url'], 'text' => $page['text']], $pages);
        try {
            $claimCheck = $this->withoutUnsupportedNormClaims($product, $description, $extracted, [...$modelPages, ...$rawPages]);
        } catch (RuntimeException $e) {
            throw new StoredSourcesDescriptionRejected($e->getMessage(), 0, $e);
        }
        $description = $claimCheck['description'];
        $fields = $this->payloadFromExtraction($product, $claimCheck['extracted'], $description, $modelPages);
        $lists = $fields['lists'];
        $evidence = $this->evidence()->extract($lists, $this->evidenceDocs($product, $pages));

        [$primaryUrl, $primaryKind] = $this->primarySource($urls, $product, []);
        $primary = null;
        foreach ($pages as $page) {
            if ($primaryUrl !== null && Product::normalizeShopUrl($page['url']) === Product::normalizeShopUrl($primaryUrl)) {
                $primary = $page;
                break;
            }
        }
        $profile = $this->profiles()->for($product);
        $identity = [
            'verdict' => $primary['verdict'] ?? null,
            'reason' => $primary !== null
                ? (string) ($primary['verdict_reason'] ?? 'werdykt zapisany przy stronie źródła')
                : 'karta bez źródła opisu',
            'key_type' => null,
            'key' => null,
            'where' => null,
            'source_url' => $primaryUrl,
            'profile' => $profile === null ? null : ($profile->profileKey ?? 'default'),
        ];

        $payload = [
            ...$lists,
            'source_urls' => $urls,
            'primary_source_url' => $primaryUrl,
            'primary_source_kind' => $primaryKind,
            'confidence' => (float) ($extracted['confidence'] ?? 0),
            'from_cache' => false,
            'identity' => $identity,
            'evidence' => $evidence['entries'],
            'evidence_summary' => ['explicit' => $evidence['explicit'], 'inferred' => $evidence['inferred']],
            'completeness' => $evidence['completeness'],
        ];
        if ($claimCheck['dropped_norm_claims'] !== []) {
            $payload['dropped_norm_claims'] = $claimCheck['dropped_norm_claims'];
        }
        if ($claimCheck['unverified_claims'] !== []) {
            $payload['unverified_claims'] = $claimCheck['unverified_claims'];
        }

        return [
            'description' => mb_substr($description, 0, 10000),
            'payload' => $payload,
            'norms' => ProductNormsColumn::fromList($lists['norms']),
            'packaging' => $fields['packaging'],
            'dropped_norm_claims' => $claimCheck['dropped_norm_claims'],
            'unverified_claims' => $claimCheck['unverified_claims'],
        ];
    }

    /**
     * Zapisane źródło z tekstem z dysku „sources”; null, gdy tekstu nie ma (plik usunięty, inny serwer).
     *
     * @return array{url: string, final_url: string, title: string, text: string, filtered_text: string, markup_codes: list<mixed>, norm_facts: list<mixed>, verdict: string|null, verdict_reason: string|null}|null
     */
    private function storedSourcePage(SourceDoc $doc): ?array
    {
        $text = trim((string) $this->sources()->get($doc->sha256));
        if ($text === '' || trim($doc->url) === '') {
            return null;
        }
        $filtered = $doc->filteredSha256 !== null ? trim((string) $this->sources()->get($doc->filteredSha256)) : '';

        return [
            'url' => $doc->url,
            'final_url' => (string) $doc->finalUrl,
            'title' => '',
            'text' => $text,
            'filtered_text' => $filtered,
            'markup_codes' => $doc->markupCodes,
            'norm_facts' => $doc->normFacts,
            'verdict' => $doc->verdict,
            'verdict_reason' => $doc->verdictReason,
        ];
    }

    /**
     * Źródło podane wprost (url, text i opcjonalnie tekst po filtrze, ramka norm, werdykt).
     *
     * @return array{url: string, final_url: string, title: string, text: string, filtered_text: string, markup_codes: list<mixed>, norm_facts: list<mixed>, verdict: string|null, verdict_reason: string|null}|null
     */
    private function givenSourcePage(mixed $doc): ?array
    {
        if (! is_array($doc)) {
            return null;
        }
        $url = trim((string) ($doc['url'] ?? ''));
        $text = trim((string) ($doc['text'] ?? ''));
        if ($url === '' || $text === '') {
            return null;
        }

        return [
            'url' => $url,
            'final_url' => trim((string) ($doc['final_url'] ?? '')),
            'title' => (string) ($doc['title'] ?? ''),
            'text' => $text,
            'filtered_text' => trim((string) ($doc['filtered_text'] ?? '')),
            'markup_codes' => is_array($doc['markup_codes'] ?? null) ? array_values($doc['markup_codes']) : [],
            'norm_facts' => is_array($doc['norm_facts'] ?? null) ? array_values($doc['norm_facts']) : [],
            'verdict' => is_string($doc['verdict'] ?? null) ? $doc['verdict'] : null,
            'verdict_reason' => is_string($doc['verdict_reason'] ?? null) ? $doc['verdict_reason'] : null,
        ];
    }

    /** Tyle stron z internetu idzie do modelu przy uzupełnianiu krótkiego opisu B2B. */
    private const SUPPLEMENT_WEB_PAGES = 4;

    /**
     * Krótki opis z konta B2B uzupełniony ze stron wyrobu w internecie (decyzja użytkownika 28.09.2026, kontrakt
     * „uzupełnianie krótkich opisów B2B”). Karta konta ma opis z B2B krótszy niż próg konta — szukamy wyrobu najpierw
     * na stronach wskazanych przy koncie ($context->hosts: indeks lokalny ograniczony do tych hostów, potem site: w
     * wyszukiwarce), a gdy tam nic, jak zwykle (paczka wyszukiwania karty). Pobrane strony przechodzą bramkę tożsamości
     * (keepConfirmedCardPages) i filtr stron; do modelu idą najwyżej SUPPLEMENT_WEB_PAGES stron, strony konta pierwsze.
     *
     * Źródła dostawcy idą do modelu bez bramki (tożsamość gwarantuje powiązanie B2B) i jako pierwszy tekst: opis z B2B,
     * tabelka ze strony sklepu (shop_fields_summary), karta katalogowa konta i normy producenta
     * (products.manufacturer_norms). Przy sprzeczności obowiązuje dostawca; wartości wyłącznie ze źródeł
     * (EnrichmentDescriptionTemplates::sourcesOnlyRules).
     *
     * Odrzucenie (karta zostaje z opisem z B2B):
     * - brak potwierdzonej strony z internetu przed modelem albo po filtrze stron → B2bSupplementNoPages;
     * - model nie potwierdził źródeł (confidence 0), opis nie jest opisem wyrobu;
     * - kod normy albo poziomu w opisie bez pokrycia w źródłach — przy niepustych normach producenta pokrycie liczy się
     *   wyłącznie w źródłach dostawcy (sklep nie dopisuje normy wbrew producentowi);
     * - po usunięciu zdań z twierdzeniami spoza źródeł (SourceClaimGuard) opis nie jest dłuższy od opisu z B2B.
     *
     * Niczego nie zapisuje w bazie (poza pamięcią wyszukiwań) — wynik zapisuje SupplementB2bDescriptionJob.
     *
     * @return array{description: string, payload: array<string, mixed>, norms: string|null, packaging: string|null, web_source_urls: list<string>, dropped: list<string>, dropped_claims: list<string>}
     *
     * @throws B2bSourcesDescriptionRejected
     * @throws B2bSupplementNoPages
     */
    public function supplementB2bDescription(Product $product, B2bSupplementContext $context, ?callable $progress = null): array
    {
        $this->supplementProgress = $progress !== null ? $progress(...) : null;
        try {
            return $this->supplementB2bDescriptionInner($product, $context);
        } finally {
            $this->supplementProgress = null;
        }
    }

    /**
     * Etap pracy dla okna postępu (SupplementB2bDescriptionJob zapisuje go przy próbie; może też przerwać pracę, gdy
     * użytkownik zatrzymał uzupełnianie).
     *
     * @var (\Closure(string): void)|null
     */
    private ?\Closure $supplementProgress = null;

    private function supplementStage(string $stage): void
    {
        if ($this->supplementProgress !== null) {
            ($this->supplementProgress)($stage);
        }
    }

    /**
     * @return array{description: string, payload: array<string, mixed>, norms: string|null, packaging: string|null, web_source_urls: list<string>, dropped: list<string>, dropped_claims: list<string>}
     */
    private function supplementB2bDescriptionInner(Product $product, B2bSupplementContext $context): array
    {
        $b2bText = trim($context->b2bText);
        $this->attemptLog()->reset();
        $this->attemptLog()->add('start', trim($product->sku.' · '.$product->name.' · uzupełnienie opisu B2B'));

        $webPages = $this->supplementWebPages($product, $context);
        if ($webPages === []) {
            throw new B2bSupplementNoPages('brak potwierdzonej strony wyrobu w internecie');
        }
        $this->supplementStage('filtr treści stron ('.count($webPages).')');
        $webPages = $this->sanitizePagesWithLlm($product, $webPages);
        $webPages = $this->fitPagesToBudget($webPages, self::SUPPLEMENT_WEB_PAGES, 4000, 12000);
        if ($webPages === []) {
            throw new B2bSupplementNoPages('strony wyrobu z internetu nie dały faktów o wyrobie (filtr stron)');
        }
        $webUrls = array_values(array_unique(array_filter(array_map(
            static fn (array $page): string => (string) ($page['url'] ?? ''),
            $webPages
        ))));

        // źródła dostawcy — jeden tekst, przed stronami z internetu
        $shopFields = trim((string) ($product->shop_fields_summary ?? ''));
        $sheet = DescribeB2bProductFromDatasheetJob::datasheet((int) $product->id, $context->accountId);
        $sheetText = $sheet !== null ? trim(B2bDocumentText::forCard((string) $sheet->text)) : '';
        $sheetUrl = $sheet !== null ? (string) $sheet->source_url : '';
        $normRows = ManufacturerNormFacts::rows($product->manufacturer_norms);
        $normsText = $normRows === [] ? '' : implode("\n", array_map(
            static fn (array $row): string => '- '.$row['label'].($row['value'] !== '' ? ': '.$row['value'] : ''),
            $normRows
        ));
        // Jeden tekst dostawcy mieści się w limicie strony dla modelu (fitPagesToBudget: 8000 znaków) — resztę miejsca
        // dostaje karta katalogowa, więc model widzi to samo, z czym potem porównujemy kody norm i twierdzenia.
        $supplierParts = [];
        // adresy źródeł dostawcy, które naprawdę weszły do tekstu dla modelu — pochodzenie opisu w source_urls
        $supplierUrls = [$context->b2bUrl];
        if ($b2bText !== '') {
            $supplierParts[] = 'Opis wyrobu z konta B2B dostawcy'.($context->b2bUrl !== '' ? ' ('.$context->b2bUrl.')' : '').":\n"
                .mb_substr($b2bText, 0, 3000);
        }
        if ($normsText !== '') {
            $normsUrl = ManufacturerNormFacts::sourceUrl($product->manufacturer_norms);
            $supplierParts[] = 'Normy producenta'.($normsUrl !== null ? ' ('.$normsUrl.')' : '').", dosłownie:\n"
                .mb_substr($normsText, 0, 1500);
            $supplierUrls[] = (string) $normsUrl;
        }
        if ($shopFields !== '') {
            $supplierParts[] = "Parametry ze strony sklepu:\n".mb_substr($shopFields, 0, 1500);
        }
        if ($sheetText !== '') {
            $head = 'Karta katalogowa dostawcy'.($sheetUrl !== '' ? ' ('.$sheetUrl.')' : '').":\n";
            $room = 7900 - mb_strlen(implode("\n\n", $supplierParts)) - mb_strlen($head);
            if ($room >= 300) {
                $supplierParts[] = $head.mb_substr($sheetText, 0, $room);
                $supplierUrls[] = $sheetUrl;
            } else {
                $sheetText = '';
            }
        }
        $supplierText = implode("\n\n", $supplierParts);
        $pages = [
            ...($supplierText !== '' ? [['url' => $context->b2bUrl !== '' ? $context->b2bUrl : 'zrodla-dostawcy-b2b', 'text' => $supplierText]] : []),
            ...$webPages,
        ];
        $withManufacturerNorms = $normRows !== [];

        $this->supplementStage('model pisze opis ('.count($webUrls).' stron z internetu)');
        $extracted = $this->extractWithLlm($product, [], $pages, 'Źródła — wyłącznie teksty poniżej, nic spoza nich:'
            ."\n1. Pierwszy tekst — źródła dostawcy: opis wyrobu z konta B2B"
            .($shopFields !== '' ? ', parametry ze strony sklepu' : '')
            .($sheetText !== '' ? ', karta katalogowa dostawcy' : '')
            .($withManufacturerNorms ? ', normy producenta' : '')
            .'. Gdy inne źródło podaje inną wartość, obowiązuje ten tekst.'
            ."\n2. Kolejne teksty — strony tego wyrobu znalezione w internecie (".implode(', ', $webUrls).'). Uzupełniają'
            .' opis dostawcy o fakty, których w nim brak.'
            .($withManufacturerNorms
                ? "\nOznaczenia norm i poziomów ochrony podawaj wyłącznie takie, jakie stoją w pierwszym tekście (źródła"
                    .' dostawcy i normy producenta) — normy i poziomy podane tylko na stronach z internetu pomiń.'
                : '')
            ."\n\n".EnrichmentDescriptionTemplates::sourcesOnlyRules(
                'ZASADY TEGO OPISU — mają pierwszeństwo przed instrukcją rodziny i przed zasadami pisania z polecenia systemowego:'
            ));
        $extracted = $this->enrichStructuredFieldsFromPages($extracted, $pages);

        $description = ProductDescriptionText::plain($this->modelDescription($extracted));
        if ($description === '') {
            throw new B2bSourcesDescriptionRejected($this->composeFullDescription($extracted) !== ''
                ? 'model nie potwierdził źródeł (confidence 0)'
                : 'model nie zwrócił opisu');
        }
        if ($this->looksLikeMissingCardMeta($description) || $this->looksLikeRawLocaleDump($description)
            || $this->looksLikeForeignOrPartsTableDump($description)) {
            throw new B2bSourcesDescriptionRejected('opis nie jest opisem wyrobu');
        }
        $this->supplementStage('sprawdzanie norm i twierdzeń ze źródłami');

        // kody norm i poziomów: w tekście, który model dostał — przy normach producenta tylko w źródłach dostawcy
        $webText = implode("\n", array_map(static fn (array $page): string => (string) ($page['text'] ?? ''), $webPages));
        $supplierKey = self::claimKey($supplierText);
        $allKey = self::claimKey($supplierText."\n".$webText);
        $claimKeys = $withManufacturerNorms ? $supplierKey : $allKey;
        $unsupported = array_values(array_filter(
            self::sourceClaims($description),
            static fn (string $code): bool => ! str_contains($claimKeys, $code),
        ));
        if ($unsupported !== []) {
            throw new B2bSourcesDescriptionRejected(($withManufacturerNorms
                ? 'oznaczenia albo poziomy norm spoza źródeł dostawcy (karta ma normy producenta) w opisie: '
                : 'oznaczenia albo poziomy norm spoza źródeł w opisie: ').implode(', ', $unsupported));
        }

        $guard = new SourceClaimGuard($supplierText."\n".$webText);
        $filtered = $guard->filterDescription($description);
        $droppedClaims = $filtered['dropped'];
        $description = $filtered['text'];
        if (! Product::isDescriptionText($description)
            || B2bDescriptionSupplement::plainLength($description) <= B2bDescriptionSupplement::plainLength($b2bText)) {
            throw new B2bSourcesDescriptionRejected('opis nie dłuższy niż opis z B2B'
                .($droppedClaims !== [] ? ' po usunięciu twierdzeń spoza źródeł: '.mb_substr(implode(' | ', $droppedClaims), 0, 400) : ''));
        }

        $fields = $this->payloadFromExtraction($product, $extracted, $description, $pages);
        $dropped = [];
        $supported = static function (string $text) use ($claimKeys, $guard, &$dropped, &$droppedClaims): bool {
            foreach (self::sourceClaims($text) as $code) {
                if (! str_contains($claimKeys, $code)) {
                    $dropped[] = $text;

                    return false;
                }
            }
            if (! $guard->keeps($text)) {
                $claims = $guard->uncoveredClaims($text);
                $droppedClaims[] = ($claims !== [] ? implode(', ', $claims) : 'brak danych').': '.$text;

                return false;
            }

            return true;
        };
        $lists = $fields['lists'];
        foreach (['features', 'norms', 'certificates', 'materials', 'use_cases', 'specs'] as $key) {
            $lists[$key] = array_values(array_filter($lists[$key], $supported));
        }
        foreach ($lists['attributes'] as $key => $value) {
            if (is_string($value) && ! $supported($value)) {
                unset($lists['attributes'][$key]);
            }
        }

        return [
            'description' => mb_substr($description, 0, 10000),
            'payload' => [
                ...$lists,
                'source_urls' => array_values(array_unique(array_filter(
                    [...$supplierUrls, ...$webUrls],
                    static fn (string $u): bool => $u !== ''
                ))),
                'primary_source_url' => $webUrls[0],
                'primary_source_kind' => 'b2b_supplement',
                'confidence' => (float) ($extracted['confidence'] ?? 0),
                'from_cache' => false,
            ],
            'norms' => ProductNormsColumn::fromList($lists['norms']),
            'packaging' => $fields['packaging'],
            'web_source_urls' => $webUrls,
            'dropped' => array_values(array_unique($dropped)),
            'dropped_claims' => array_values(array_unique($droppedClaims)),
        ];
    }

    /**
     * Potwierdzone strony wyrobu z internetu dla supplementB2bDescription, przed filtrem stron: (1) indeks lokalny
     * ograniczony do hostów konta, (2) site: na hostach konta, (3) zwykła paczka wyszukiwania. Kolejny krok tylko wtedy,
     * gdy poprzednie nie dały żadnej potwierdzonej strony. Strony z hostów konta pierwsze, dalej jak w zwykłym opisie
     * (producent, ranga domen); marka „tylko producent” z kartą producenta w puli — same strony producenta.
     *
     * @return list<array<string, mixed>>
     */
    private function supplementWebPages(Product $product, B2bSupplementContext $context): array
    {
        $hosts = [];
        foreach ($context->hosts as $host) {
            $bare = preg_replace('/^www\./', '', mb_strtolower(trim((string) $host))) ?? '';
            if ($bare !== '') {
                $hosts[$bare] = true;
            }
        }
        $hosts = array_keys($hosts);
        $tried = [];

        $pages = [];
        if ($hosts !== []) {
            $this->supplementStage('indeks stron konta ('.implode(', ', $hosts).')');
            $pages = $this->fetchSupplementPages($product, $context, $this->search->catalogHitsOnHosts($product, $hosts), [], $tried);
            if ($pages === []) {
                $this->supplementStage('szukanie na stronach konta ('.implode(', ', $hosts).')');
                $pages = $this->fetchSupplementPages($product, $context, $this->search->searchOnHosts($product, $hosts), [], $tried);
            }
        }
        $mfrDomains = $this->manufacturers->domainsFor($product);
        $searchErrors = [];
        if ($pages === []) {
            $this->supplementStage('zwykłe szukanie w internecie');
            $pack = $this->searchPackForEnrichment($product);
            $results = $pack['results'];
            $searchErrors = is_array($pack['errors'] ?? null) ? $pack['errors'] : [];
            $mfrDomains = $this->manufacturers->discoverFromResults($product, array_column($results, 'url'));
            $pages = $this->fetchSupplementPages(
                $product,
                $context,
                $this->rankResultsForDescription($results, $product, $mfrDomains),
                $mfrDomains,
                $tried
            );
        }
        if ($pages === []) {
            // „brak stron” przy awarii wyszukiwarki to nie wynik — próba ma wrócić do ponowienia, a nie zamknąć się
            // statusem no_pages dla tych samych źródeł
            $outage = $this->engineOutageDetail(implode(' | ', array_slice($searchErrors, 0, 2)));
            if ($outage !== null) {
                throw new B2bSupplementSearchOutage('Wyszukiwarka nie odpowiedziała przy uzupełnianiu opisu '.$product->sku
                    .' — nie wiadomo, czy strona wyrobu istnieje. '.$outage);
            }
            $this->attemptLog()->add('desc', 'uzupełnienie opisu B2B: brak potwierdzonej strony wyrobu');

            return [];
        }

        $onAccount = [];
        $rest = [];
        foreach ($pages as $page) {
            if ($this->urlOnHosts((string) ($page['url'] ?? ''), $hosts)) {
                $onAccount[] = $page;
            } else {
                $rest[] = $page;
            }
        }
        $pages = array_merge(
            $this->orderPagesForDescription($onAccount, $product, $mfrDomains),
            $this->orderPagesForDescription($rest, $product, $mfrDomains)
        );
        $pages = $this->manufacturerOnlyPages($product, $pages, false)['pages'];
        $pages = array_slice($pages, 0, self::SUPPLEMENT_WEB_PAGES);
        $this->attemptLog()->add('page', 'uzupełnienie opisu B2B: '.count($pages).' stron', urls: array_column($pages, 'url'));

        return $pages;
    }

    /**
     * Pobranie i bramka tożsamości dla jednej partii wyników. Adres karty u dostawcy (b2bUrl i link synchronizacji
     * w shop_source_url) to źródło dostawcy, a nie strona z internetu — nie liczy się do stron; tak samo hosty
     * wykluczone (dropBlockedSourceHosts) i adresy sprawdzone w poprzedniej partii.
     *
     * @param  list<array<string, mixed>>  $results
     * @param  list<string>  $mfrDomains
     * @param  array<string, true>  $tried
     * @return list<array<string, mixed>>
     */
    private function fetchSupplementPages(Product $product, B2bSupplementContext $context, array $results, array $mfrDomains, array &$tried): array
    {
        $supplierUrl = static fn (string $url): bool => ($context->b2bUrl !== ''
                && Product::normalizeShopUrl($url) === Product::normalizeShopUrl($context->b2bUrl))
            || ($product->isHintedShopUrl($url) && ! $product->isTrustedShopUrl($url));
        $fresh = [];
        foreach ($this->dropBlockedSourceHosts($results, $product) as $row) {
            $url = (string) ($row['url'] ?? '');
            $key = mb_strtolower($url);
            if ($url === '' || isset($tried[$key]) || $supplierUrl($url) || ProductImageDownloader::looksLikeImageUrl($url)) {
                continue;
            }
            $tried[$key] = true;
            $fresh[] = $row;
        }
        if ($fresh === []) {
            return [];
        }
        $this->supplementStage('pobieranie stron ('.count($fresh).') i bramka wariantu');
        $fetched = $this->pages->fetch($fresh, (string) $product->sku, self::SUPPLEMENT_WEB_PAGES, [], $product);
        $this->attemptLog()->add('fetch', count($fetched['pages']).' stron HTML', urls: array_column($fetched['pages'], 'url'));

        $kept = [];
        foreach ($this->keepConfirmedCardPages($product, $fetched['pages']) as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($supplierUrl($url)) {
                continue;
            }
            if (! $this->supplementPageNamesCardVariant($product, $page)) {
                $this->attemptLog()->add('page', 'uzupełnienie opisu B2B: strona innego wariantu albo bez kodu karty — pominięta', urls: [$url]);

                continue;
            }
            $kept[] = $page;
        }

        return $kept;
    }

    /**
     * Strona z internetu opisuje wariant tej karty, a nie tylko model (produkcja 28.09.2026, Bolle): bramka tożsamości
     * (keepConfirmedCardPages) przyjmuje stronę po samej nazwie modelu, a u Bolle warianty różnią się wyłącznie kodem —
     * TRACPSF dostał strony TRACPSI i TRACPSJ (inna soczewka), PSSNESF028 cztery warianty NESS+, a przez nazwę serii
     * także SILIUM i SLAM (inne wyroby). Do uzupełnienia wolno więc tylko stronę:
     * - z kodem karty (SKU albo kod bez rozmiaru) w adresie albo tytule, albo
     * - z kodem karty w treści, gdy adres i tytuł nie niosą kodu innej karty tego producenta z naszego katalogu
     *   (strona innego wariantu wymienia nasz kod w „podobnych produktach”).
     * Kod krótszy niż SUPPLEMENT_MIN_CODE_CHARS znaków (litery i cyfry) zostaje przy samej bramce tożsamości — w treści
     * strony trafiałby przypadkiem.
     *
     * @param  array<string, mixed>  $page
     */
    private function supplementPageNamesCardVariant(Product $product, array $page): bool
    {
        // lista, nie klucze tablicy: kod z samych cyfr („46017”, Lahti Pro) jako klucz staje się liczbą, a funkcje
        // tekstowe poniżej przy strict_types rzucały wtedy TypeError (produkcja 08.10.2026)
        $own = [];
        foreach ([(string) $product->sku, $this->identity->catalogSkuWithoutSize($product)] as $code) {
            $key = self::supplementCodeKey($code);
            if (mb_strlen($key) >= self::SUPPLEMENT_MIN_CODE_CHARS && ! in_array($key, $own, true)) {
                $own[] = $key;
            }
        }
        if ($own === []) {
            return true;
        }
        $head = rawurldecode((string) ($page['url'] ?? ''))."\n".(string) ($page['title'] ?? '');
        foreach ($own as $key) {
            if (self::textCarriesCode($head, $key)) {
                return true;
            }
        }
        $inText = false;
        foreach ($own as $key) {
            if (self::textCarriesCode((string) ($page['text'] ?? ''), $key)) {
                $inText = true;
                break;
            }
        }
        if (! $inText) {
            return false;
        }
        $headKey = self::supplementCodeKey($head);
        foreach ($this->manufacturerCatalogCodes($product) as $foreign) {
            if (in_array($foreign, $own, true)) {
                continue;
            }
            // kod innej karty zawarty w naszym (COBPSI w COBPSIX) to nie inny wariant na stronie
            $insideOwn = false;
            foreach ($own as $key) {
                if (str_contains($key, $foreign)) {
                    $insideOwn = true;
                    break;
                }
            }
            if (! $insideOwn && str_contains($headKey, $foreign) && self::textCarriesCode($head, $foreign)) {
                return false;
            }
        }

        return true;
    }

    /** Najkrótszy kod (litery i cyfry), który przy uzupełnianiu opisu B2B musi stać na stronie wyrobu. */
    private const SUPPLEMENT_MIN_CODE_CHARS = 5;

    /** @var array<string, list<string>> producent => kody jego kart (supplementCodeKey), na czas przebiegu */
    private array $manufacturerCatalogCodes = [];

    /**
     * Kody kart tego samego producenta z katalogu (bez tej karty), w postaci supplementCodeKey, co najmniej
     * SUPPLEMENT_MIN_CODE_CHARS znaków.
     *
     * @return list<string>
     */
    private function manufacturerCatalogCodes(Product $product): array
    {
        $manufacturer = mb_strtolower(trim((string) $product->manufacturer));
        if ($manufacturer === '') {
            return [];
        }
        if (! isset($this->manufacturerCatalogCodes[$manufacturer])) {
            $codes = [];
            foreach (Product::query()->whereRaw('LOWER(TRIM(manufacturer)) = ?', [$manufacturer])->toBase()->pluck('sku', 'id') as $id => $sku) {
                $key = self::supplementCodeKey((string) $sku);
                if (mb_strlen($key) >= self::SUPPLEMENT_MIN_CODE_CHARS) {
                    $codes[(int) $id] = $key;
                }
            }
            $this->manufacturerCatalogCodes[$manufacturer] = $codes;
        }
        $codes = $this->manufacturerCatalogCodes[$manufacturer];
        unset($codes[(int) $product->id]);

        return array_values(array_unique($codes));
    }

    /** Kod do porównania: małe litery i cyfry, bez separatorów („PSSBL30-014” → „pssbl30014”) — ProductCodeMatch::key. */
    private static function supplementCodeKey(string $code): string
    {
        return ProductCodeMatch::key($code);
    }

    /**
     * Kod stoi w tekście jako osobny ciąg — między znakami kodu wolno separator („PSSBL30-014”, „TRACPSF”), przed
     * i za nim nie ma litery ani cyfry (TRACPSF nie trafia w TRACPSFX). Reguła wspólna z werdyktem tożsamości
     * źródła: ProductCodeMatch::textCarries.
     */
    private static function textCarriesCode(string $text, string $key): bool
    {
        return ProductCodeMatch::textCarries($text, $key);
    }

    /**
     * @param  list<string>  $hosts  bez „www.”, małymi literami
     */
    private function urlOnHosts(string $url, array $hosts): bool
    {
        $host = preg_replace('/^www\./', '', mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''))) ?? '';
        if ($host === '') {
            return false;
        }
        foreach ($hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fakty, które muszą wystąpić w źródłach, w postaci do porównania (claimKey):
     * - kody poziomów ochrony: EN 388 („4131A”, „4544C” — przecięcie coup test ma poziomy do 5), EN 407 („X1XXXX”),
     *   bez lat („2016”, „2020”);
     * - oznaczenia norm z wydaniem i zmianą („EN 388:2016+A1:2018”, „EN ISO 21420:2020”) — 21.09.2026 model napisał
     *   przy PVC/40 „EN 374-1:2016”, a źródło podaje „EN ISO 374-1:2016”. Źródła bywają ze sobą niezgodne (CITRIN:
     *   PDF „A1:2019”, strona sklepu „A1:2018”) — wystarczy, że oznaczenie jest w jednym z nich.
     *   Przedrostek „PN-” zostaje poza dopasowaniem (ta sama norma).
     * Reguła wspólna z dowodami (EvidenceExtractor): SourceClaims::claims.
     *
     * @return list<string>
     */
    private static function sourceClaims(string $text): array
    {
        return SourceClaims::claims($text);
    }

    /**
     * Zwykłe wzbogacanie: oznaczenia i poziomy norm (sourceClaims) muszą stać dosłownie w tekście źródeł — jak przy
     * opisie z B2B. Do 07.10.2026 sprawdzano je tylko tam; model pisał przy cenniku z pliku kod EN 388 czy wydanie normy
     * z pamięci. Zdanie opisu albo pozycja listy z kodem spoza źródeł wypada (dropped_norm_claims). Źródła = teksty
     * stron opisu, ich ramki norm, normy producenta z karty, tabelka dostawcy, cennik i nazwa — wszystko, co dostał model
     * albo co kod dokłada do opisu (alignEn388WithManufacturer).
     *
     * Twierdzenia o właściwościach (SourceClaimGuard) tylko zapisujemy (unverified_claims): słownik strażnika jest po
     * polsku, a strony producentów bywają angielskie — wycinanie wyrzucałoby poprawne zdania.
     *
     * @param  array<string, mixed>  $extracted
     * @param  list<array<string, mixed>>  $pages
     * @return array{description: string, extracted: array<string, mixed>, dropped_norm_claims: list<string>, unverified_claims: list<string>}
     */
    private function withoutUnsupportedNormClaims(Product $product, string $description, array $extracted, array $pages): array
    {
        $parts = [(string) $product->name, (string) ($product->shop_fields_summary ?? '')];
        foreach ($pages as $page) {
            $parts[] = (string) ($page['text'] ?? '');
            if (is_array($page['norm_facts'] ?? null)) {
                $parts[] = (string) json_encode($page['norm_facts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        foreach ([$product->manufacturer_norms, $product->price_list_attributes] as $structured) {
            if (is_array($structured)) {
                $parts[] = (string) json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        // Etap 3 (W7): wiersz atrybutu szablonu sklepu bez oznaczenia normy („EN166 Lens Marking = PrB420”) nie
        // potwierdza normy — sama etykieta atrybutu to nie źródło.
        $sourceText = NormListSanity::withoutTemplateAttributeRows(implode("\n", $parts));
        $sources = self::claimKey($sourceText);
        $sourceNorms = self::normDesignations($sourceText);
        // Same cyfry („1200” z „1200 x 1800 mm” maty Coba przeliczonej z „1,2 m”) to poziom normy tylko w zdaniu
        // o normie — kod z literą/X („4131A”, „X1XXXX”) i oznaczenie normy sprawdzamy zawsze.
        $unsupported = static function (string $text) use ($sources, $sourceNorms): array {
            $normContext = preg_match('/\bEN\s*(?:ISO\s*)?(?:388|407|511|374|381|1149)\b|poziom|level/iu', $text) === 1;

            return array_values(array_filter(
                self::sourceClaims($text),
                static function (string $code) use ($sources, $sourceNorms, $normContext): bool {
                    if (preg_match('/^(?:EN|ISO)/', $code) === 1) {
                        return ! self::normDesignationSupported($code, $sourceNorms);
                    }

                    return ! str_contains($sources, $code) && ($normContext || preg_match('/^\d+$/', $code) !== 1);
                },
            ));
        };

        $filtered = SourceClaimGuard::filterSentences($description, static function (string $sentence) use ($unsupported): array {
            $codes = $unsupported($sentence);

            return $codes === [] ? [] : ['normy spoza źródeł '.implode(', ', $codes)];
        });
        $dropped = $filtered['dropped'];
        $checked = $filtered['text'];
        if ($dropped !== []) {
            $this->attemptLog()->add('desc', 'usunięto zdania z oznaczeniami norm spoza źródeł: '.mb_substr(implode(' | ', $dropped), 0, 400));
            if (! Product::isDescriptionText($checked)) {
                throw new RuntimeException('Opis po usunięciu oznaczeń norm spoza źródeł jest za krótki: '.mb_substr(implode(' | ', $dropped), 0, 300));
            }
        }

        $keepItem = static function (mixed $item) use ($unsupported, &$dropped): bool {
            if (! is_string($item)) {
                return true;
            }
            $codes = $unsupported($item);
            if ($codes !== []) {
                $dropped[] = 'normy spoza źródeł '.implode(', ', $codes).': '.$item;

                return false;
            }

            return true;
        };
        foreach (['features', 'norms', 'certificates', 'materials', 'use_cases', 'specs'] as $key) {
            if (is_array($extracted[$key] ?? null)) {
                $extracted[$key] = array_values(array_filter($extracted[$key], $keepItem));
            }
        }
        if (is_array($extracted['attributes'] ?? null)) {
            foreach ($extracted['attributes'] as $key => $value) {
                if (is_string($value) && ! $keepItem($value)) {
                    $extracted['attributes'][$key] = null;
                } elseif (is_array($value)) {
                    $extracted['attributes'][$key] = array_values(array_filter($value, $keepItem));
                }
            }
        }

        $unverified = (new SourceClaimGuard($sourceText))->filterDescription($checked)['dropped'];
        if ($unverified !== []) {
            $this->attemptLog()->add('desc', 'twierdzenia bez pokrycia w źródłach (tylko zapis): '.mb_substr(implode(' | ', $unverified), 0, 300));
        }

        return [
            'description' => $checked,
            'extracted' => $extracted,
            'dropped_norm_claims' => array_values(array_unique(array_map(static fn (string $d): string => mb_substr($d, 0, 300), $dropped))),
            'unverified_claims' => array_values(array_unique(array_map(static fn (string $d): string => mb_substr($d, 0, 300), $unverified))),
        ];
    }

    /**
     * Normy wymienione w tekście źródeł: numer z częścią („13997”, „374-1”) => wydania podane przy nim. Przedrostki
     * EN / ISO / IEC / PN- i myślnik („EN-388”) nie mają znaczenia — strona angielska pisze „ISO 13997”, sklep
     * „EN 374-1:2016”, a model poprawnie „EN ISO 13997” i „EN ISO 374-1:2016”; wydanie bywa w nawiasie („EN 388 (2016)”).
     * Reguła wspólna z dowodami: SourceClaims::designations.
     *
     * @return array<string, list<string>>
     */
    private static function normDesignations(string $text): array
    {
        return SourceClaims::designations($text);
    }

    /**
     * Oznaczenie normy z opisu (klucz z sourceClaims, np. „ENISO374-1:2016+A1:2018”) ma pokrycie, gdy źródło wymienia
     * tę normę (numer i część; „EN 374” pokrywa „EN ISO 374-1”), a wydanie — tylko gdy źródło podaje jakiekolwiek
     * wydanie tej normy: strona z samym „EN ISO 20345” nie przeczy „EN ISO 20345:2022”, strona z „:2011” — tak.
     * Reguła wspólna z dowodami: SourceClaims::designationSupported.
     *
     * @param  array<string, list<string>>  $sourceNorms
     */
    private static function normDesignationSupported(string $claim, array $sourceNorms): bool
    {
        return SourceClaims::designationSupported($claim, $sourceNorms);
    }

    /** Tekst do porównania faktów ze źródłem: wielkie litery, bez białych znaków (PDF łamie „4131 A”, „EN 388 :2016”). */
    private static function claimKey(string $text): string
    {
        return SourceClaims::key($text);
    }

    /**
     * Sam akapit opisowy — listy (spec/cechy/normy…) idą do enrichment_payload i UI.
     *
     * @param  array<string, mixed>  $extracted
     */
    private function composeFullDescription(array $extracted): string
    {
        return ProductDescriptionText::plain((string) ($extracted['description'] ?? ''));
    }

    /**
     * Opis, który wolno zapisać: wyłącznie tekst napisany przez model, i tylko gdy model
     * potwierdził źródła (confidence > 0). Przy confidence 0 model sam mówi, że strony nie
     * opisują produktu — audyt 22.09.2026 znalazł 166 kart z confidence 0 i zapisanym opisem,
     * w tym 104 z surowym tekstem strony (CAPTCHA, cennik, baner cookies). Karta bez takiego
     * opisu idzie do ręki; tekstu strony jako opisu nie zapisujemy nigdy.
     *
     * @param  array<string, mixed>  $extracted
     */
    private function modelDescription(array $extracted): string
    {
        if ((float) ($extracted['confidence'] ?? 0) <= 0.0) {
            return '';
        }

        return $this->composeFullDescription($extracted);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($v): bool => is_string($v) && trim($v) !== ''
        ));
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $results
     * @return list<array{url: string, text: string}>
     */
    private function fetchPageSnippets(array $results): array
    {
        $out = [];
        foreach ($results as $row) {
            $url = $row['url'];
            if (ProductImageDownloader::looksLikeImageUrl($url)) {
                continue;
            }
            $snippet = trim($row['snippet'] ?? '');
            if ($snippet !== '') {
                $out[] = ['url' => $url, 'text' => mb_substr($snippet, 0, 1200)];

                continue;
            }

            // HTML tylko gdy brak snippetu Tavily — i krótko
            try {
                // tylko serwery z adresem publicznym, każde przekierowanie sprawdzone
                $response = (new PublicUrlFetcher)->get(fn () => Http::timeout(12)
                    ->withHeaders(['User-Agent' => 'SUPON-ProductEnrichment/1.0']), $url, PublicUrlFetcher::PAGE_MAX_BYTES);
                if (! $response->successful()) {
                    continue;
                }
                $html = $response->body();
                $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
                $out[] = ['url' => $url, 'text' => mb_substr(trim($text), 0, 1200)];
            } catch (Throwable) {
                continue;
            }
        }

        return $out;
    }
}
