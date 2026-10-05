<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductImageRetry;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Przebieg opisu, w którym strona wyrobu przychodzi, a plik zdjęcia zasłania zapora (karta 8564, ansell.com):
 * opis zapisany, zdjęcia brak, adresy zdjęcia czekają na products:retry-images — tylko na karcie, nie w pamięci SKU.
 */
final class EnrichmentImageRetryTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://sklep.example/rekawice-ringers-r074';

    private const IMAGE = 'https://sklep.example/media/catalog/product/ringers-r074.jpg';

    private const SHOP = 'https://hurtownia.example/rekawice-ansell-ringers-r074';

    private const SHOP_IMAGE = 'https://hurtownia.example/img/ringers-r074-packshot.jpg';

    /** @var \Closure(): list<array<string, string>> */
    private \Closure $shopCards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shopCards = static fn (): array => [];
    }

    public function test_blocked_image_is_kept_for_retry_on_the_card_only(): void
    {
        $product = $this->runEnrichment(Http::response(
            '<html><script src="/_Incapsula_Resource?SWJIYLWA=1"></script></html>', 200, ['Content-Type' => 'text/html']
        ));

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertSame(0, $product->images()->count());
        // sklepy przebieg już sprawdził (atrapa nie dała żadnego) — ponawianie pyta tylko producenta
        $this->assertSame(
            ['urls' => [self::IMAGE], 'attempts' => 0, 'shops_tried' => true],
            $product->enrichment_payload[ProductImageRetry::PAYLOAD_KEY] ?? null
        );
        $this->assertStringEndsWith(ProductImageRetry::ERROR_NOTE, (string) $product->enrichment_error);

        $cache = ProductEnrichmentCache::query()->sole();
        $this->assertArrayNotHasKey(ProductImageRetry::PAYLOAD_KEY, $cache->enrichment_payload);
    }

    /**
     * Zapora ansell.com puszcza dziś kilka plików na sto (05.10.2026, karta 8772 HyFlex 11-840): wyszukiwanie kończy na
     * karcie producenta, więc sklepy trzeba dopytać osobno. Zdjęcie z potwierdzonej karty sklepu zamiast ponawiania.
     */
    public function test_blocked_image_falls_back_to_shop_card_found_without_manufacturer_site(): void
    {
        $asked = [];
        $this->shopCards = static function (Product $product) use (&$asked): array {
            $asked[] = $product->sku;

            return [['url' => self::SHOP, 'title' => 'Rękawice Ansell RINGERS R074', 'snippet' => '']];
        };

        $product = $this->runEnrichment(Http::response(
            '<html><script src="/_Incapsula_Resource?SWJIYLWA=1"></script></html>', 200, ['Content-Type' => 'text/html']
        ));

        $this->assertSame(['R074'], $asked);
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $image = $product->images()->sole();
        $this->assertSame(self::SHOP_IMAGE, $image->source_url);
        $this->assertArrayNotHasKey(ProductImageRetry::PAYLOAD_KEY, $product->enrichment_payload);
        $this->assertNull($product->enrichment_error);
        $steps = array_column((array) ($product->enrichment_trace['steps'] ?? []), 'm');
        $this->assertContains('zdjęcie z karty sklepu (sklepy bez strony producenta) — plik producenta zablokowany', $steps);
    }

    /** Brak pliku (404) to nie zapora — sklepów po zdjęcie nie dopytujemy. */
    public function test_missing_image_does_not_ask_for_shop_cards(): void
    {
        $asked = 0;
        $this->shopCards = static function () use (&$asked): array {
            $asked++;

            return [['url' => self::SHOP, 'title' => 'Rękawice Ansell RINGERS R074', 'snippet' => '']];
        };

        $product = $this->runEnrichment(Http::response('Not found', 404, ['Content-Type' => 'text/html']));

        $this->assertSame(0, $asked);
        $this->assertSame(0, $product->images()->count());
    }

    public function test_missing_image_is_not_retried(): void
    {
        $product = $this->runEnrichment(Http::response('Not found', 404, ['Content-Type' => 'text/html']));

        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status, (string) $product->enrichment_error);
        $this->assertArrayNotHasKey(ProductImageRetry::PAYLOAD_KEY, $product->enrichment_payload);
        $this->assertStringStartsWith('Opis OK, nie udało się pobrać zdjęcia', (string) $product->enrichment_error);
        $this->assertStringNotContainsString('Ponowimy', (string) $product->enrichment_error);
    }

    private function runEnrichment(mixed $imageResponse): Product
    {
        Storage::fake('public');
        $product = Product::query()->create([
            'sku' => 'R074',
            'name' => 'Rękawice RINGERS R074 powlekane PVC',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 20,
            'purchase_price' => 20,
            'stock' => 1,
            'shop_source_url' => self::PAGE,
            'description' => null,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')->zeroOrMoreTimes()->andReturn(['results' => [], 'errors' => []]);
        $search->shouldReceive('dropListingResults')->zeroOrMoreTimes()
            ->andReturnUsing(static fn (array $results): array => $results);
        $search->shouldReceive('moreCatalogHits')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchMappedRetailers')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchWebWithoutLocalIndex')->zeroOrMoreTimes()
            ->andReturn(['results' => [], 'images' => [], 'errors' => []]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();
        $search->shouldReceive('shopCardsForImage')->zeroOrMoreTimes()->andReturnUsing($this->shopCards);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $facts = 'Rękawice RINGERS R074 są powlekane PVC, wodoodporne i odporne na wiele chemikaliów. '
            .'Wnętrze z bawełnianą wyściółką poprawia komfort przy dłuższej pracy, a szorstkie wykończenie '
            .'dłoni zapewnia pewny chwyt mokrych i zaolejonych przedmiotów. Rękawice przeznaczone są do prac '
            .'w przemyśle chemicznym, przy przeładunku beczek oraz w laboratoriach.';
        $handler = static function (array $messages) use ($facts): array {
            $system = (string) ($messages[0]['content'] ?? '');
            if (str_contains($system, 'filtrem treści')) {
                return ['pages' => [['url' => self::PAGE, 'text' => $facts]]];
            }

            return [
                'features' => [], 'specs' => [], 'norms' => [], 'certificates' => [], 'materials' => [],
                'use_cases' => [], 'image_urls' => [], 'source_urls' => [], 'description' => $facts, 'confidence' => 0.9,
            ];
        };
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);

        $html = '<html><head><title>RINGERS R074 Rękawice</title>'
            .'<meta property="og:image" content="'.self::IMAGE.'"></head><body><h1>Rękawice RINGERS R074</h1>'
            .'<img src="'.self::IMAGE.'" alt="RINGERS R074">'
            .'<div class="product-description">Rękawice RINGERS R074 powlekane PVC, wodoodporne, odporne chemicznie. '
            .'Krótkie rękawice przemysłowe do przenoszenia gorących przedmiotów.</div>'
            // strona krótsza niż 800 znaków to dla ProductPageFetcher zapora, nie karta
            .'<div class="product-details"><p>Powłoka z PVC chroni dłonie przed wodą, olejami i wieloma chemikaliami. '
            .'Wnętrze z bawełnianą wyściółką poprawia komfort przy dłuższej pracy. Szorstkie wykończenie dłoni '
            .'zapewnia pewny chwyt mokrych i zaolejonych przedmiotów. Rękawice przeznaczone do prac w przemyśle '
            .'chemicznym, przy przeładunku beczek oraz w laboratoriach.</p></div></body></html>';
        $shopHtml = str_replace(self::IMAGE, self::SHOP_IMAGE, $html);
        Http::fake(function (Request $request) use ($html, $shopHtml, $imageResponse) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'r.jina.ai') => Http::response("Title: x\n\nMarkdown Content:\nundefined", 200, ['Content-Type' => 'text/plain']),
                str_starts_with($url, self::IMAGE) => $imageResponse,
                str_starts_with($url, self::SHOP_IMAGE) => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
                str_starts_with($url, self::SHOP) => Http::response($shopHtml, 200, ['Content-Type' => 'text/html']),
                default => Http::response($html, 200, ['Content-Type' => 'text/html']),
            };
        });

        $service = new ProductEnrichmentService(
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
        $service->enrichProduct($product, false);

        return $product->refresh();
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(320, 480);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
