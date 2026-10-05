<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ProductSourcesNotFoundException;
use App\Models\ManufacturerSite;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductSourcePrice;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\B2b\B2bSupplementContext;
use App\Services\Enrichment\EnrichmentAttemptLog;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\PriceListSourceSettings;
use App\Services\Enrichment\ProductEnrichmentService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Cenniki → „Z pliku”: strony z opisami przy cenniku z pliku (prośba użytkownika 05.10.2026, plan
 * SUPON_AI_Plan_Zrodla_opisow_cennikow_2026-10-05). Strony cennika działają tylko bez karty producenta w puli:
 * „najpierw” — strona cennika z kodem karty wypycha resztę, a bez niej dotychczasowa kolejność; „tylko” — bez strony
 * cennika nie ma opisu (bez modelu), a awaria wyszukiwarki to „ponów”, nie „wpisz ręcznie”.
 */
final class EnrichmentPriceListSitesTest extends TestCase
{
    use RefreshDatabase;

    private const LIST_HOST_1 = 'cennik-pierwszy.example';

    private const LIST_HOST_2 = 'cennik-drugi.example';

    private const LIST_1 = 'https://cennik-pierwszy.example/rekawice-norvik-kx-2210';

    private const LIST_2 = 'https://www.cennik-drugi.example/produkt/norvik-kx-2210';

    /** sklep z listy „Strony wyszukiwarka” (retailer_domains) */
    private const GLOBAL_SHOP = 'https://behapownia.pl/rekawice-norvik-kx-2210';

    private const OTHER_SHOP = 'https://obcy-sklep.example/norvik-kx-2210';

    private const DESCRIPTION = 'Rękawice ochronne Norvik KX-2210 wykonane z dzianiny poliestrowej i powlekane nitrylem na części chwytnej. '
        .'Powłoka nitrylowa zapewnia pewny chwyt na suchych i lekko zaolejonych powierzchniach. '
        .'Mankiet ściągaczowy chroni nadgarstek i ułatwia zakładanie. '
        .'Rękawice są przeznaczone do prac montażowych, w magazynie i w przemyśle lekkim.';

    /** @var array<string, string> adres => HTML */
    private array $pagesHtml = [];

    /** @var list<string> adresy stron pobrane przez Http (bez wyszukiwarek) */
    private array $requested = [];

    /** @var list<string> wiadomości użytkownika wysłane do modelu */
    private array $prompts = [];

    /** @var list<string> */
    private array $modelSources = [];

    private string $modelDescription = self::DESCRIPTION;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'enrichment.retailer_domains' => ['behapownia.pl'],
            'enrichment.preferred_domains' => [],
        ]);
        Queue::fake();
        Storage::fake('public');
        $this->modelSources = [self::LIST_1, self::GLOBAL_SHOP];
        $this->fakeModel();
        Http::fake(function (Request $request): PromiseInterface {
            $url = $request->url();
            $this->requested[] = $url;
            $html = $this->pagesHtml[$url] ?? null;

            return $html !== null
                ? Http::response($html, 200, ['Content-Type' => 'text/html'])
                : Http::response('', 404);
        });
        foreach ([self::LIST_1, self::LIST_2, self::GLOBAL_SHOP, self::OTHER_SHOP] as $url) {
            $this->pagesHtml[$url] = $this->cardHtml($url);
        }
    }

    public function test_price_list_page_wins_over_a_shop_from_the_global_list(): void
    {
        $product = $this->product();
        $this->priceList($product, [self::LIST_HOST_1, self::LIST_HOST_2]);
        $this->search(
            both: [$this->row(self::GLOBAL_SHOP), $this->row(self::OTHER_SHOP)],
            catalog: [$this->row(self::LIST_1)],
            configure: static fn (MockInterface $search) => $search->shouldNotReceive('searchOnHosts'),
        );

        $this->enrich($product);
        $product->refresh();

        $extraction = $this->extractionPrompt();
        $this->assertStringContainsString(self::LIST_1, $extraction, 'strona cennika idzie do modelu');
        $this->assertStringNotContainsString('behapownia', $extraction, 'sklep z listy „Strony wyszukiwarka” odpada');
        $this->assertStringNotContainsString('obcy-sklep', $extraction);

        $payload = (array) $product->enrichment_payload;
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertSame([self::LIST_1], $payload['source_urls'] ?? null, 'model wskazał też sklep spoza puli');
        $this->assertSame(self::LIST_1, $payload['primary_source_url'] ?? null);
        $this->assertSame('shop', $payload['primary_source_kind'] ?? null);
        $list = PriceList::query()->firstOrFail();
        $this->assertSame([
            'price_list_id' => (int) $list->id,
            'mode' => PriceList::MODE_FIRST,
            'hosts_sha1' => $list->enrichmentHostsSha1(),
        ], $payload['price_list_sources'] ?? null);
        $this->assertStringContainsString('strony cennika NORVIK', json_encode($product->enrichment_trace, JSON_UNESCAPED_UNICODE) ?: '');

        $cache = ProductEnrichmentCache::query()->first();
        $this->assertNotNull($cache);
        $this->assertArrayNotHasKey('price_list_sources', (array) $cache->enrichment_payload, 'pamięć SKU bez ustawień cennika');
    }

    public function test_site_search_runs_on_first_four_hosts_when_index_has_no_coded_card(): void
    {
        $product = $this->product();
        $hosts = [self::LIST_HOST_1, self::LIST_HOST_2, 'trzeci.example', 'czwarty.example', 'piaty.example'];
        $this->priceList($product, $hosts);
        $this->search(
            both: [$this->row(self::OTHER_SHOP)],
            catalog: [],
            configure: static fn (MockInterface $search) => $search->shouldReceive('searchOnHosts')->once()
                ->with(Mockery::any(), array_slice($hosts, 0, 4), 'strony cennika NORVIK')
                ->andReturn([['url' => self::LIST_2, 'title' => 'Norvik KX-2210', 'snippet' => '']]),
        );

        $this->enrich($product);

        $this->assertStringContainsString(self::LIST_2, $this->extractionPrompt());
        $this->assertStringNotContainsString('obcy-sklep', $this->extractionPrompt());
    }

    public function test_price_list_pages_are_ordered_by_list_position(): void
    {
        $product = $this->product();
        [$service] = $this->serviceWithSettings($product, [self::LIST_HOST_1, self::LIST_HOST_2]);
        $pages = array_map(fn (string $url): array => ['url' => $url, 'title' => 'Norvik KX-2210', 'text' => $this->pageText()], [
            self::OTHER_SHOP, self::LIST_2, self::GLOBAL_SHOP, self::LIST_1,
        ]);

        $ordered = (new \ReflectionMethod($service, 'orderPagesForDescription'))->invoke($service, $pages, $product, []);
        $this->assertSame([self::LIST_1, self::LIST_2, self::OTHER_SHOP, self::GLOBAL_SHOP], array_column($ordered, 'url'));

        $ranked = (new \ReflectionMethod($service, 'rankResultsForDescription'))->invoke($service, $pages, $product, []);
        $this->assertSame([self::LIST_1, self::LIST_2, self::GLOBAL_SHOP, self::OTHER_SHOP], array_column($ranked, 'url'));
        $score = new \ReflectionMethod($service, 'descriptionSourceScore');
        $this->assertSame(90, $score->invoke($service, self::LIST_1, $product, [], []));
        $this->assertSame(89, $score->invoke($service, self::LIST_2, $product, [], []));
        $this->assertSame(100, $score->invoke($service, self::LIST_1, new Product([
            'sku' => 'KX-2210', 'name' => 'x', 'manufacturer' => 'NORVIK', 'shop_source_url' => self::LIST_1,
        ]), [], []), 'adres wskazany ręcznie zostaje nad stroną cennika');

        $pool = (new \ReflectionMethod($service, 'manufacturerOnlyPages'))->invoke($service, $product, $ordered);
        $this->assertSame([self::LIST_1, self::LIST_2], array_column($pool['pages'], 'url'));
        $this->assertTrue($pool['list_sites']);
        $this->assertFalse($pool['cut']);
    }

    public function test_page_of_another_variant_on_a_price_list_host_gets_no_promotion(): void
    {
        $product = $this->product();
        // inna karta tego producenta w katalogu — jej kod w adresie strony cennika zdradza inny wariant
        Product::query()->create(['sku' => 'KX-2215', 'name' => 'Rękawice Norvik KX-2215', 'manufacturer' => 'NORVIK']);
        [$service] = $this->serviceWithSettings($product, [self::LIST_HOST_1]);
        $variant = 'https://cennik-pierwszy.example/rekawice-norvik-kx-2215';
        $pages = [
            ['url' => self::OTHER_SHOP, 'title' => 'Norvik KX-2210', 'text' => $this->pageText()],
            ['url' => $variant, 'title' => 'Norvik KX-2215', 'text' => $this->pageText().' Podobne produkty: KX-2210.'],
        ];

        $ordered = (new \ReflectionMethod($service, 'orderPagesForDescription'))->invoke($service, $pages, $product, []);
        $this->assertSame([self::OTHER_SHOP, $variant], array_column($ordered, 'url'), 'strona innego wariantu nie idzie na początek');

        $pool = (new \ReflectionMethod($service, 'manufacturerOnlyPages'))->invoke($service, $product, $ordered);
        $this->assertFalse($pool['list_sites'], 'bez strony tego wariantu dawna kolejność');
        $this->assertSame([self::OTHER_SHOP, $variant], array_column($pool['pages'], 'url'));
    }

    public function test_only_mode_without_a_price_list_page_goes_to_manual_without_the_model(): void
    {
        $product = $this->product();
        $this->priceList($product, [self::LIST_HOST_1], PriceList::MODE_ONLY);
        $this->search(both: [$this->row(self::OTHER_SHOP), $this->row(self::GLOBAL_SHOP)], catalog: []);

        $error = $this->enrichExpectingFailure($product);
        $product->refresh();

        $this->assertStringContainsString('Nie znaleziono strony wyrobu KX-2210 na stronach cennika NORVIK', $error);
        $this->assertStringContainsString('Cenniki → Z pliku', $error);
        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status);
        $this->assertSame([], $this->prompts, 'model nie jest wołany');
        $this->assertNotContains(self::OTHER_SHOP, $this->requested, 'sklep spoza cennika nie jest pobierany');
        $this->assertNotContains(self::GLOBAL_SHOP, $this->requested);
    }

    public function test_only_mode_with_shop_pages_in_the_pool_ends_without_the_model(): void
    {
        $product = $this->product();
        [$service] = $this->serviceWithSettings($product, [self::LIST_HOST_1], PriceList::MODE_ONLY);
        $pages = [['url' => self::OTHER_SHOP, 'title' => 'Norvik KX-2210', 'text' => $this->pageText()]];

        $pool = (new \ReflectionMethod($service, 'manufacturerOnlyPages'))->invoke($service, $product, $pages);
        $this->assertSame([], $pool['pages']);

        $retry = (new \ReflectionMethod($service, 'describeFromPages'))->invoke($service, $product, $pages);
        $this->assertSame('', $retry['description']);
        $this->assertSame([], $this->prompts, 'pusta pula — bez modelu');
    }

    public function test_only_mode_search_outage_fails_for_retry_instead_of_manual(): void
    {
        $product = $this->product();
        $this->priceList($product, [self::LIST_HOST_1], PriceList::MODE_ONLY);
        $this->search(both: [], catalog: [], errors: ['SearXNG: silniki zablokowane (too many requests)']);

        $error = $this->enrichExpectingFailure($product);
        $product->refresh();

        $this->assertStringContainsString('Wyszukiwarka nie odpowiedziała', $error);
        $this->assertSame(Product::ENRICHMENT_FAILED, $product->enrichment_status);
        $this->assertSame([], $this->prompts);
    }

    /** Karta producenta w puli tnie ją jak dotąd — strona cennika (tu sklep) nie wraca do opisu. */
    public function test_manufacturer_card_keeps_only_the_manufacturer_even_with_price_list_sites(): void
    {
        $mfr = 'https://bemoregreen.eu/pl/plaszcz/2-plaszcz-meski-906.html';
        $shop = 'https://behapownia.pl/meski-plaszcz-przeciwdeszczowy-bemoregreen-906';
        $product = Product::query()->create([
            'sku' => '906', 'name' => 'PŁASZCZ MĘSKI', 'manufacturer' => 'AJ GROUP',
            'catalog_price_net' => 200, 'purchase_price' => 150, 'stock' => 1,
        ]);
        $this->priceList($product, ['behapownia.pl']);
        $this->pagesHtml[$mfr] = '<!doctype html><html><head><title>PŁASZCZ MĘSKI 906 - BeMoreGreen</title></head><body>'
            .'<h1>PŁASZCZ MĘSKI 906</h1><div class="product-description"><p>'
            .str_repeat('Prosty płaszcz przeciwdeszczowy 906 do kolan z materiału Plavitex Eco, kaptur ściągany sznurkiem. ', 6)
            .'</p></div><span itemprop="sku">BEMOREGREEN-906-00001</span></body></html>';
        $this->pagesHtml[$shop] = '<!doctype html><html><head><title>Męski płaszcz przeciwdeszczowy BeMoreGreen 906</title></head><body>'
            .'<h1>Męski płaszcz przeciwdeszczowy BeMoreGreen 906</h1><div class="product-description"><p>'
            .str_repeat('Męski płaszcz przeciwdeszczowy AJ GROUP BeMoreGreen 906, sklep behapownia. ', 8).'</p></div></body></html>';
        $this->modelSources = [$mfr, $shop];
        $this->modelDescription = 'Męski płaszcz przeciwdeszczowy 906 marki AJ GROUP (Be More Green), prosty fason do kolan. '
            .'Wykonany z lekkiego, recyklingowego materiału Plavitex Eco, miękkiego i przyjemnego w dotyku. '
            .'Wyposażony w obszerny kaptur ściągany sznurkiem, zapięcie na napy oraz dwie zewnętrzne kieszenie z patkami. '
            .'Szwy zgrzewane w technologii Solar Welding zwiększają ich wytrzymałość. Zaprojektowany i wykonany w Polsce.';
        $this->search(both: [
            ['url' => $shop, 'title' => 'Męski płaszcz przeciwdeszczowy BeMoreGreen 906', 'snippet' => 'Płaszcz 906 AJ GROUP'],
            ['url' => $mfr, 'title' => 'PŁASZCZ MĘSKI 906 - BeMoreGreen', 'snippet' => 'Płaszcz męski 906'],
        ], catalog: [['url' => $shop, 'title' => 'Męski płaszcz przeciwdeszczowy BeMoreGreen 906', 'snippet' => '']]);

        $this->enrich($product);
        $product->refresh();

        $extraction = $this->extractionPrompt();
        $this->assertStringContainsString($mfr, $extraction);
        $this->assertStringNotContainsString('behapownia', $extraction, 'strona cennika nie wchodzi obok karty producenta');
        $this->assertSame([$mfr], $product->enrichment_payload['source_urls'] ?? null);
        $this->assertSame('manufacturer', $product->enrichment_payload['primary_source_kind'] ?? null);
    }

    public function test_supplement_takes_only_price_list_sites_in_only_mode_and_them_first_in_first_mode(): void
    {
        $product = $this->product();
        $more = ['https://trzeci-sklep.example/norvik-kx-2210', 'https://czwarty-sklep.example/norvik-kx-2210'];
        // cztery sklepy przed stronami cennika — uzupełnienie bierze najwyżej cztery adresy
        $results = [
            $this->row(self::OTHER_SHOP), $this->row(self::GLOBAL_SHOP), $this->row($more[0]), $this->row($more[1]),
            $this->row(self::LIST_2), $this->row(self::LIST_1),
        ];
        // strony nie odpowiadają (404) — liczy się, które uzupełnienie pobiera (kolejność pobierania ustala fetcher)
        $this->pagesHtml = [];

        [$service] = $this->serviceWithSettings($product, [self::LIST_HOST_1, self::LIST_HOST_2], PriceList::MODE_ONLY);
        $supplement = new \ReflectionMethod($service, 'supplementDescriptionFromOtherSites');
        $supplement->invoke($service, $product, $results, [], [], '', true);
        $this->assertEqualsCanonicalizing([self::LIST_1, self::LIST_2], $this->pageRequests([...$more]), 'tylko strony cennika');

        $this->requested = [];
        [$service] = $this->serviceWithSettings($product, [self::LIST_HOST_1, self::LIST_HOST_2]);
        $supplement->invoke($service, $product, $results, [], [], '', false);
        $this->assertEqualsCanonicalizing(
            [self::LIST_1, self::LIST_2, self::OTHER_SHOP, self::GLOBAL_SHOP],
            $this->pageRequests([...$more]),
            'najpierw strony cennika, potem reszta do limitu czterech'
        );

        // bez ustawień — dawne cztery pierwsze adresy
        $this->requested = [];
        $service = app(ProductEnrichmentService::class);
        $supplement->invoke($service, $product, $results, [], [], '', false);
        $this->assertEqualsCanonicalizing([self::OTHER_SHOP, self::GLOBAL_SHOP, ...$more], $this->pageRequests([...$more]));
    }

    public function test_card_without_file_slot_and_unsaved_product_run_as_before(): void
    {
        $service = app(ProductEnrichmentService::class);
        $settingsFor = new \ReflectionMethod($service, 'priceListSourcesFor');
        $this->assertNull($settingsFor->invoke($service, new Product(['sku' => 'KX-2210', 'manufacturer' => 'NORVIK'])));

        $product = $this->product();
        // cennik ze stronami istnieje, ale karta nie ma w nim slotu pliku
        PriceList::query()->create([
            'manufacturer' => 'NORVIK', 'version' => '2026', 'original_filename' => 'norvik.xlsx',
            'enrichment_sites' => [self::LIST_HOST_1], 'enrichment_sites_mode' => PriceList::MODE_ONLY,
        ]);
        $this->search(
            both: [$this->row(self::OTHER_SHOP), $this->row(self::LIST_1)],
            catalog: [],
            configure: static function (MockInterface $search): void {
                $search->shouldNotReceive('catalogHitsOnHosts');
                $search->shouldNotReceive('searchOnHosts');
            },
        );
        $this->modelSources = [self::OTHER_SHOP];

        $this->enrich($product);
        $product->refresh();

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertArrayNotHasKey('price_list_sources', (array) $product->enrichment_payload);
        $this->assertStringContainsString('obcy-sklep', $this->extractionPrompt(), 'bez ustawień kolejność jak dotąd');
    }

    public function test_b2b_supplement_of_a_card_with_file_slot_does_not_see_price_list_sites(): void
    {
        $product = $this->product();
        $this->priceList($product, [self::LIST_HOST_1]);
        $this->search(
            both: [$this->row(self::OTHER_SHOP), $this->row(self::LIST_1)],
            catalog: [],
            configure: static function (MockInterface $search): void {
                $search->shouldNotReceive('catalogHitsOnHosts');
                $search->shouldNotReceive('searchOnHosts');
            },
        );
        $service = app(ProductEnrichmentService::class);
        $context = new B2bSupplementContext(
            accountId: 1,
            linkIds: [],
            descriptionHash: sha1('x'),
            sourceDescriptionHash: null,
            sourceSha1: sha1('x'),
            b2bText: 'Rękawice Norvik KX-2210.',
            b2bUrl: 'https://b2b.dostawca.example/kx-2210',
            hosts: [],
            hostsSha1: sha1(''),
            minChars: 1000,
            productDescription: 'Rękawice Norvik KX-2210.',
        );

        $webPages = new \ReflectionMethod($service, 'supplementWebPages');
        $withSlot = array_column($webPages->invoke($service, $product, $context), 'url');

        // ta sama karta bez slotu pliku — kolejność stron musi być identyczna
        ProductSourcePrice::query()->where('product_id', $product->id)->delete();
        $withoutSlot = array_column($webPages->invoke($service, $product, $context), 'url');

        $this->assertEqualsCanonicalizing([self::OTHER_SHOP, self::LIST_1], $withSlot);
        $this->assertSame($withoutSlot, $withSlot, 'strona cennika bez pierwszeństwa w ścieżce B2B');
        $this->assertStringNotContainsString('strony cennika', json_encode(app(EnrichmentAttemptLog::class)->snapshot($product), JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_sku_cache_is_skipped_when_the_price_list_has_sites(): void
    {
        $product = $this->product(['enrichment_status' => Product::ENRICHMENT_NONE]);
        $this->priceList($product, [self::LIST_HOST_1]);
        $key = ProductEnrichmentCache::normalizeKey('NORVIK', 'KX-2210');
        ProductEnrichmentCache::query()->create([
            ...$key,
            'description' => 'Opis z pamięci SKU z innej strony: rękawice Norvik KX-2210 z powłoką nitrylową, do prac montażowych i magazynowych.',
            'enrichment_payload' => ['confidence' => 0.9, 'source_urls' => [self::OTHER_SHOP]],
            'image_urls' => [],
            'source_urls' => [self::OTHER_SHOP],
        ]);
        $this->search(
            both: [$this->row(self::OTHER_SHOP)],
            catalog: [$this->row(self::LIST_1)],
            configure: static fn (MockInterface $search) => $search->shouldReceive('catalogHitsOnHosts')->once()
                ->andReturn([['url' => self::LIST_1, 'title' => 'Norvik KX-2210', 'snippet' => '']]),
        );

        app(ProductEnrichmentService::class)->enrichProduct($product, false);
        $product->refresh();

        $this->assertFalse((bool) ($product->enrichment_payload['from_cache'] ?? true));
        $this->assertSame(self::LIST_1, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertStringContainsString('pamięć SKU pominięta', json_encode($product->enrichment_trace, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** discoverFromResults uznaje host cennika z nazwą marki za domenę producenta — przebieg traktuje go jak sklep. */
    public function test_price_list_host_looking_like_the_brand_stays_a_shop(): void
    {
        $brandShop = 'https://norvik-sklep.example/rekawice-kx-2210';
        $product = $this->product();
        $this->assertContains(
            'norvik-sklep.example',
            app(ManufacturerDomainResolver::class)->discoverFromResults($product, [$brandShop]),
            'resolver widzi w hoście markę'
        );

        $this->priceList($product, ['norvik-sklep.example']);
        $this->pagesHtml[$brandShop] = $this->cardHtml($brandShop);
        $this->modelSources = [$brandShop];
        $this->search(both: [$this->row(self::OTHER_SHOP), $this->row($brandShop)], catalog: [$this->row($brandShop)]);

        $this->enrich($product);
        $product->refresh();

        $this->assertSame($brandShop, $product->enrichment_payload['primary_source_url'] ?? null);
        $this->assertSame('shop', $product->enrichment_payload['primary_source_kind'] ?? null, 'strona cennika to nie strona producenta');
        $this->assertStringContainsString('host cennika to sklep', json_encode($product->enrichment_trace, JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertStringNotContainsString('obcy-sklep', $this->extractionPrompt());
    }

    /**
     * „Tylko producent i strony cennika”: producentem jest też domena przypisana marce w Administracji → „Strony
     * wyszukiwarka” (manufacturer_sites), a nie tylko domena z konfiguracji — jej strona zostaje, sklep spoza listy odpada.
     */
    public function test_only_mode_keeps_manufacturer_domain_assigned_in_admin(): void
    {
        $mfr = 'https://norvik-producent.example/produkty/kx-2210';
        $product = $this->product();
        ManufacturerSite::remember('norvik', 'NORVIK', ['norvik-producent.example'], 'manual');
        $this->priceList($product, [self::LIST_HOST_1], PriceList::MODE_ONLY);
        $service = app(ProductEnrichmentService::class);
        (new \ReflectionProperty($service, 'listSources'))->setValue(
            $service,
            (new \ReflectionMethod($service, 'priceListSourcesFor'))->invoke($service, $product)
        );
        $pages = [
            ['url' => self::OTHER_SHOP, 'title' => 'Norvik KX-2210', 'text' => $this->pageText()],
            ['url' => $mfr, 'title' => 'Norvik KX-2210', 'text' => $this->pageText()],
        ];

        $pool = (new \ReflectionMethod($service, 'manufacturerOnlyPages'))->invoke($service, $product, $pages);
        $this->assertSame([$mfr], array_column($pool['pages'], 'url'));

        $kept = (new \ReflectionMethod($service, 'dropOutsideListSources'))->invoke($service, [
            $this->row(self::OTHER_SHOP), $this->row($mfr), $this->row(self::LIST_1),
        ], $product);
        $this->assertSame([$mfr, self::LIST_1], array_column($kept, 'url'));
    }

    /**
     * Karta producenta, którego domenę zna tylko Administracja (manufacturer_sites „manual”), ma pierwszeństwo przed
     * stroną cennika w obu trybach — jak bez ustawień, gdzie zostaje sama (listedSitePagesFirst).
     */
    public function test_admin_assigned_manufacturer_card_beats_price_list_page_in_both_modes(): void
    {
        $mfr = 'https://norvik-producent.example/produkty/kx-2210';
        $product = $this->product();
        ManufacturerSite::remember('norvik', 'NORVIK', ['norvik-producent.example'], 'manual');
        $this->priceList($product, [self::LIST_HOST_1]);
        $pages = [
            ['url' => self::LIST_1, 'title' => 'Norvik KX-2210', 'text' => $this->pageText()],
            ['url' => $mfr, 'title' => 'Norvik KX-2210', 'text' => $this->pageText()],
        ];

        foreach ([PriceList::MODE_FIRST, PriceList::MODE_ONLY] as $mode) {
            PriceList::query()->update(['enrichment_sites_mode' => $mode]);
            $service = app(ProductEnrichmentService::class);
            (new \ReflectionProperty($service, 'listSources'))->setValue(
                $service,
                (new \ReflectionMethod($service, 'priceListSourcesFor'))->invoke($service, $product)
            );

            $pool = (new \ReflectionMethod($service, 'manufacturerOnlyPages'))->invoke($service, $product, $pages);
            // „najpierw”: lista „Strony wyszukiwarka” (domena producenta jest na niej, strona cennika nie);
            // „tylko”: producent bez sklepów — w obu zostaje sama karta producenta
            $this->assertSame([$mfr], array_column($pool['pages'], 'url'), "tryb {$mode}: karta producenta ma pierwszeństwo");
        }
    }

    /**
     * Host cennika zapisany przez wykrywanie jako „discovered” zostaje sklepem; przypisany ręcznie („manual”) — także
     * dla AJ GROUP przez domeny PROS — jest producentem.
     */
    public function test_discovered_list_host_stays_a_shop_but_manual_one_is_the_manufacturer(): void
    {
        $product = $this->product();
        $this->priceList($product, [self::LIST_HOST_1, 'pros-sklep.example']);
        ManufacturerSite::remember('norvik', 'NORVIK', [self::LIST_HOST_1], 'discovered');
        [$service] = $this->serviceWithSettings($product, [self::LIST_HOST_1]);
        (new \ReflectionMethod($service, 'priceListSourcesFor'))->invoke($service, $product);
        $position = new \ReflectionMethod($service, 'listSiteShopPosition');
        $this->assertSame(0, $position->invoke($service, self::LIST_1, $product), '„discovered” to nie przypisanie');
        $this->assertSame(90, (new \ReflectionMethod($service, 'descriptionSourceScore'))->invoke($service, self::LIST_1, $product, [], [], [], ''));

        ManufacturerSite::remember('norvik', 'NORVIK', [self::LIST_HOST_1], 'manual');
        (new \ReflectionMethod($service, 'priceListSourcesFor'))->invoke($service, $product);
        $this->assertNull($position->invoke($service, self::LIST_1, $product), 'przypisany ręcznie = producent');

        $aj = Product::query()->create(['sku' => '906', 'name' => 'PŁASZCZ MĘSKI 906', 'manufacturer' => 'AJ GROUP']);
        $this->priceList($aj, ['pros-sklep.example']);
        ManufacturerSite::remember('pros', 'PROS', ['pros-sklep.example'], 'manual');
        [$ajService] = $this->serviceWithSettings($aj, ['pros-sklep.example']);
        (new \ReflectionMethod($ajService, 'priceListSourcesFor'))->invoke($ajService, $aj);
        $this->assertNull(
            (new \ReflectionMethod($ajService, 'listSiteShopPosition'))->invoke($ajService, 'https://pros-sklep.example/906', $aj),
            'domena PROS przypisana ręcznie to producent AJ GROUP'
        );
    }

    /** Ranga 90 tylko dla wyniku strony cennika z kodem karty w adresie albo tytule. */
    public function test_price_list_result_without_card_code_gets_no_top_rank(): void
    {
        $product = $this->product();
        [$service] = $this->serviceWithSettings($product, [self::LIST_HOST_1]);
        $score = new \ReflectionMethod($service, 'descriptionSourceScore');

        $this->assertSame(90, $score->invoke($service, self::LIST_1, $product, [], [], [], ''));
        $this->assertSame(90, $score->invoke($service, 'https://cennik-pierwszy.example/p/123', $product, [], [], [], 'Rękawice Norvik KX-2210'));
        $this->assertSame(10, $score->invoke($service, 'https://cennik-pierwszy.example/rekawice-norvik-kx-2215', $product, [], [], [], 'Norvik KX-2215'));
    }

    public function test_settings_are_cleared_after_the_run(): void
    {
        $product = $this->product();
        $this->priceList($product, [self::LIST_HOST_1]);
        $this->search(both: [$this->row(self::OTHER_SHOP)], catalog: [$this->row(self::LIST_1)]);
        $service = app(ProductEnrichmentService::class);

        $service->enrichProduct($product, true);

        $this->assertNull((new \ReflectionProperty($service, 'listSources'))->getValue($service));
    }

    // ---- pomocnicze ----

    /** @param  array<string, mixed>  $extra */
    private function product(array $extra = []): Product
    {
        return Product::query()->create([
            'sku' => 'KX-2210',
            'name' => 'Rękawice ochronne Norvik KX-2210',
            'manufacturer' => 'NORVIK',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'currency' => 'PLN',
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            ...$extra,
        ]);
    }

    /** @param  list<string>  $hosts */
    private function priceList(Product $product, array $hosts, string $mode = PriceList::MODE_FIRST): PriceList
    {
        $list = PriceList::query()->create([
            'manufacturer' => (string) $product->manufacturer,
            'version' => '2026',
            'original_filename' => 'cennik.xlsx',
            'enrichment_sites' => $hosts,
            'enrichment_sites_mode' => $mode,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $list->id,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);

        return $list;
    }

    /**
     * Serwis z ustawieniami cennika wpisanymi jak na starcie enrichProduct — do wywołań metod przez refleksję.
     *
     * @param  list<string>  $hosts
     * @return array{0: ProductEnrichmentService}
     */
    private function serviceWithSettings(Product $product, array $hosts, string $mode = PriceList::MODE_FIRST): array
    {
        $service = app(ProductEnrichmentService::class);
        (new \ReflectionProperty($service, 'listSources'))->setValue(
            $service,
            new PriceListSourceSettings(1, (string) $product->manufacturer, $hosts, $mode, sha1($mode."\n".implode("\n", $hosts)))
        );

        return [$service];
    }

    private function enrich(Product $product): void
    {
        app(ProductEnrichmentService::class)->enrichProduct($product, true);
    }

    private function enrichExpectingFailure(Product $product): string
    {
        try {
            app(ProductEnrichmentService::class)->enrichProduct($product, true);
        } catch (ProductSourcesNotFoundException $e) {
            return $e->getMessage();
        }
        $this->fail('oczekiwano ProductSourcesNotFoundException');
    }

    /**
     * @param  list<array<string, string>>  $both
     * @param  list<array<string, string>>  $catalog
     * @param  list<string>  $errors
     * @param  (callable(MockInterface): mixed)|null  $configure
     */
    private function search(array $both, array $catalog, array $errors = [], ?callable $configure = null): void
    {
        $search = Mockery::mock(HybridWebSearchService::class);
        if ($configure !== null) {
            $configure($search);
        }
        $search->shouldReceive('catalogHitsOnHosts')->zeroOrMoreTimes()->andReturn($catalog);
        $search->shouldReceive('searchOnHosts')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('lastHostSearchErrors')->zeroOrMoreTimes()->andReturn($errors);
        $search->shouldReceive('dropListingResults')->zeroOrMoreTimes()->andReturnUsing(static fn (array $results): array => $results);
        $search->shouldReceive('moreCatalogHits')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchMappedRetailers')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchWebWithoutLocalIndex')->zeroOrMoreTimes()->andReturn(['results' => [], 'images' => [], 'errors' => []]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();
        $search->shouldReceive('searchBothPhases')->zeroOrMoreTimes()->andReturn(['results' => $both, 'errors' => []]);
        $this->app->instance(HybridWebSearchService::class, $search);
    }

    /** @return array{url: string, title: string, snippet: string} */
    private function row(string $url): array
    {
        return ['url' => $url, 'title' => 'Rękawice Norvik KX-2210', 'snippet' => 'Rękawice ochronne Norvik KX-2210'];
    }

    private function fakeModel(): void
    {
        $handler = function (array $messages): array {
            $user = (string) ($messages[1]['content'] ?? '');
            if (str_contains((string) ($messages[0]['content'] ?? ''), 'filtrem treści')) {
                $pages = [];
                foreach ((array) (json_decode($user, true)['pages'] ?? []) as $page) {
                    $pages[] = ['url' => $page['url'] ?? '', 'text' => $page['text'] ?? ''];
                }

                return ['pages' => $pages];
            }
            $this->prompts[] = $user;

            return [
                'description' => $this->modelDescription,
                'features' => ['powłoka nitrylowa', 'mankiet ściągaczowy'],
                'specs' => ['Materiał: dzianina poliestrowa'],
                'norms' => [],
                'certificates' => [],
                'materials' => ['nitryl', 'poliester'],
                'use_cases' => ['prace montażowe'],
                'attributes' => ['kategoria_bhp' => 'rekawice'],
                'image_urls' => [],
                'source_urls' => $this->modelSources,
                'confidence' => 0.9,
            ];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    private function extractionPrompt(): string
    {
        $extraction = collect($this->prompts)->first(fn (string $p): bool => str_contains($p, 'Strony (po filtrze AI)'));
        $this->assertNotNull($extraction, 'wiadomość do modelu z treścią stron');

        return (string) $extraction;
    }

    /**
     * @param  list<string>  $extra  dodatkowe adresy kart do wypatrywania
     * @return list<string> pobrane strony kart (bez zapytań wyszukiwarek, readera i obrazków), każda raz
     */
    private function pageRequests(array $extra = []): array
    {
        $cards = [self::LIST_1, self::LIST_2, self::GLOBAL_SHOP, self::OTHER_SHOP, ...$extra];

        return array_values(array_unique(array_filter(
            $this->requested,
            static fn (string $url): bool => in_array($url, $cards, true)
        )));
    }

    private function pageText(): string
    {
        return str_repeat('Rękawice ochronne NORVIK KX-2210 z dzianiny poliestrowej, powlekane nitrylem. ', 8);
    }

    private function cardHtml(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return '<html><head><title>Rękawice Norvik KX-2210</title></head><body><h1>Rękawice ochronne NORVIK KX-2210</h1>'
            .'<div class="product-description"><p>Rękawice NORVIK KX-2210 z dzianiny poliestrowej, powlekane nitrylem ('.$host.'). '
            .'Mankiet ściągaczowy ułatwia zakładanie.</p><p>'
            .str_repeat('Powłoka nitrylowa na części chwytnej rękawic KX-2210. ', 20).'</p></div></body></html>';
    }
}
