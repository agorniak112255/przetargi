<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use ReflectionClass;
use Tests\TestCase;

final class EnrichmentSourceRankingTest extends TestCase
{
    public function test_description_ranking_prefers_retailer_over_manufacturer(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'manufacturer' => 'Uvex',
            'sku' => '60549',
            'name' => 'uvex C300 Dry',
        ]);
        $mfrDomains = ['uvex-safety.com', 'www.uvex-safety.com'];

        $ranked = $this->invoke($service, 'rankResultsForDescription', [
            [
                ['url' => 'https://www.uvex-safety.com/en/product/c300-dry', 'title' => 'mfr', 'snippet' => ''],
                ['url' => 'https://bhp-sklep.com.pl/produkt/rekawice-uvex-c300-dry', 'title' => 'shop', 'snippet' => ''],
                ['url' => 'https://other-shop.example/item/60549', 'title' => 'other', 'snippet' => ''],
            ],
            $product,
            $mfrDomains,
        ]);

        $this->assertStringContainsString('bhp-sklep.com.pl', (string) ($ranked[0]['url'] ?? ''));
        $this->assertStringContainsString('other-shop.example', (string) ($ranked[1]['url'] ?? ''));
        $this->assertStringContainsString('uvex-safety.com', (string) ($ranked[2]['url'] ?? ''));
    }

    public function test_manufacturer_search_results_keeps_only_mfr_and_pdf(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'manufacturer' => 'Uvex',
            'sku' => '60549',
            'name' => 'uvex C300 Dry',
        ]);
        $mfrDomains = ['uvex-safety.com', 'd3nan4w00fsv2d.cloudfront.net'];

        $mfr = $this->invoke($service, 'manufacturerSearchResults', [
            [
                ['url' => 'https://bhp-sklep.com.pl/produkt/c300', 'title' => '', 'snippet' => ''],
                ['url' => 'https://www.uvex-safety.com/en/product/c300', 'title' => '', 'snippet' => ''],
                ['url' => 'https://d3nan4w00fsv2d.cloudfront.net/DATASHEET/60549_PDB_EN.pdf', 'title' => '', 'snippet' => ''],
            ],
            $product,
            $mfrDomains,
        ]);

        $urls = array_column($mfr, 'url');
        $this->assertCount(2, $urls);
        $this->assertTrue(collect($urls)->contains(fn (string $u): bool => str_contains($u, 'uvex-safety.com')));
        $this->assertTrue(collect($urls)->contains(fn (string $u): bool => str_ends_with($u, '.pdf')));
    }

    public function test_ansell_description_ranking_prefers_pl_product_over_blog_and_cn(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'manufacturer' => 'Ansell',
            'sku' => 'WH20T-00111-09',
            'name' => '2000-WH TSPLUS CVRL HOOD 111.5XL',
        ]);
        $mfrDomains = ['ansell.com', 'www.ansell.com'];

        $ranked = $this->invoke($service, 'rankResultsForDescription', [
            [
                ['url' => 'https://www.ansell.com/hk/en/blogs/critical-insights/x', 'title' => 'blog', 'snippet' => ''],
                ['url' => 'https://www.ansell.com/cn/zh-hans/products/bioclean-2000', 'title' => 'cn', 'snippet' => ''],
                ['url' => 'https://www.ansell.com/pl/pl/products/bioclean-2000-hooded-coverall-model-111', 'title' => 'pl', 'snippet' => ''],
            ],
            $product,
            $mfrDomains,
        ]);

        $this->assertStringContainsString('/pl/pl/products/', (string) ($ranked[0]['url'] ?? ''));
        $this->assertStringContainsString('/blogs/', (string) ($ranked[count($ranked) - 1]['url'] ?? ''));
    }

    public function test_primary_source_points_at_manufacturer_card_not_shop(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'manufacturer' => 'ARTRA',
            'sku' => 'ARCASIO 732 616560 S1 P ESD',
            'name' => 'ARCASIO 732 616560 S1 P ESD',
        ]);

        [$url, $kind] = $this->invoke($service, 'primarySource', [
            [
                'https://regera.pl/produkt/artra-arcasio-732',
                'https://artra.pl/products/3813781-arcasio-732-616560-s1-p-esd',
            ],
            $product,
            ['artra.pl', 'www.artra.pl'],
        ]);

        $this->assertSame('https://artra.pl/products/3813781-arcasio-732-616560-s1-p-esd', $url);
        $this->assertSame('manufacturer', $kind);
    }

    public function test_primary_source_keeps_shop_when_manufacturer_card_is_missing(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'manufacturer' => 'ARTRA',
            'sku' => 'ARCASIO 732 616560 S1 P ESD',
            'name' => 'ARCASIO 732 616560 S1 P ESD',
        ]);

        [$url, $kind] = $this->invoke($service, 'primarySource', [
            ['https://regera.pl/produkt/artra-arcasio-732', 'https://empik.com/x'],
            $product,
            ['artra.pl'],
        ]);

        $this->assertSame('https://regera.pl/produkt/artra-arcasio-732', $url);
        $this->assertSame('shop', $kind);
    }

    public function test_primary_source_is_empty_when_card_has_no_sources(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product(['manufacturer' => 'ARTRA', 'sku' => 'X', 'name' => 'X']);

        [$url, $kind] = $this->invoke($service, 'primarySource', [[], $product, []]);

        $this->assertNull($url);
        $this->assertNull($kind);
    }

    public function test_primary_source_prefers_url_pointed_by_hand(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product([
            'manufacturer' => 'ARTRA',
            'sku' => 'ARCASIO 732',
            'name' => 'ARCASIO 732',
            'shop_source_url' => 'https://sklep.example/artra-arcasio-732',
        ]);

        [$url, $kind] = $this->invoke($service, 'primarySource', [
            ['https://artra.pl/products/arcasio', 'https://sklep.example/artra-arcasio-732'],
            $product,
            ['artra.pl'],
        ]);

        $this->assertSame('https://sklep.example/artra-arcasio-732', $url);
        $this->assertSame('manual', $kind);
    }

    /**
     * @param  list<mixed>  $args
     */
    private function invoke(object $service, string $method, array $args): mixed
    {
        $ref = new ReflectionClass($service);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($service, ...$args);
    }
}
