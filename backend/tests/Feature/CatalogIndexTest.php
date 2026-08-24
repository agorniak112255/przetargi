<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogPage;
use App\Models\Product;
use App\Services\Enrichment\CatalogIndexSearch;
use App\Services\Enrichment\CatalogSitemapIndexer;
use App\Services\Enrichment\HybridWebSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CatalogIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_indexes_urls_from_sitemap_index(): void
    {
        Http::fake([
            'https://optimumbhp.pl/robots.txt' => Http::response(
                "User-agent: *\nSitemap: https://optimumbhp.pl/sitemap.xml\n",
                200
            ),
            'https://optimumbhp.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex><sitemap><loc>https://optimumbhp.pl/sitemap-products.xml</loc></sitemap></sitemapindex>',
                200
            ),
            'https://optimumbhp.pl/sitemap-products.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://optimumbhp.pl/REKAWICE-1202-URGENT-p138481</loc></url>'
                .'<url><loc>https://inny-sklep.pl/cos-obcego</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('optimumbhp.pl');

        $this->assertSame(2, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['host' => 'optimumbhp.pl']);
        // adres z innej domeny trafia do indeksu pod własnym hostem
        $this->assertDatabaseHas('catalog_pages', ['host' => 'inny-sklep.pl']);
    }

    public function test_reindex_does_not_duplicate_rows(): void
    {
        Http::fake([
            'https://optimumbhp.pl/robots.txt' => Http::response('Sitemap: https://optimumbhp.pl/sitemap.xml', 200),
            'https://optimumbhp.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset><url><loc>https://optimumbhp.pl/REKAWICE-1202-URGENT-p138481</loc></url></urlset>',
                200
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('optimumbhp.pl');
        app(CatalogSitemapIndexer::class)->index('optimumbhp.pl');

        $this->assertSame(1, CatalogPage::query()->count());
    }

    public function test_reads_gzipped_sitemap(): void
    {
        Http::fake([
            'https://demar24.pl/robots.txt' => Http::response('Sitemap: https://demar24.pl/sitemap.xml.gz', 200),
            'https://demar24.pl/sitemap.xml.gz' => Http::response(
                (string) gzencode(
                    '<?xml version="1.0"?><urlset><url><loc>https://demar24.pl/buty-demar-1202</loc></url></urlset>'
                ),
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('demar24.pl');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://demar24.pl/buty-demar-1202']);
    }

    public function test_keeps_locations_pointing_to_other_domains(): void
    {
        Http::fake([
            'https://atg-glovesolutions.com/robots.txt' => Http::response(
                'Sitemap: https://atg-glovesolutions.com/sitemap.xml',
                200
            ),
            'https://atg-glovesolutions.com/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.atggloves.com/maxiflex-42-874</loc></url>'
                .'<url><loc>https://www.facebook.com/atggloves</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('atg-glovesolutions.com');

        $this->assertSame(1, $result['saved']);
        $this->assertSame(1, $result['off_host']);
        $this->assertDatabaseHas('catalog_pages', ['host' => 'atggloves.com']);
        $this->assertSame(0, CatalogPage::query()->where('url', 'like', '%facebook%')->count());
    }

    public function test_reads_namespaced_and_cdata_locations(): void
    {
        Http::fake([
            'https://pros.pl/robots.txt' => Http::response('Sitemap: https://pros.pl/sitemap.xml', 200),
            'https://pros.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><sm:urlset xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<sm:url><sm:loc><![CDATA[https://pros.pl/produkt/kask-pros-1202]]></sm:loc></sm:url>'
                .'</sm:urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('pros.pl');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://pros.pl/produkt/kask-pros-1202']);
    }

    public function test_finds_product_page_by_code_in_url(): void
    {
        $this->seedPage('https://optimumbhp.pl/REKAWICE-ROBOCZE-1202-URGENT-p138481');
        $this->seedPage('https://optimumbhp.pl/SPODNIE-URG-C-URGENT-p138548');

        $gloves = new Product(['sku' => '1202', 'name' => 'Rękawice 1202 kozia', 'manufacturer' => 'Urgent']);
        $shorts = new Product(['sku' => 'URG-C-SPODNIE', 'name' => 'URG-C (spodnie)', 'manufacturer' => 'Urgent']);

        $this->assertSame(
            'https://optimumbhp.pl/REKAWICE-ROBOCZE-1202-URGENT-p138481',
            app(CatalogIndexSearch::class)->findFor($gloves)[0]['url'] ?? null
        );
        $this->assertSame(
            'https://optimumbhp.pl/SPODNIE-URG-C-URGENT-p138548',
            app(CatalogIndexSearch::class)->findFor($shorts)[0]['url'] ?? null
        );
    }

    public function test_search_uses_index_instead_of_web(): void
    {
        $this->seedPage('https://optimumbhp.pl/REKAWICE-ROBOCZE-1202-URGENT-p138481');
        $product = Product::query()->create([
            'sku' => '1202',
            'name' => 'Rękawice 1202 kozia czerwona',
            'manufacturer' => 'Urgent',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        Http::fake();

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame('catalog_index', $pack['provider']);
        Http::assertNothingSent();
    }

    private function seedPage(string $url): void
    {
        CatalogPage::query()->create([
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'haystack' => mb_strtolower($url),
            'last_seen_at' => now(),
        ]);
    }
}
