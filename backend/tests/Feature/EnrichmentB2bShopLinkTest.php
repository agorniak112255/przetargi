<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ProductSourcesNotFoundException;
use App\Models\Product;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\B2b\P4sB2bClient;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Karta 57476 (23.09.2026): naklejka ze znakiem „Koc gaśniczy” z konta P4S. Link do karty P4S wpisała synchronizacja B2B,
 * a „Pobierz” wziął go za link wskazany przez człowieka i ominął bramki tożsamości. Strona P4S bez sesji to powłoka SPA
 * z jednym zdaniem („Ten serwis nie jest dostępny dla Twojej przeglądarki…”), filtr stron nie znalazł w niej faktów,
 * a mimo to surowy tekst trafił do modelu i ten napisał z samej nazwy opis koca z włókna szklanego „do 1000 V”.
 */
final class EnrichmentB2bShopLinkTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = '"Koc gaśniczy", płyta PVC samoprzylepna z nadrukiem fotoluminescencyjnym, 150x150';

    public function test_b2b_card_link_without_content_gives_no_description(): void
    {
        $product = $this->runEnrichment(P4sB2bClient::PRODUCT_PAGE.'95546', $this->p4sShell(), [], $prompts);

        $this->assertSame([], $prompts, 'model opisu nie może dostać zlecenia bez tekstu źródła');
        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status);
        $this->assertSame('', trim((string) $product->description));
        $this->assertNotSame('manual', $product->enrichment_payload['primary_source_kind'] ?? null);
    }

    /** Link wpisany przez człowieka na obcy host dalej omija bramki — ale werdykt filtra „brak faktów” obowiązuje. */
    public function test_person_link_to_empty_page_gives_no_description_when_filter_finds_no_facts(): void
    {
        $product = $this->runEnrichment('https://sklep.example/spa/koc', $this->p4sShell(), [], $prompts);

        $this->assertSame([], $prompts);
        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status);
        $this->assertSame('', trim((string) $product->description));
    }

    /** Filtr bywa zbyt surowy: strona, która sama nazywa wyrób, wraca surowym tekstem mimo pustego werdyktu. */
    public function test_page_naming_the_product_survives_empty_filter_verdict(): void
    {
        $html = '<html><head><title>ANRO ZPPV99C Znak Koc gaśniczy</title></head><body><h1>ANRO ZPPV99C '.self::NAME.'</h1>'
            .'<div class="product-description">Znak ewakuacyjny ANRO ZPPV99C wskazuje miejsce koca gaśniczego. '
            .'Płyta PVC samoprzylepna, nadruk fotoluminescencyjny, wymiar 150x150 mm.</div></body></html>';

        $this->runEnrichment('https://sklep.example/znak-zppv99c', $html, [
            'description' => 'Znak ANRO ZPPV99C wskazuje miejsce koca gaśniczego.',
        ], $prompts);

        $this->assertCount(1, $prompts);
        $this->assertStringContainsString('fotoluminescencyjny, wymiar 150x150 mm', $prompts[0]);
    }

    private function p4sShell(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/enrichment/b2b/p4s-spa-shell.html'));
    }

    /**
     * Przebieg „Pobierz” nad jedną stroną pod linkiem karty; wyszukiwarka nic nie znajduje, filtr stron ocenia każdą
     * stronę jako pustą („text”: „”).
     *
     * @param  array<string, mixed>  $extract
     * @param  list<string>|null  $prompts  wiadomości użytkownika wysłane do modelu opisu
     */
    private function runEnrichment(string $shopUrl, string $html, array $extract, ?array &$prompts): Product
    {
        Storage::fake('public');
        $prompts = [];
        $product = Product::query()->create([
            'sku' => 'ZPPV99C',
            'name' => self::NAME,
            'manufacturer' => 'ANRO',
            'catalog_price_net' => 8.69,
            'purchase_price' => 8.69,
            'stock' => 1,
            'shop_source_url' => $shopUrl,
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

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $handler = function (array $messages) use ($extract, &$prompts): array {
            $system = (string) ($messages[0]['content'] ?? '');
            $user = (string) ($messages[1]['content'] ?? '');
            if (str_contains($system, 'filtrem treści')) {
                preg_match_all('#"url"\s*:\s*"(https?://[^"]+)"#', $user, $m);

                return ['pages' => array_map(
                    static fn (string $url): array => ['url' => stripslashes($url), 'text' => ''],
                    array_values(array_unique($m[1] ?? []))
                )];
            }
            $prompts[] = $user;

            return [
                'features' => [], 'specs' => [], 'norms' => [], 'certificates' => [], 'materials' => [],
                'use_cases' => [], 'image_urls' => [], 'source_urls' => [], 'description' => '', ...$extract,
            ];
        };
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);

        Http::fake(static fn (): PromiseInterface => Http::response($html, 200, ['Content-Type' => 'text/html']));

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

        try {
            $service->enrichProduct($product, false);
        } catch (ProductSourcesNotFoundException) {
            // karta bez opisu kończy przebieg wyjątkiem — status „manual” ustawia enrichProduct
        }

        return $product->refresh();
    }
}
