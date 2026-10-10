<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductImage;
use App\Models\ProductSourcePin;
use App\Models\ProductSourcePrice;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\B2b\B2bSupplementContext;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Services\Enrichment\SourceDocumentStore;
use App\Services\Enrichment\Sources\SourcePins;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Pobieranie opisu karty z mapy importera cennika (product_source_pins, 10.10.2026): karta cennika map_only z przypiętą
 * stroną czyta wyłącznie tę stronę (bez wyszukiwarki, jedno pobranie), publikuje z MAPPED_SOURCE_REASON mimo twardej
 * bazy z innej strony, zapisuje enrichment_payload.source_map; image_url z mapy staje się zdjęciem głównym, a inne
 * zdjęcia zostają; bez image_url zdjęcie bierze się tylko ze strony z mapy (bez kart sklepów). Adres wskazany przez
 * człowieka wygrywa z mapą, a cennik dawnym sposobem ignoruje wiersze mapy.
 */
final class EnrichmentSourceMapPinTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://www.example.com/produkt/rekawice-z100';

    private const OTHER_PAGE = 'https://www.example.com/produkt/rekawice-z200';

    private const HUMAN_PAGE = 'https://www.example.org/rekawice-zeta-z100';

    private const IMAGE_MAPPED = 'https://www.example.com/media/Z100-packshot.jpg';

    private const IMAGE_ON_PAGE = 'https://www.example.com/media/zeta-Z100-czarne.jpg';

    private const FOREIGN_IMAGE = 'https://www.example.net/img/Z100-rekawice.jpg';

    private const DESCRIPTION = 'Rękawice ochronne ZETA Z100 wykonane z nitrylu, przeznaczone do prac montażowych i magazynowych. '
        .'Powłoka nitrylowa na dłoni zapewnia pewny chwyt suchych i lekko zaolejonych przedmiotów, a dzianinowy wkład '
        .'zapewnia wygodę przy długiej pracy. Mankiet ściągaczowy chroni przed zsuwaniem się rękawicy z dłoni.';

    private const OLD_DESCRIPTION = 'Rękawice ZETA Z100 z poprzedniego pobrania ze strony innego wyrobu — opis bazowy z twardym werdyktem '
        .'i dużą liczbą dowodów, który przy zwykłej regule nie ustąpiłby nowemu opisowi.';

    private ?string $lastUserPrompt = null;

    private int $pageImageStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(SourceDocumentStore::DISK);
        config()->set('enrichment.store_sources', true);
    }

    public function test_mapped_page_is_the_only_source_publishes_with_mapped_reason_and_sets_mapped_image_as_primary(): void
    {
        $card = $this->card(['enrichment_status' => Product::ENRICHMENT_DONE, 'description' => self::OLD_DESCRIPTION]);
        // twarda baza z innej strony z większą liczbą dowodów — przy zwykłej regule nowy opis byłby propozycją
        app(DescriptionVersionStore::class)->record($card, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_ENRICHMENT, [
            'description' => self::OLD_DESCRIPTION,
            'primary_source_url' => self::OTHER_PAGE,
            'identity_verdict' => 'hard',
            'evidence_count' => 30,
        ]);
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $this->pin($card, $list, ['image_url' => self::IMAGE_MAPPED, 'spec' => ['Rozmiar: 9', 'Kolor: Czarny']]);
        $account = B2bAccount::query()->create(['username' => 'zeta', 'password' => 'sekret', 'sites' => ['b2b.example.com']]);
        $b2b = $this->webImage($card, 'https://b2b.example.com/img/Z100.jpg', ['b2b_account_id' => $account->id]);
        $foreign = $this->webImage($card, 'https://www.example.net/img/stare-Z100.jpg');
        $search = $this->search();
        $service = $this->service($search);

        $service->enrichProduct($card, true);

        $card->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $card->enrichment_status, (string) $card->enrichment_error);
        $this->assertSame(self::DESCRIPTION, $card->description);
        $this->assertNull($card->review_reason);
        $version = $service->lastRunVersion();
        $this->assertNotNull($version);
        $this->assertSame(ProductDescriptionVersion::STATUS_PUBLISHED, $version->status);
        $this->assertSame(DescriptionVersionStore::MAPPED_SOURCE_REASON, $version->reason);

        $payload = $card->enrichment_payload;
        $this->assertSame([
            'url' => self::PAGE,
            'source_kind' => ProductSourcePin::KIND_MANUFACTURER,
            'match_kind' => ProductSourcePin::MATCH_EXACT_CODE,
            'match_key' => 'Z100',
            'image_url' => self::IMAGE_MAPPED,
            'spec' => ['Rozmiar: 9', 'Kolor: Czarny'],
            'importer_key' => 'zeta-2026',
            'importer_version' => 2,
        ], $payload['source_map'] ?? null);
        $this->assertArrayNotHasKey('parts_table', $payload);
        $this->assertSame('hard', $payload['identity']['verdict'] ?? null);
        $this->assertSame('manufacturer_code', $payload['identity']['key_type'] ?? null);
        $this->assertSame('Z100', $payload['identity']['key'] ?? null);
        $this->assertSame('mapa importera zeta-2026: exact_code Z100', $payload['identity']['reason'] ?? null);
        $this->assertSame([self::PAGE], $payload['source_urls'] ?? null);
        $this->assertSame(self::PAGE, $payload['primary_source_url'] ?? null);
        // linie cennika z mapy zastępują linie modelu z tą samą etykietą, linia neutralna zostaje
        $specs = $payload['specs'] ?? [];
        $this->assertContains('Rozmiar: 9', $specs);
        $this->assertContains('Kolor: Czarny', $specs);
        $this->assertContains('Materiał wkładu: poliamid', $specs);
        $this->assertNotContains('Kolor: Biały', $specs);

        $prompt = (string) $this->lastUserPrompt;
        $this->assertStringContainsString('Źródło opisu: wyłącznie strona producenta '.self::PAGE, $prompt);
        $this->assertStringContainsString('Dane cennika tej karty: Rozmiar: 9; Kolor: Czarny', $prompt);
        $this->assertStringContainsString('ZASADY TEGO OPISU', $prompt);

        // bez wyszukiwarki: jedno pobranie strony z mapy, zdjęcie z mapy; zdjęcia strony nie są dobierane
        $this->assertCount(1, Http::recorded(static fn (Request $r): bool => str_starts_with($r->url(), self::PAGE)));
        Http::assertNotSent(static fn (Request $r): bool => $r->url() === self::IMAGE_ON_PAGE || str_contains($r->url(), self::OTHER_PAGE));
        $search->shouldNotHaveReceived('searchBothPhases');
        $search->shouldNotHaveReceived('searchOnHosts');
        $search->shouldNotHaveReceived('catalogHitsOnHosts');
        $search->shouldNotHaveReceived('searchMappedRetailers');
        $search->shouldNotHaveReceived('shopCardsForImage');

        // zdjęcie z mapy główne; zdjęcie z B2B i stare zdjęcie z internetu zostają jako dodatkowe (nic nie jest odrzucane)
        $images = $card->images()->orderBy('sort_order')->get();
        $this->assertSame(self::IMAGE_MAPPED, $images->first()?->source_url);
        $this->assertTrue((bool) $images->first()?->is_primary);
        $this->assertCount(3, $images);
        $this->assertNotNull(ProductImage::query()->find($b2b->id));
        $this->assertNotNull(ProductImage::query()->find($foreign->id));
        $this->assertSame(1, $images->where('is_primary', true)->count());
    }

    public function test_mapped_page_without_image_takes_image_only_from_that_page(): void
    {
        $card = $this->card();
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $this->pin($card, $list);
        $search = $this->search();
        $service = $this->service($search);

        $service->enrichProduct($card, false);

        $card->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $card->enrichment_status, (string) $card->enrichment_error);
        $this->assertSame(DescriptionVersionStore::MAPPED_SOURCE_REASON, $service->lastRunVersion()?->reason);
        $this->assertArrayHasKey('image_url', $card->enrichment_payload['source_map'] ?? []);
        $this->assertNull($card->enrichment_payload['source_map']['image_url']);
        // model podał też zdjęcie z kodem w nazwie z cudzego sklepu — nie stoi na stronie z mapy, więc odpada
        $this->assertSame([self::IMAGE_ON_PAGE], $card->images()->pluck('source_url')->all());
        Http::assertNotSent(static fn (Request $r): bool => $r->url() === self::FOREIGN_IMAGE);
        $search->shouldNotHaveReceived('searchBothPhases');
        $search->shouldNotHaveReceived('shopCardsForImage');
    }

    public function test_mapped_page_without_image_does_not_look_for_images_on_shop_cards_when_page_image_fails(): void
    {
        $card = $this->card();
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $this->pin($card, $list);
        $this->pageImageStatus = 404;
        $search = $this->search();
        $service = $this->service($search);

        $service->enrichProduct($card, false);

        $card->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $card->enrichment_status);
        $this->assertSame(self::DESCRIPTION, $card->description);
        $this->assertSame(0, $card->images()->count());
        $this->assertStringStartsWith('Opis OK, nie udało się pobrać zdjęcia', (string) $card->enrichment_error);
        $search->shouldNotHaveReceived('shopCardsForImage');
        $search->shouldNotHaveReceived('searchBothPhases');
        Http::assertNotSent(static fn (Request $r): bool => $r->url() === self::FOREIGN_IMAGE);
    }

    public function test_link_chosen_by_a_person_wins_over_the_map(): void
    {
        $card = $this->card(['shop_source_url' => self::HUMAN_PAGE]);
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $this->pin($card, $list, ['image_url' => self::IMAGE_MAPPED]);
        $this->assertNull(app(SourcePins::class)->pinFor($card));
        $this->assertNull(app(SourcePins::class)->blockedReason($card));
        $search = $this->search();
        $service = $this->service($search);

        $service->enrichProduct($card, false);

        $card->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $card->enrichment_status, (string) $card->enrichment_error);
        $this->assertArrayNotHasKey('source_map', (array) $card->enrichment_payload);
        $this->assertSame(self::HUMAN_PAGE, $card->enrichment_payload['primary_source_url'] ?? null);
        $this->assertSame(DescriptionVersionStore::MANUAL_URL_REASON, $service->lastRunVersion()?->reason);
        Http::assertNotSent(static fn (Request $r): bool => str_starts_with($r->url(), self::PAGE) || $r->url() === self::IMAGE_MAPPED);
    }

    public function test_price_list_without_source_policy_ignores_the_map_and_goes_the_old_way(): void
    {
        $card = $this->card();
        $list = $this->priceList($card, null);
        $this->pin($card, $list, ['image_url' => self::IMAGE_MAPPED]);
        $search = $this->search(oldPath: true);
        $service = $this->service($search);

        $service->enrichProduct($card, false);

        $search->shouldHaveReceived('searchBothPhases')->atLeast()->once();
        $card->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $card->enrichment_status, (string) $card->enrichment_error);
        $this->assertArrayNotHasKey('source_map', (array) $card->enrichment_payload);
        $this->assertNull($card->review_reason);
        $this->assertNotSame(DescriptionVersionStore::MAPPED_SOURCE_REASON, $service->lastRunVersion()?->reason);
        $this->assertStringNotContainsString('wskazana dla tej karty przez importer cennika', (string) $this->lastUserPrompt);
        Http::assertNotSent(static fn (Request $r): bool => $r->url() === self::IMAGE_MAPPED);
    }

    public function test_b2b_supplement_of_mapped_card_reads_only_the_mapped_page(): void
    {
        $card = $this->card(['description' => 'Rękawice nitrylowe Z100, rozmiary 7–11.']);
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $this->pin($card, $list);
        $search = $this->search();
        $service = $this->service($search);

        $result = $service->supplementB2bDescription($card, $this->supplementContext());

        $this->assertSame([self::PAGE], $result['web_source_urls']);
        $this->assertSame(self::DESCRIPTION, $result['description']);
        $this->assertCount(1, Http::recorded(static fn (Request $r): bool => str_starts_with($r->url(), self::PAGE)));
        $search->shouldNotHaveReceived('catalogHitsOnHosts');
        $search->shouldNotHaveReceived('searchOnHosts');
        $search->shouldNotHaveReceived('searchBothPhases');
        $search->shouldNotHaveReceived('searchMappedRetailers');
    }

    public function test_image_retry_does_not_go_to_shop_cards_for_mapped_card(): void
    {
        $card = $this->card(['enrichment_status' => Product::ENRICHMENT_DONE, 'enrichment_payload' => ['source_urls' => [self::PAGE]]]);
        $list = $this->priceList($card, PriceList::POLICY_MAP_ONLY);
        $this->pin($card, $list);
        $search = $this->search();

        $this->assertSame([], $this->service($search)->imageFromShopCards($card));

        $search->shouldNotHaveReceived('shopCardsForImage');
        Http::assertNothingSent();
    }

    private function supplementContext(): B2bSupplementContext
    {
        $text = 'Rękawice nitrylowe Z100, rozmiary 7–11.';

        return new B2bSupplementContext(
            accountId: 1,
            linkIds: [],
            descriptionHash: sha1($text),
            sourceDescriptionHash: null,
            sourceSha1: sha1($text),
            b2bText: $text,
            b2bUrl: 'https://b2b.example.com/produkt/z100',
            hosts: ['www.example.org'],
            hostsSha1: sha1('www.example.org'),
            minChars: 1000,
            productDescription: $text,
        );
    }

    /** @param  array<string, mixed>  $extra */
    private function card(array $extra = []): Product
    {
        return Product::query()->create([
            'sku' => 'Z100', 'name' => 'Rękawice nitrylowe ZETA Z100 czarne', 'manufacturer' => 'ZETA SAFETY',
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 0,
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
            ...$extra,
        ]);
    }

    private function priceList(Product $card, ?string $policy): PriceList
    {
        $list = PriceList::query()->create([
            'manufacturer' => 'ZETA SAFETY', 'manufacturer_key' => PriceList::manufacturerKey('ZETA SAFETY'),
            'version' => '2026', 'original_filename' => 'zeta.xlsx',
            'source_policy' => $policy, 'importer_key' => $policy !== null ? 'zeta-2026' : null,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'currency' => 'PLN', 'checked_at' => now(),
        ]);

        return $list;
    }

    /** @param  array<string, mixed>  $extra */
    private function pin(Product $card, PriceList $list, array $extra = []): ProductSourcePin
    {
        return ProductSourcePin::query()->create([
            'product_id' => $card->id, 'price_list_id' => $list->id, 'importer_key' => 'zeta-2026', 'importer_version' => 2,
            'url' => self::PAGE, 'source_kind' => ProductSourcePin::KIND_MANUFACTURER, 'page_title' => 'Rękawice Z100',
            'match_kind' => ProductSourcePin::MATCH_EXACT_CODE, 'match_key' => 'Z100', 'checked_at' => now(),
            ...$extra,
        ]);
    }

    /**
     * Wyszukiwarka-szpieg. $oldPath — stara ścieżka: wynik = strona z mapy (opis z kodem karty w treści).
     */
    private function search(bool $oldPath = false): MockInterface
    {
        $search = Mockery::spy(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')->andReturn([
            'results' => $oldPath ? [['url' => self::PAGE, 'title' => 'Rękawice ZETA Z100', 'snippet' => 'Z100']] : [],
            'errors' => [],
        ]);
        $search->shouldReceive('dropListingResults')->andReturnUsing(static fn (array $results): array => $results);
        $search->shouldReceive('moreCatalogHits')->andReturn([]);
        $search->shouldReceive('searchMappedRetailers')->andReturn([]);
        $search->shouldReceive('searchWebWithoutLocalIndex')->andReturn(['results' => [], 'images' => [], 'errors' => []]);
        $search->shouldReceive('shopCardsForImage')->andReturn([['url' => 'https://www.example.net/z100', 'title' => 'Z100', 'snippet' => 'Z100']]);
        $search->shouldReceive('catalogHitsOnHosts')->andReturn([]);
        $search->shouldReceive('searchOnHosts')->andReturn([]);
        $search->shouldReceive('lastHostSearchErrors')->andReturn([]);

        return $search;
    }

    private function service(MockInterface $search): ProductEnrichmentService
    {
        $facts = 'Rękawice ochronne ZETA Z100 z powłoką nitrylową na dłoni i dzianinowym wkładem z poliamidu. Do prac montażowych '
            .'i magazynowych, pewny chwyt suchych i lekko zaolejonych przedmiotów, mankiet ściągaczowy. Kod Z100.';
        $page = static fn (string $title): string => '<html><head><title>'.$title.'</title>'
            .'<meta property="og:image" content="'.self::IMAGE_ON_PAGE.'"></head><body><h1>Rękawice ZETA Z100</h1>'
            .'<img src="'.self::IMAGE_ON_PAGE.'" alt="Rękawice ZETA Z100">'
            .'<div class="product-description"><p>'.$facts.'</p>'
            .'<p>Producent: ZETA SAFETY. Rękawice dostępne w rozmiarach 7–11, pakowane po 12 par, kolor czarny.</p>'
            .'<p>Rękawice Z100 sprawdzają się przy kompletacji zamówień, montażu drobnych elementów i pracach porządkowych. '
            .'Wkład bez szwów na palcach nie uciska dłoni, a powłoka nie przesiąka przy kontakcie z niewielką ilością oleju.</p>'
            .'<table class="spec"><tr><th>Kod</th><td>Z100</td></tr><tr><th>Materiał wkładu</th><td>poliamid</td></tr>'
            .'<tr><th>Powłoka</th><td>nitryl</td></tr><tr><th>Rozmiary</th><td>7, 8, 9, 10, 11</td></tr></table></div>'
            .'</body></html>';

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $handler = function (array $messages) use ($facts): array {
            $system = (string) ($messages[0]['content'] ?? '');
            $user = (string) ($messages[1]['content'] ?? '');
            if (str_contains($system, 'filtrem treści')) {
                return ['pages' => [['url' => str_contains($user, self::HUMAN_PAGE) ? self::HUMAN_PAGE : self::PAGE, 'text' => $facts]]];
            }
            $this->lastUserPrompt = $user;

            return [
                'features' => ['Powłoka nitrylowa na dłoni', 'Mankiet ściągaczowy'],
                // kolor innego wariantu i linia neutralna — linie cennika z mapy zastępują „Kolor”
                'specs' => ['Kolor: Biały', 'Materiał wkładu: poliamid'],
                'norms' => [], 'certificates' => [], 'materials' => ['nitryl', 'poliamid'], 'use_cases' => ['prace montażowe'],
                'image_urls' => [self::IMAGE_ON_PAGE, self::FOREIGN_IMAGE],
                'source_urls' => [self::PAGE, self::OTHER_PAGE, self::HUMAN_PAGE],
                'description' => self::DESCRIPTION, 'confidence' => 0.9,
            ];
        };
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);

        Http::fake(function (Request $request) use ($page) {
            $url = $request->url();
            if ($url === self::IMAGE_ON_PAGE) {
                return $this->pageImageStatus === 200
                    ? Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])
                    : Http::response('', $this->pageImageStatus);
            }
            if (str_ends_with(mb_strtolower($url), '.jpg')) {
                return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_contains($url, 'r.jina.ai')) {
                return Http::response("Title: x\n\nMarkdown Content:\nundefined", 200, ['Content-Type' => 'text/plain']);
            }
            if (str_starts_with($url, self::PAGE)) {
                return Http::response($page('Rękawice ZETA Z100 | ZETA SAFETY'), 200, ['Content-Type' => 'text/html']);
            }
            if (str_starts_with($url, self::HUMAN_PAGE)) {
                return Http::response($page('Rękawice ZETA Z100 | Sklep BHP'), 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('', 404);
        });

        return new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );
    }

    /** @param  array<string, mixed>  $extra */
    private function webImage(Product $product, ?string $sourceUrl, array $extra = []): ProductImage
    {
        $path = 'products/'.$product->id.'/'.uniqid('img', true).'.jpg';
        Storage::disk('public')->put($path, $this->jpeg());
        $sort = (int) (ProductImage::query()->where('product_id', $product->id)->max('sort_order') ?? -1) + 1;

        return ProductImage::query()->create([
            'product_id' => $product->id, 'path' => $path, 'source_url' => $sourceUrl, 'is_primary' => $sort === 0,
            'sort_order' => $sort, 'checksum' => hash('sha256', $path),
            ...$extra,
        ]);
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(320, 480);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 40, 40));
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
