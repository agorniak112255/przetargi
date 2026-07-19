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
