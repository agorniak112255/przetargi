<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogHost;
use App\Models\CatalogPage;
use App\Models\CatalogSkipOverride;
use App\Models\Product;
use App\Services\Enrichment\CatalogIndexSearch;
use App\Services\Enrichment\CatalogSitemapIndexer;
use App\Services\Enrichment\HybridWebSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CatalogIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $urls
     */
    private function fakeHttp(array $urls = []): void
    {
        Http::fake($urls + [
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);
    }

    public function test_indexes_urls_from_sitemap_index(): void
    {
        $this->fakeHttp([
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
                .'<url><loc>https://inny-sklep.pl/cos-obcego-123</loc></url>'
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

    public function test_stops_at_max_urls(): void
    {
        $locs = '';
        for ($i = 1; $i <= 5; $i++) {
            $locs .= '<url><loc>https://optimumbhp.pl/karta-'.$i.'</loc></url>';
        }
        $this->fakeHttp([
            'https://optimumbhp.pl/robots.txt' => Http::response('Sitemap: https://optimumbhp.pl/sitemap.xml', 200),
            'https://optimumbhp.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'.$locs.'</urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('optimumbhp.pl', 3);

        $this->assertSame(3, $result['urls']);
        $this->assertSame(3, CatalogPage::query()->count());
    }

    public function test_reindex_does_not_duplicate_rows(): void
    {
        $this->fakeHttp([
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

    public function test_missing_only_skips_already_indexed_host(): void
    {
        Http::fake();
        $this->seedPage('https://optimumbhp.pl/buty-ardon');

        $this->artisan('catalog:index', [
            'host' => 'optimumbhp.pl',
            '--missing-only' => true,
        ])->expectsOutputToContain('już w indeksie')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_missing_only_indexes_unknown_host(): void
    {
        $this->fakeHttp([
            'https://ardon.pl/robots.txt' => Http::response('Sitemap: https://ardon.pl/sitemap.xml', 200),
            'https://ardon.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset><url><loc>https://ardon.pl/buty-robocze-m80</loc></url></urlset>',
                200
            ),
        ]);

        $this->artisan('catalog:index', [
            'host' => 'ardon.pl',
            '--missing-only' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('catalog_pages', ['host' => 'ardon.pl']);
    }

    public function test_follows_shoper_google_sitemap_children(): void
    {
        $this->fakeHttp([
            'https://tmbhp.pl/robots.txt' => Http::response(
                "Sitemap: https://tmbhp.pl/console/integration/execute/name/GoogleSitemap\n",
                200
            ),
            'https://tmbhp.pl/console/integration/execute/name/GoogleSitemap' => Http::response(
                '<?xml version="1.0"?><sitemapindex>'
                .'<sitemap><loc>https://tmbhp.pl/console/integration/execute/name/GoogleSitemap/list/products/locale/pl_PL/page/1</loc></sitemap>'
                .'</sitemapindex>',
                200,
                ['Content-Type' => 'application/force-download']
            ),
            'https://tmbhp.pl/console/integration/execute/name/GoogleSitemap/list/products/locale/pl_PL/page/1' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://tmbhp.pl/pl/p/Rekawice-MSA/123</loc></url>'
                .'</urlset>',
                200,
                ['Content-Type' => 'application/force-download']
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('tmbhp.pl');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://tmbhp.pl/pl/p/Rekawice-MSA/123']);
    }

    public function test_waf_403_html_is_not_counted_as_sitemap(): void
    {
        $this->fakeHttp([
            'https://bhp.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            '*' => Http::response(
                '<!DOCTYPE html><html><head><title>Just a moment</title></head><body>Cloudflare</body></html>',
                403,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('bhp.pl');

        $this->assertSame(0, $result['urls']);
        $this->assertSame([], $result['sitemaps']);
    }

    public function test_uses_magento_media_sitemap_when_robots_has_none(): void
    {
        $this->fakeHttp([
            'https://bpbhp.pl/robots.txt' => Http::response("User-agent: *\nDisallow: /search\n", 200),
            'https://bpbhp.pl/media/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121</loc></url>'
                .'</urlset>',
                200
            ),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('bpbhp.pl');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121',
        ]);
    }

    public function test_falls_back_to_media_sitemap_when_robots_map_is_empty(): void
    {
        $this->fakeHttp([
            'https://bpbhp.pl/robots.txt' => Http::response(
                "Sitemap: https://bpbhp.pl/sitemap.xml\n",
                200
            ),
            'https://bpbhp.pl/sitemap.xml' => Http::response(
                '<!DOCTYPE html><html><head><title>Brak</title></head></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://bpbhp.pl/media/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-111</loc></url>'
                .'</urlset>',
                200
            ),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('bpbhp.pl');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-111',
        ]);
    }

    public function test_follows_xml_child_without_sitemap_in_name(): void
    {
        $this->fakeHttp([
            'https://ox-on.com/robots.txt' => Http::response('Sitemap: https://ox-on.com/sitemap.xml', 200),
            'https://ox-on.com/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex>'
                .'<sitemap><loc>https://ox-on.com/feeds/products.xml</loc></sitemap>'
                .'</sitemapindex>',
                200
            ),
            'https://ox-on.com/feeds/products.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://ox-on.com/gloves/nitril-4500</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('ox-on.com');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://ox-on.com/gloves/nitril-4500']);
        $this->assertSame(0, CatalogPage::query()->where('url', 'like', '%feeds/products.xml%')->count());
    }

    public function test_uses_www_robots_when_apex_has_no_sitemap(): void
    {
        $this->fakeHttp([
            'https://brand.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://www.brand.pl/robots.txt' => Http::response(
                "Sitemap: https://www.brand.pl/sitemap.xml\n",
                200
            ),
            'https://www.brand.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.brand.pl/rekawice-ox-on-4500</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('brand.pl');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://www.brand.pl/rekawice-ox-on-4500']);
    }

    public function test_missing_only_retry_empty_reindexes_zero_hosts(): void
    {
        $this->fakeHttp([
            'https://gvarant.pl/robots.txt' => Http::response('Sitemap: https://gvarant.pl/sitemap.xml', 200),
            'https://gvarant.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://gvarant.pl/p100,kask-pros.html</loc></url>'
                .'</urlset>',
                200
            ),
        ]);
        CatalogHost::query()->create([
            'host' => 'gvarant.pl',
            'pages_count' => 0,
            'off_host_count' => 0,
            'last_attempt_at' => now()->subDay(),
        ]);

        $this->artisan('catalog:index', [
            'host' => 'gvarant.pl',
            '--missing-only' => true,
            '--retry-empty' => true,
        ])->expectsOutputToContain('ponawiam')->assertSuccessful();

        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://gvarant.pl/p100,kask-pros.html']);
    }

    public function test_missing_only_skips_host_already_checked_without_pages(): void
    {
        Http::fake();
        CatalogHost::query()->create([
            'host' => 'gvarant.pl',
            'pages_count' => 0,
            'off_host_count' => 0,
            'last_attempt_at' => now(),
        ]);

        $this->artisan('catalog:index', [
            'host' => 'gvarant.pl',
            '--missing-only' => true,
        ])->expectsOutputToContain('już sprawdzane')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_missing_only_skips_host_whose_urls_were_on_another_domain(): void
    {
        Http::fake();
        CatalogHost::query()->create([
            'host' => 'sklep.prohaccp.pl',
            'pages_count' => 4548,
            'off_host_count' => 4548,
            'last_attempt_at' => now(),
        ]);

        $this->artisan('catalog:index', [
            'host' => 'sklep.prohaccp.pl',
            '--missing-only' => true,
            '--retry-empty' => true,
        ])->expectsOutputToContain('już sprawdzane')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_soft_404_page_is_not_counted_as_sitemap(): void
    {
        $this->fakeHttp([
            'https://gvarant.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            '*' => Http::response(
                '<!DOCTYPE html><html><head><title>Sklep</title></head><body>Nie znaleziono</body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('gvarant.pl');

        $this->assertSame(0, $result['urls']);
        $this->assertSame([], $result['sitemaps']);
    }

    public function test_indexes_empik_style_comma_product_id(): void
    {
        $card = 'https://www.empik.com/osuszacz-powietrza,p1702782328,agd-p';
        $this->fakeHttp([
            'https://empik.com/robots.txt' => Http::response(
                "Sitemap: https://www.empik.com/sitemap/agd-4.xml.gz\n",
                200
            ),
            'https://www.empik.com/sitemap/agd-4.xml.gz' => Http::response(
                (string) gzencode(
                    '<?xml version="1.0"?><urlset xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
                    .'<url><loc>'.$card.'</loc>'
                    .'<image:image><image:loc>https://ecsmedia.pl/c/foto.jpg</image:loc></image:image>'
                    .'</url></urlset>'
                ),
                200,
                ['Content-Type' => 'application/x-gzip']
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('empik.com');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => $card]);
        $this->assertDatabaseMissing('catalog_pages', ['url' => 'https://ecsmedia.pl/c/foto.jpg']);
    }

    public function test_indexes_house_garden_sitemaps_not_food_or_ebooks(): void
    {
        $garden = 'https://www.empik.com/rekawice-ogrodowe,p1701000001,dom-i-ogrod-p';
        $boots = 'https://www.empik.com/buty-robocze-s3,p1701000005,dom-i-ogrod-p';
        $pot = 'https://www.empik.com/doniczka-ceramiczna,p1701000006,dom-i-ogrod-p';
        $apron = 'https://www.empik.com/fartuch-kuchenny,p1701000007,dom-i-ogrod-p';
        $food = 'https://www.empik.com/kawa,p1701000002,delikatesy-p';
        $ebook = 'https://www.empik.com/ebook,p1701000003,ebooki-i-mp3-p';
        $agd = 'https://www.empik.com/osuszacz,p1701000004,agd-p';
        $this->seedPage($food);
        $this->seedPage($pot);
        $this->fakeHttp([
            'https://empik.com/robots.txt' => Http::response(
                "Sitemap: https://www.empik.com/sitemap.xml\n",
                200
            ),
            'https://www.empik.com/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex>'
                .'<sitemap><loc>https://www.empik.com/sitemap/agd-1.xml.gz</loc></sitemap>'
                .'<sitemap><loc>https://www.empik.com/sitemap/delikatesy-1.xml.gz</loc></sitemap>'
                .'<sitemap><loc>https://www.empik.com/sitemap/ebooki-i-mp3-1.xml.gz</loc></sitemap>'
                .'<sitemap><loc>https://www.empik.com/sitemap/dom-i-ogrod-1.xml.gz</loc></sitemap>'
                .'</sitemapindex>',
                200
            ),
            'https://www.empik.com/sitemap/agd-1.xml.gz' => Http::response(
                (string) gzencode(
                    '<?xml version="1.0"?><urlset><url><loc>'.$agd.'</loc></url></urlset>'
                ),
                200,
                ['Content-Type' => 'application/x-gzip']
            ),
            'https://www.empik.com/sitemap/delikatesy-1.xml.gz' => Http::response(
                (string) gzencode(
                    '<?xml version="1.0"?><urlset><url><loc>'.$food.'</loc></url></urlset>'
                ),
                200,
                ['Content-Type' => 'application/x-gzip']
            ),
            'https://www.empik.com/sitemap/ebooki-i-mp3-1.xml.gz' => Http::response(
                (string) gzencode(
                    '<?xml version="1.0"?><urlset><url><loc>'.$ebook.'</loc></url></urlset>'
                ),
                200,
                ['Content-Type' => 'application/x-gzip']
            ),
            'https://www.empik.com/sitemap/dom-i-ogrod-1.xml.gz' => Http::response(
                (string) gzencode(
                    '<?xml version="1.0"?><urlset>'
                    .'<url><loc>'.$garden.'</loc></url>'
                    .'<url><loc>'.$boots.'</loc></url>'
                    .'<url><loc>'.$pot.'</loc></url>'
                    .'<url><loc>'.$apron.'</loc></url>'
                    .'</urlset>'
                ),
                200,
                ['Content-Type' => 'application/x-gzip']
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('empik.com');

        $this->assertContains('https://www.empik.com/sitemap/dom-i-ogrod-1.xml.gz', $result['sitemaps']);
        $this->assertNotContains('https://www.empik.com/sitemap/delikatesy-1.xml.gz', $result['sitemaps']);
        $this->assertNotContains('https://www.empik.com/sitemap/ebooki-i-mp3-1.xml.gz', $result['sitemaps']);
        $this->assertNotContains('https://www.empik.com/sitemap/agd-1.xml.gz', $result['sitemaps']);
        $this->assertDatabaseHas('catalog_pages', ['url' => $garden]);
        $this->assertDatabaseHas('catalog_pages', ['url' => $boots]);
        $this->assertDatabaseMissing('catalog_pages', ['url' => $pot]);
        $this->assertDatabaseMissing('catalog_pages', ['url' => $apron]);
        $this->assertDatabaseMissing('catalog_pages', ['url' => $food]);
        $this->assertDatabaseMissing('catalog_pages', ['url' => $ebook]);
        $this->assertDatabaseMissing('catalog_pages', ['url' => $agd]);
    }

    public function test_reads_gzipped_sitemap(): void
    {
        $this->fakeHttp([
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
        $this->fakeHttp([
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
        $this->fakeHttp([
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

    public function test_skips_image_loc_entries_inside_sitemap(): void
    {
        $this->fakeHttp([
            'https://ardon.cz/robots.txt' => Http::response('Sitemap: https://ardon.cz/sitemap.xml', 200),
            'https://ardon.cz/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
                .'<url><loc>https://ardon.cz/rukavice-a5111</loc>'
                .'<image:image><image:loc>https://ardon.cz/multimedia/products/A5111_001.jpg</image:loc></image:image>'
                .'</url></urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('ardon.cz');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://ardon.cz/rukavice-a5111']);
        $this->assertDatabaseMissing('catalog_pages', ['url' => 'https://ardon.cz/multimedia/products/A5111_001.jpg']);
    }

    public function test_skips_image_files_listed_as_regular_loc(): void
    {
        $this->fakeHttp([
            'https://bhp-gabi.pl/robots.txt' => Http::response('Sitemap: https://bhp-gabi.pl/sitemap.xml', 200),
            'https://bhp-gabi.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.bhp-gabi.pl/bluza-ochronna-mmb-reis</loc></url>'
                .'<url><loc>https://www.bhp-gabi.pl/galerie/61/7/bluza-ochronna-mmb-reis-18576_61788.jpg</loc></url>'
                .'<url><loc>https://www.bhp-gabi.pl/galerie/61/7/bluza-ochronna-mmb-reis-18576_61788.webp</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('bhp-gabi.pl');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://www.bhp-gabi.pl/bluza-ochronna-mmb-reis']);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.bhp-gabi.pl/galerie/61/7/bluza-ochronna-mmb-reis-18576_61788.jpg',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.bhp-gabi.pl/galerie/61/7/bluza-ochronna-mmb-reis-18576_61788.webp',
        ]);
    }

    public function test_reindex_removes_previously_saved_image_urls(): void
    {
        $image = 'https://www.bhp-gabi.pl/galerie/61/7/stary.jpg';
        CatalogPage::query()->create([
            'host' => 'www.bhp-gabi.pl',
            'url_hash' => CatalogPage::hashFor($image),
            'url' => $image,
            'title' => null,
            'haystack' => $image,
            'last_seen_at' => now(),
        ]);
        $this->fakeHttp([
            'https://bhp-gabi.pl/robots.txt' => Http::response('Sitemap: https://bhp-gabi.pl/sitemap.xml', 200),
            'https://bhp-gabi.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.bhp-gabi.pl/bluza-ochronna-mmb-reis</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('bhp-gabi.pl');

        $this->assertDatabaseMissing('catalog_pages', ['url' => $image]);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://www.bhp-gabi.pl/bluza-ochronna-mmb-reis']);
    }

    public function test_ignores_llms_txt_listed_as_sitemap(): void
    {
        $this->fakeHttp([
            'https://dodatkimasarskiezwm.pl/robots.txt' => Http::response(
                "Sitemap: /llms.txt\nSitemap: https://dodatkimasarskiezwm.pl/sitemaps/sitemap_ab.xml\n",
                200
            ),
            'https://dodatkimasarskiezwm.pl/sitemaps/sitemap_ab.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex>'
                .'<sitemap><loc>https://dodatkimasarskiezwm.pl/sitemaps/sitemap_ab_1.xml</loc></sitemap>'
                .'</sitemapindex>',
                200
            ),
            'https://dodatkimasarskiezwm.pl/sitemaps/sitemap_ab_1.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://dodatkimasarskiezwm.pl/111237-kalosz-damskimeski-pl</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('dodatkimasarskiezwm.pl');

        $this->assertContains(
            'https://dodatkimasarskiezwm.pl/sitemaps/sitemap_ab_1.xml',
            $result['sitemaps']
        );
        $this->assertNotContains('https://dodatkimasarskiezwm.pl/llms.txt', $result['sitemaps']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://dodatkimasarskiezwm.pl/111237-kalosz-damskimeski-pl',
        ]);
    }

    public function test_resolves_relative_sitemap_path_from_robots(): void
    {
        $this->fakeHttp([
            'https://boxmetmedical.pl/robots.txt' => Http::response("User-Agent: *\nSitemap: /data/sitemap/sitemap.xml\n", 200),
            'https://boxmetmedical.pl/data/sitemap/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset><url><loc>https://boxmetmedical.pl/produkt/rekawiczki-nitrylowe</loc></url></urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('boxmetmedical.pl');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://boxmetmedical.pl/produkt/rekawiczki-nitrylowe']);
    }

    public function test_full_sweep_skips_config_skip_host_unless_overridden(): void
    {
        config([
            'enrichment.retailer_domains' => ['allowed-shop.pl', 'blocked-shop.pl'],
            'enrichment.manufacturer_domains' => [],
            'enrichment.catalog_skip_hosts' => ['blocked-shop.pl'],
        ]);
        $this->fakeHttp([
            'https://allowed-shop.pl/robots.txt' => Http::response('Sitemap: https://allowed-shop.pl/sitemap.xml', 200),
            'https://allowed-shop.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset><url><loc>https://allowed-shop.pl/produkt-1</loc></url></urlset>',
                200
            ),
            'https://blocked-shop.pl/robots.txt' => Http::response('Sitemap: https://blocked-shop.pl/sitemap.xml', 200),
            'https://blocked-shop.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset><url><loc>https://blocked-shop.pl/produkt-1</loc></url></urlset>',
                200
            ),
        ]);

        $this->artisan('catalog:index')->assertSuccessful();

        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://allowed-shop.pl/produkt-1']);
        $this->assertDatabaseMissing('catalog_pages', ['url' => 'https://blocked-shop.pl/produkt-1']);
        $this->assertFalse(CatalogHost::query()->where('host', 'blocked-shop.pl')->exists());

        CatalogSkipOverride::remember('blocked-shop.pl');
        $this->artisan('catalog:index')->assertSuccessful();

        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://blocked-shop.pl/produkt-1']);
    }

    public function test_stores_manufacturer_from_official_host(): void
    {
        $this->fakeHttp([
            'https://ansell.com/robots.txt' => Http::response("Sitemap: https://ansell.com/sitemap.xml\n", 200),
            'https://ansell.com/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://ansell.com/pl/pl/products/alphatec-4000</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('ansell.com');

        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://ansell.com/pl/pl/products/alphatec-4000',
            'manufacturer' => 'ansell',
        ]);
    }

    public function test_stores_manufacturer_from_shop_slug(): void
    {
        $this->fakeHttp([
            'https://optimumbhp.pl/robots.txt' => Http::response("Sitemap: https://optimumbhp.pl/sitemap.xml\n", 200),
            'https://optimumbhp.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://optimumbhp.pl/KURTKA-SOFTSHELL-ARDON-p1</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('optimumbhp.pl');

        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://optimumbhp.pl/KURTKA-SOFTSHELL-ARDON-p1',
            'manufacturer' => 'ardon',
        ]);
    }

    public function test_leaves_manufacturer_empty_on_shared_brand_host(): void
    {
        $this->fakeHttp([
            'https://automation.honeywell.com/robots.txt' => Http::response(
                "Sitemap: https://automation.honeywell.com/sitemap.xml\n",
                200
            ),
            'https://automation.honeywell.com/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://automation.honeywell.com/shop/p/123</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('automation.honeywell.com');

        $page = CatalogPage::query()
            ->where('url', 'https://automation.honeywell.com/shop/p/123')
            ->first();
        $this->assertNotNull($page);
        $this->assertNull($page->manufacturer);
    }

    public function test_does_not_match_sku_on_page_tagged_with_other_manufacturer(): void
    {
        $this->seedPage('https://icd.pl/rekawice-tegera-884a-ejendals', 'ejendals');

        $product = new Product([
            'sku' => '884A',
            'name' => 'Półbut Jalas 884A',
            'manufacturer' => 'Jalas',
        ]);

        $this->assertSame([], app(CatalogIndexSearch::class)->findFor($product));
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

    public function test_finds_codeless_product_by_brand_and_name(): void
    {
        $this->seedPage('https://bhp-sklep.com.pl/wkladki-alutermiczne-urgent-do-butow');
        $this->seedPage('https://bhp-sklep.com.pl/kurtka-zimowa-portwest');

        $product = new Product([
            'sku' => 'WKLADKI-ALUTERMICZNE',
            'name' => 'Wkładki alutermiczne do butów',
            'manufacturer' => 'Urgent',
        ]);

        $hits = app(CatalogIndexSearch::class)->findFor($product);

        $this->assertSame('https://bhp-sklep.com.pl/wkladki-alutermiczne-urgent-do-butow', $hits[0]['url'] ?? null);
        $this->assertCount(1, $hits);
    }

    public function test_finds_ansell_coverall_on_bpbhp_by_style_in_slug(): void
    {
        $this->seedPage('https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-111');
        $this->seedPage('https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121');

        $product = new Product([
            'sku' => 'GR40T-00121-09',
            'name' => '4000-GR CVRL HOOD 121-G02.5XL',
            'manufacturer' => 'Ansell',
        ]);

        $hits = app(CatalogIndexSearch::class)->findFor($product);
        $urls = array_column($hits, 'url');

        $this->assertContains('https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121', $urls);
    }

    public function test_finds_page_where_shop_shortened_the_code(): void
    {
        $this->seedPage('https://www.bezpieczni112.pl/maska-mt-212-p-8.html');
        $this->seedPage('https://www.bezpieczni112.pl/maska-mt-213-2-p-9.html');

        $product = new Product([
            'sku' => 'MT-212-2',
            'name' => 'Maska MT 212/2',
            'manufacturer' => 'MASKPOL',
        ]);

        $hits = app(CatalogIndexSearch::class)->findFor($product);

        $this->assertSame('https://www.bezpieczni112.pl/maska-mt-212-p-8.html', $hits[0]['url'] ?? null);
        $this->assertCount(1, $hits);
    }

    public function test_shortened_code_page_from_index_is_used_instead_of_web(): void
    {
        $this->seedPage('https://www.bezpieczni112.pl/maska-mt-212-p-8.html');
        $product = Product::query()->create([
            'sku' => 'MT-212-2',
            'name' => 'Maska MT 212/2',
            'manufacturer' => 'MASKPOL',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        Http::fake();

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame('catalog_index', $pack['provider']);
        $this->assertSame(
            'https://www.bezpieczni112.pl/maska-mt-212-p-8.html',
            $pack['results'][0]['url'] ?? null
        );
        Http::assertNothingSent();
    }

    public function test_short_sku_does_not_match_other_brand_same_number(): void
    {
        $this->seedPage('https://bogarobhp.pl/kombinezon-wodoochronny-model-104-aj-group-pros');
        $this->seedPage('https://icd.pl/rekawice-tegera-104-ejendals');

        $product = new Product([
            'sku' => '104',
            'name' => 'Rękawica tekstylna TEGERA 104',
            'manufacturer' => 'Ejendals',
        ]);

        $hits = app(CatalogIndexSearch::class)->findFor($product);

        $this->assertSame('https://icd.pl/rekawice-tegera-104-ejendals', $hits[0]['url'] ?? null);
        $this->assertCount(1, $hits);
    }

    public function test_short_numeric_sku_finds_typed_model_among_many_same_numbers(): void
    {
        $this->seedPage('https://icd.pl/znak-bc300-gasnica.html');
        $this->seedPage('https://icd.pl/kurtka-wodoodporna-pros-300-czerwony.html', 'pros');
        $this->seedPage('https://pros.pl/pl/odziez-wodoochronna-standard/62-kurtka-z-zamkiem-model-103.html', 'aj-group');
        $this->seedPage('https://pros.pl/pl/odziez-wodoochronna-standard/63-kurtka-z-zamkiem-model-300.html', 'aj-group');
        $this->seedPage('https://inny-sklep.pl/kurtka-wodoochronna-model-300.html', 'aj-group');

        $product = new Product([
            'sku' => '300',
            'name' => 'Kurtka wodoochronna zapinana na zamek + stójka + rynienka',
            'manufacturer' => 'AJ GROUP',
        ]);

        $hits = app(CatalogIndexSearch::class)->findFor($product);
        $urls = array_column($hits, 'url');
        $official = 'https://pros.pl/pl/odziez-wodoochronna-standard/63-kurtka-z-zamkiem-model-300.html';

        $this->assertSame($official, $hits[0]['url'] ?? null);
        $this->assertContains('https://inny-sklep.pl/kurtka-wodoochronna-model-300.html', $urls);
        $this->assertNotContains('https://icd.pl/znak-bc300-gasnica.html', $urls);
        $this->assertNotContains(
            'https://pros.pl/pl/odziez-wodoochronna-standard/62-kurtka-z-zamkiem-model-103.html',
            $urls
        );
    }

    public function test_sku_with_catalog_tail_finds_numeric_model_page(): void
    {
        $this->seedPage('https://icd.pl/znak-bc001-gasnica.html');
        $this->seedPage(
            'https://pros.pl/pl/odziez-wodoochronna-standard/70-spodnie-ogrodniczki-model-001.html',
            'aj-group'
        );

        $product = new Product([
            'sku' => '001/A/ELR',
            'name' => 'Spodnie ogrodniczki z elementami odblaskowymi',
            'manufacturer' => 'AJ GROUP',
        ]);

        $hits = app(CatalogIndexSearch::class)->findFor($product);
        $urls = array_column($hits, 'url');

        $this->assertContains(
            'https://pros.pl/pl/odziez-wodoochronna-standard/70-spodnie-ogrodniczki-model-001.html',
            $urls
        );
        $this->assertNotContains('https://icd.pl/znak-bc001-gasnica.html', $urls);
    }

    public function test_slash_numeric_set_finds_concatenated_model_page(): void
    {
        $this->seedPage('https://icd.pl/ubranie-robocze-pasa.html', 'aj-group');
        $this->seedPage(
            'https://pros.pl/pl/odziez-wodoochronna-standard/244-ubranie-model-101112.html',
            'aj-group'
        );

        $product = new Product([
            'sku' => '101/112',
            'name' => 'Ubranie wodoochronne [kurtka 3/4 i spodnie do pasa]',
            'manufacturer' => 'AJ GROUP',
        ]);

        $hits = app(CatalogIndexSearch::class)->findFor($product);
        $urls = array_column($hits, 'url');

        $this->assertContains(
            'https://pros.pl/pl/odziez-wodoochronna-standard/244-ubranie-model-101112.html',
            $urls
        );
        $this->assertNotContains('https://icd.pl/ubranie-robocze-pasa.html', $urls);
    }

    public function test_slash_letter_variant_finds_model_on_any_mapped_host(): void
    {
        $this->seedPage('https://icd.pl/znak-bc109-gasnica.html');
        $this->seedPage('https://inny-sklep.pl/fartuch-wodoochronny-model-109.html', 'aj-group');
        $this->seedPage('https://pros.pl/pl/fartuchy-wodoochronne/199-fartuch-model-109.html', 'aj-group');

        $product = new Product([
            'sku' => '109/O',
            'name' => 'Fartuch wodoochronny z PU',
            'manufacturer' => 'AJ GROUP',
        ]);

        $hits = app(CatalogIndexSearch::class)->findFor($product);
        $urls = array_column($hits, 'url');

        $this->assertContains('https://pros.pl/pl/fartuchy-wodoochronne/199-fartuch-model-109.html', $urls);
        $this->assertContains('https://inny-sklep.pl/fartuch-wodoochronny-model-109.html', $urls);
        $this->assertNotContains('https://icd.pl/znak-bc109-gasnica.html', $urls);
    }

    public function test_slash_letter_variant_index_is_used_instead_of_web(): void
    {
        $this->seedPage('https://sklep-bhp.example/fartuch-model-109-zielony', 'aj-group');
        $product = Product::query()->create([
            'sku' => '109/O',
            'name' => 'Fartuch wodoochronny z PU',
            'manufacturer' => 'AJ GROUP',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        Http::fake();

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame('catalog_index', $pack['provider']);
        $this->assertSame(
            'https://sklep-bhp.example/fartuch-model-109-zielony',
            $pack['results'][0]['url'] ?? null
        );
        Http::assertNothingSent();
    }

    public function test_short_numeric_sku_index_prefers_official_card(): void
    {
        $this->seedPage('https://icd.pl/kurtka-wodoodporna-pros-300-czerwony.html', 'pros');
        $this->seedPage(
            'https://pros.pl/pl/odziez-wodoochronna-standard/63-kurtka-z-zamkiem-model-300.html',
            'aj-group'
        );
        $product = Product::query()->create([
            'sku' => '300',
            'name' => 'Kurtka wodoochronna zapinana na zamek + stójka + rynienka',
            'manufacturer' => 'AJ GROUP',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        Http::fake();

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame('catalog_index', $pack['provider']);
        $this->assertSame(
            'https://pros.pl/pl/odziez-wodoochronna-standard/63-kurtka-z-zamkiem-model-300.html',
            $pack['results'][0]['url'] ?? null
        );
        Http::assertNothingSent();
    }

    public function test_prefers_page_with_brand_when_code_matches_twice(): void
    {
        $this->seedPage('https://sklep-a.pl/lampka-1202');
        $this->seedPage('https://sklep-b.pl/rekawice-urgent-1202');

        $product = new Product(['sku' => '1202', 'name' => 'Rękawice 1202', 'manufacturer' => 'Urgent']);

        $hits = app(CatalogIndexSearch::class)->findFor($product);

        $this->assertSame('https://sklep-b.pl/rekawice-urgent-1202', $hits[0]['url'] ?? null);
    }

    public function test_uses_bigcommerce_xmlsitemap_php(): void
    {
        $this->fakeHttp([
            'https://idsblast.com/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://idsblast.com/xmlsitemap.php' => Http::response(
                '<?xml version="1.0"?><sitemapindex>'
                .'<sitemap><loc>https://idsblast.com/xmlsitemap.php?type=products</loc></sitemap>'
                .'</sitemapindex>',
                200,
                ['Content-Type' => 'text/xml']
            ),
            'https://idsblast.com/xmlsitemap.php?type=products' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://idsblast.com/al-q-2x/</loc></url>'
                .'</urlset>',
                200,
                ['Content-Type' => 'text/xml']
            ),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('idsblast.com');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://idsblast.com/al-q-2x/']);
    }

    public function test_crawls_iai_product_cards_when_sitemap_missing(): void
    {
        $this->fakeHttp([
            'https://gvarant.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://gvarant.pl/' => Http::response(
                '<!DOCTYPE html><html><body><a href="/rekawice-robocze/">Rękawice</a></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://gvarant.pl/rekawice-robocze/' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="/p494,rekawice-reis-rlevel5.html">Rękawice Reis</a>'
                .'<a href="/puma-safety/">Puma Safety</a>'
                .'<a href="/drewniaki-klapki,44-/">Rozmiar 44</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('gvarant.pl');

        $this->assertGreaterThanOrEqual(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://gvarant.pl/p494,rekawice-reis-rlevel5.html',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://gvarant.pl/drewniaki-klapki,44-/',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://gvarant.pl/puma-safety/',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://gvarant.pl/rekawice-robocze/',
        ]);
    }

    public function test_iai_listing_keeps_gvarant_product_links(): void
    {
        $card = 'https://www.gvarant.pl/p117,sandaly-urgent-302-s1-gray.html';
        $this->fakeHttp([
            'https://robocze-buty.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://www.robocze-buty.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://robocze-buty.pl/' => Http::response(
                '<html><body><a href="/sandaly/">Sandały</a></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://www.robocze-buty.pl/' => Http::response(
                '<html><body><a href="/sandaly/">Sandały</a></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://robocze-buty.pl/sandaly/' => Http::response(
                '<html><body><a href="'.$card.'">Sandały Urgent</a></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://www.robocze-buty.pl/sandaly/' => Http::response(
                '<html><body><a href="'.$card.'">Sandały Urgent</a></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            $card => Http::response(
                '<html><body><h1 class="fn">SANDAŁY URGENT 302 S1 GRAY</h1></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('robocze-buty.pl');

        $this->assertDatabaseHas('catalog_pages', ['url' => $card]);
        $page = CatalogPage::query()->where('url', $card)->first();
        $this->assertNotNull($page);
        $this->assertStringContainsString('SANDAŁY URGENT 302', (string) $page->title);
    }

    public function test_iai_403_card_retries_gvarant_twin(): void
    {
        $card = '/p117,sandaly-urgent-302-s1-gray.html';
        $forbidden = '<html><head><title>403 Forbidden</title></head><body><center><h1>403 Forbidden</h1></center></body></html>';
        $ok = '<html><body><h1 class="fn">SANDAŁY URGENT 302 S1 GRAY</h1></body></html>';
        $this->fakeHttp([
            'https://robocze-buty.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://www.robocze-buty.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://robocze-buty.pl/' => Http::response(
                '<html><body><a href="'.$card.'">Sandały</a></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://www.robocze-buty.pl/' => Http::response(
                '<html><body><a href="'.$card.'">Sandały</a></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://robocze-buty.pl'.$card => Http::response($forbidden, 403, ['Content-Type' => 'text/html']),
            'https://www.robocze-buty.pl'.$card => Http::response($forbidden, 403, ['Content-Type' => 'text/html']),
            'https://gvarant.pl'.$card => Http::response($ok, 200, ['Content-Type' => 'text/html']),
            'https://www.gvarant.pl'.$card => Http::response($ok, 200, ['Content-Type' => 'text/html']),
        ]);

        app(CatalogSitemapIndexer::class)->index('robocze-buty.pl');

        $page = CatalogPage::query()->where('url', 'like', '%'.$card)->first();
        $this->assertNotNull($page);
        $this->assertStringContainsString('SANDAŁY URGENT 302', (string) $page->title);
        $this->assertStringNotContainsString('403', (string) $page->title);
    }

    public function test_reindex_removes_403_forbidden_titles(): void
    {
        $broken = 'https://www.robocze-buty.pl/p117,sandaly-urgent-302-s1-gray.html';
        CatalogPage::query()->create([
            'host' => 'www.robocze-buty.pl',
            'url_hash' => CatalogPage::hashFor($broken),
            'url' => $broken,
            'title' => '403 Forbidden 403 Forbidden',
            'haystack' => $broken,
            'last_seen_at' => now(),
        ]);
        $this->fakeHttp([
            'https://robocze-buty.pl/robots.txt' => Http::response(
                "User-agent: *\nSitemap: https://www.robocze-buty.pl/sitemap.xml\n",
                200
            ),
            'https://www.robocze-buty.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.robocze-buty.pl/p28932,polbuty-lemaitre-ales-s3.html</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('robocze-buty.pl');

        $this->assertDatabaseMissing('catalog_pages', ['url' => $broken]);
    }

    public function test_skips_iai_category_filter_urls_from_sitemap(): void
    {
        $this->fakeHttp([
            'https://robocze-buty.pl/robots.txt' => Http::response(
                "User-agent: *\nSitemap: https://www.robocze-buty.pl/sitemap.xml\n",
                200
            ),
            'https://www.robocze-buty.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.gvarant.pl/p117,sandaly-urgent-302-s1-gray.html</loc></url>'
                .'<url><loc>https://www.robocze-buty.pl/p28932,polbuty-lemaitre-securite-ales-s3-ci.html</loc></url>'
                .'<url><loc>https://www.robocze-buty.pl/puma-safety/</loc></url>'
                .'<url><loc>https://www.robocze-buty.pl/drewniaki-klapki,44-/</loc></url>'
                .'<url><loc>https://www.robocze-buty.pl/drewniaki-klapki,41-42-/</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('robocze-buty.pl');

        $this->assertSame(2, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.gvarant.pl/p117,sandaly-urgent-302-s1-gray.html',
        ]);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.robocze-buty.pl/p28932,polbuty-lemaitre-securite-ales-s3-ci.html',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.robocze-buty.pl/puma-safety/',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.robocze-buty.pl/drewniaki-klapki,44-/',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.robocze-buty.pl/drewniaki-klapki,41-42-/',
        ]);
    }

    public function test_reindex_removes_iai_category_filter_urls(): void
    {
        $listing = 'https://www.robocze-buty.pl/drewniaki-klapki,44-/';
        CatalogPage::query()->create([
            'host' => 'www.robocze-buty.pl',
            'url_hash' => CatalogPage::hashFor($listing),
            'url' => $listing,
            'title' => null,
            'haystack' => $listing,
            'last_seen_at' => now(),
        ]);
        $this->fakeHttp([
            'https://robocze-buty.pl/robots.txt' => Http::response(
                "User-agent: *\nSitemap: https://www.robocze-buty.pl/sitemap.xml\n",
                200
            ),
            'https://www.robocze-buty.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.robocze-buty.pl/p28932,polbuty-lemaitre-ales-s3.html</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('robocze-buty.pl');

        $this->assertDatabaseMissing('catalog_pages', ['url' => $listing]);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.robocze-buty.pl/p28932,polbuty-lemaitre-ales-s3.html',
        ]);
    }

    public function test_crawls_pretty_product_slugs_when_sitemap_is_html(): void
    {
        $this->fakeHttp([
            'https://marelplus.pl/robots.txt' => Http::response(
                "User-agent: *\nSitemap: https://marelplus.pl/p/GoogleSiteMapPlugin/Index\n",
                200
            ),
            'https://marelplus.pl/p/GoogleSiteMapPlugin/Index' => Http::response('', 404),
            'https://marelplus.pl/' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="/polbuty-cadiz-s1ps-fo-sr">Cadiz</a>'
                .'<a href="/kurtka-argo">Argo</a>'
                .'<a href="/o-nas">O nas</a>'
                .'<a href="/kontakt">Kontakt</a>'
                .'<a href="/produkty/pozostale/filtry/filtr-3m-5935-p3">Filtr P3</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('marelplus.pl');

        $this->assertSame(2, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://marelplus.pl/polbuty-cadiz-s1ps-fo-sr']);
        $this->assertDatabaseMissing('catalog_pages', ['url' => 'https://marelplus.pl/kurtka-argo']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://marelplus.pl/produkty/pozostale/filtry/filtr-3m-5935-p3',
        ]);
        $this->assertDatabaseMissing('catalog_pages', ['url' => 'https://marelplus.pl/o-nas']);
        $this->assertDatabaseMissing('catalog_pages', ['url' => 'https://marelplus.pl/kontakt']);
    }

    public function test_skips_shop_listings_from_sitemap_for_common_platforms(): void
    {
        $this->fakeHttp([
            'https://shop.pl/robots.txt' => Http::response("Sitemap: https://shop.pl/sitemap.xml\n", 200),
            'https://shop.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://shop.pl/buty-hoka-speedgoat-7-zielone</loc></url>'
                .'<url><loc>https://shop.pl/123-buty-robocze-s3.html</loc></url>'
                .'<url><loc>https://shop.pl/111237-kalosz-damskimeski-pl</loc></url>'
                .'<url><loc>https://shop.pl/produkt/kurtka-softshell-damska</loc></url>'
                .'<url><loc>https://shop.pl/namioty-kemping</loc></url>'
                .'<url><loc>https://shop.pl/namioty-kemping:dla_1_osoby</loc></url>'
                .'<url><loc>https://shop.pl/on-running</loc></url>'
                .'<url><loc>https://shop.pl/kategoria/buty-robocze</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('shop.pl');

        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://shop.pl/buty-hoka-speedgoat-7-zielone']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://shop.pl/123-buty-robocze-s3.html']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://shop.pl/111237-kalosz-damskimeski-pl']);
        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://shop.pl/produkt/kurtka-softshell-damska']);
        $this->assertDatabaseMissing('catalog_pages', ['url' => 'https://shop.pl/namioty-kemping']);
        $this->assertDatabaseMissing('catalog_pages', ['url' => 'https://shop.pl/namioty-kemping:dla_1_osoby']);
        $this->assertDatabaseMissing('catalog_pages', ['url' => 'https://shop.pl/on-running']);
        $this->assertDatabaseMissing('catalog_pages', ['url' => 'https://shop.pl/kategoria/buty-robocze']);
        $this->assertSame(4, $result['saved']);
    }

    public function test_crawls_pretty_cards_but_not_shop_listings(): void
    {
        $home = '<!DOCTYPE html><html><body>'
            .'<a href="/buty-do-biegania-new-balance-fuelcell-rebel-v5-zielony">Rebel</a>'
            .'<a href="/namioty-kemping">Namioty</a>'
            .'<a href="/namioty-kemping:dla_2_osob">2 os.</a>'
            .'<a href="/on-running">On</a>'
            .'<a href="/la-sportiva">La Sportiva</a>'
            .'</body></html>';
        $this->fakeHttp([
            'https://deporvillage.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://www.deporvillage.pl/' => Http::response($home, 200, ['Content-Type' => 'text/html']),
            'https://deporvillage.pl/' => Http::response($home, 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        app(CatalogSitemapIndexer::class)->index('deporvillage.pl');

        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.deporvillage.pl/buty-do-biegania-new-balance-fuelcell-rebel-v5-zielony',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.deporvillage.pl/namioty-kemping',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.deporvillage.pl/namioty-kemping:dla_2_osob',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.deporvillage.pl/on-running',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.deporvillage.pl/la-sportiva',
        ]);
    }

    public function test_html_crawl_fills_sparse_sitemap(): void
    {
        $this->fakeHttp([
            'https://urgent.pl/robots.txt' => Http::response("Sitemap: https://urgent.pl/sitemap.xml\n", 200),
            'https://urgent.pl/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://urgent.pl/oferta</loc></url>'
                .'<url><loc>https://urgent.pl/kontakt</loc></url>'
                .'</urlset>',
                200,
                ['Content-Type' => 'text/xml']
            ),
            'https://urgent.pl/' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="/rekawice-urgent-1202">Rękawice 1202</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('urgent.pl');

        $this->assertDatabaseHas('catalog_pages', ['url' => 'https://urgent.pl/rekawice-urgent-1202']);
        $this->assertGreaterThanOrEqual(3, $result['saved']);
    }

    public function test_sparse_skips_hosts_with_enough_pages(): void
    {
        Http::fake();
        for ($i = 0; $i < 50; $i++) {
            $this->seedPage('https://optimumbhp.pl/karta-'.$i);
        }

        $this->artisan('catalog:index', [
            'host' => 'optimumbhp.pl',
            '--sparse' => 50,
        ])->expectsOutputToContain('pomijam')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_falls_back_to_wp_sitemap_when_robots_maps_are_html_404(): void
    {
        $card = 'https://www.showagroup.com/eu-en/shop/310/';
        $this->fakeHttp([
            'https://showagroup.com/robots.txt' => Http::response(
                "Sitemap: https://www.showagroup.com/eu-en/product-sitemap2.xml\n",
                200
            ),
            'https://www.showagroup.com/eu-en/product-sitemap2.xml' => Http::response(
                '<!DOCTYPE html><html><head><title>Page not found</title></head></html>',
                404,
                ['Content-Type' => 'text/html']
            ),
            'https://www.showagroup.com/wp-sitemap.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex>'
                .'<sitemap><loc>https://www.showagroup.com/eu-en/wp-sitemap-posts-product-1.xml</loc></sitemap>'
                .'</sitemapindex>',
                200,
                ['Content-Type' => 'application/xml']
            ),
            'https://showagroup.com/wp-sitemap.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex>'
                .'<sitemap><loc>https://www.showagroup.com/eu-en/wp-sitemap-posts-product-1.xml</loc></sitemap>'
                .'</sitemapindex>',
                200,
                ['Content-Type' => 'application/xml']
            ),
            'https://www.showagroup.com/eu-en/wp-sitemap-posts-product-1.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>'.$card.'</loc></url>'
                .'</urlset>',
                200,
                ['Content-Type' => 'application/xml']
            ),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('showagroup.com');

        $this->assertGreaterThanOrEqual(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', ['url' => $card]);
    }

    public function test_reads_wordpress_sitemap_index_served_as_html(): void
    {
        $this->fakeHttp([
            'https://bhpsupply.pl/robots.txt' => Http::response(
                "Sitemap: https://bhpsupply.pl/sitemap_index.xml\n",
                200
            ),
            'https://bhpsupply.pl/sitemap_index.xml' => Http::response(
                '<?xml version="1.0"?><sitemapindex>'
                .'<sitemap><loc>https://bhpsupply.pl/product-sitemap.xml</loc></sitemap>'
                .'</sitemapindex>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://bhpsupply.pl/product-sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://bhpsupply.pl/produkt/rekawice-nitrilowe</loc></url>'
                .'</urlset>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('bhpsupply.pl');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://bhpsupply.pl/produkt/rekawice-nitrilowe',
        ]);
    }

    public function test_uses_magento_media_sitemap_en_from_robots(): void
    {
        $this->fakeHttp([
            'https://ox-on.com/robots.txt' => Http::response(
                "Sitemap: https://www.ox-on.com/media/sitemap/sitemap_en.xml\n",
                200
            ),
            'https://www.ox-on.com/media/sitemap/sitemap_en.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.ox-on.com/gloves/nitril-4500</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('ox-on.com');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.ox-on.com/gloves/nitril-4500',
        ]);
    }

    public function test_guesses_media_sitemap_en_when_robots_empty(): void
    {
        $this->fakeHttp([
            'https://ox-on.com/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://ox-on.com/media/sitemap/sitemap_en.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://ox-on.com/gloves/cut-c</loc></url>'
                .'</urlset>',
                200
            ),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('ox-on.com');

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://ox-on.com/gloves/cut-c',
        ]);
    }

    public function test_rejects_jpeg_disguised_as_sitemap(): void
    {
        $this->fakeHttp([
            'https://bpbhp.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://bpbhp.pl/media/sitemap.xml' => Http::response(
                'not-xml',
                200,
                ['Content-Type' => 'image/jpeg']
            ),
            '*' => Http::response('<!DOCTYPE html><html><head><title>404</title></head></html>', 404),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('bpbhp.pl');

        $this->assertSame(0, $result['urls']);
        $this->assertSame([], $result['sitemaps']);
    }

    public function test_crawls_productpage_urls_and_indexes_code_from_html(): void
    {
        $this->fakeHttp([
            'https://treadsafe.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://treadsafe.test/' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="/products/productcategory/Footwear">Footwear</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://treadsafe.test/products/productcategory/Footwear' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="/brandpage/productpage/763/Tungsten_Boot">Tungsten</a>'
                .'<a href="/products/productcategory/Footwear?color=black&amp;pgc=2">page 2</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://treadsafe.test/products/productcategory/Footwear?pgc=2' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="/brandpage/productpage/734/Flex_Defend">Flex Defend</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://treadsafe.test/brandpage/productpage/763/Tungsten_Boot' => Http::response(
                '<!DOCTYPE html><html><head><title>Tungsten Boot</title></head><body>'
                .'<h1>Tungsten Boot</h1>'
                .'<p>CODE: JN3002 BRAND: Jonsson Footwear SIZES: 3-13</p>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://treadsafe.test/brandpage/productpage/734/Flex_Defend' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<h1>Flex Defend</h1>'
                .'<p>CODE: JAS1003 BRAND: Jonsson Footwear</p>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('treadsafe.test');

        $this->assertGreaterThanOrEqual(2, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://treadsafe.test/brandpage/productpage/763/Tungsten_Boot',
        ]);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://treadsafe.test/brandpage/productpage/734/Flex_Defend',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://treadsafe.test/products/productcategory/Footwear?color=black&pgc=2',
        ]);

        $hits = app(CatalogIndexSearch::class)->findFor(new Product([
            'sku' => 'JN3002',
            'name' => 'Tungsten Boot',
            'manufacturer' => 'Jonsson',
        ]));

        $this->assertSame(
            'https://treadsafe.test/brandpage/productpage/763/Tungsten_Boot',
            $hits[0]['url'] ?? null
        );
    }

    public function test_html_crawl_uses_reader_when_shop_is_unreachable(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_starts_with($url, 'https://r.jina.ai/')) {
                $target = mb_substr($url, mb_strlen('https://r.jina.ai/'));
                if (preg_match('#^https://www\.readershop\.test/?$#', $target) === 1) {
                    return Http::response(
                        '<html><body><a href="/brandpage/productpage/1/Boot">Boot</a></body></html>',
                        200,
                        ['Content-Type' => 'text/html']
                    );
                }
                if (str_contains($target, '/brandpage/productpage/1/Boot')) {
                    return Http::response(
                        '<html><body><h1>Boot</h1><p>CODE: JN1001 BRAND: Jonsson</p></body></html>',
                        200,
                        ['Content-Type' => 'text/html']
                    );
                }

                return Http::response('no', 404);
            }

            return Http::response('no', 404);
        });

        $result = app(CatalogSitemapIndexer::class)->index('readershop.test');

        $this->assertGreaterThanOrEqual(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.readershop.test/brandpage/productpage/1/Boot',
        ]);
    }

    public function test_html_crawl_stops_when_both_homepages_fail(): void
    {
        $hit = [];
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$hit) {
            $hit[] = $request->url();

            return Http::response('no', 404);
        });

        $result = app(CatalogSitemapIndexer::class)->index('deadshop.test');

        $this->assertSame(0, $result['saved']);
        foreach ($hit as $url) {
            $this->assertStringNotContainsString('productcategory', (string) $url);
        }
    }

    public function test_html_crawl_resolves_relative_productpage_href(): void
    {
        $this->fakeHttp([
            'https://relshop.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'https://www.relshop.test/' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="brandpage/productpage/9/Steel_Boot">Steel</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://www.relshop.test/brandpage/productpage/9/Steel_Boot' => Http::response(
                '<!DOCTYPE html><html><body><h1>Steel Boot</h1><p>CODE: JN9001 BRAND: Jonsson</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('relshop.test');

        $this->assertGreaterThanOrEqual(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.relshop.test/brandpage/productpage/9/Steel_Boot',
        ]);
    }

    public function test_html_crawl_runs_when_robots_and_sitemaps_time_out(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if ($url === 'https://slowshop.test/') {
                return Http::response(
                    '<!DOCTYPE html><html><body>'
                    .'<a href="/brandpage/productpage/1/Boot">Boot</a>'
                    .'</body></html>',
                    200,
                    ['Content-Type' => 'text/html']
                );
            }
            if ($url === 'https://slowshop.test/brandpage/productpage/1/Boot') {
                return Http::response(
                    '<!DOCTYPE html><html><body><h1>Boot</h1><p>CODE: JN1001 BRAND: Jonsson</p></body></html>',
                    200,
                    ['Content-Type' => 'text/html']
                );
            }

            throw new ConnectionException('timeout');
        });

        $result = app(CatalogSitemapIndexer::class)->index('slowshop.test');

        $this->assertGreaterThanOrEqual(1, $result['saved']);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://slowshop.test/brandpage/productpage/1/Boot',
        ]);
    }

    public function test_indexes_soteshop_cards_and_skips_categories(): void
    {
        $card = 'https://www.fasterbhp.pl/351,bluza-robocza-brixton-spark.html';
        $this->fakeHttp([
            'https://fasterbhp.pl/robots.txt' => Http::response(
                "User-agent: *\nSitemap: http://www.fasterbhp.pl/sitemaps.xml\n",
                200
            ),
            'http://www.fasterbhp.pl/sitemaps.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>'.$card.'</loc></url>'
                .'<url><loc>https://www.fasterbhp.pl/odziez-robocza,166.html</loc></url>'
                .'<url><loc>https://www.fasterbhp.pl/odziez-robocza,166,1634,2.html</loc></url>'
                .'<url><loc>https://www.fasterbhp.pl/o-sklepie,11.html</loc></url>'
                .'<url><loc>https://www.fasterbhp.pl/index.html</loc></url>'
                .'</urlset>',
                200,
                ['Content-Type' => 'application/xml']
            ),
        ]);

        $result = app(CatalogSitemapIndexer::class)->index('fasterbhp.pl');

        $this->assertDatabaseHas('catalog_pages', ['url' => $card]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.fasterbhp.pl/odziez-robocza,166.html',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.fasterbhp.pl/odziez-robocza,166,1634,2.html',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.fasterbhp.pl/o-sklepie,11.html',
        ]);
        $this->assertGreaterThanOrEqual(1, $result['saved']);
    }

    public function test_crawls_soteshop_category_when_sitemap_already_has_cards(): void
    {
        $locs = '';
        for ($i = 1; $i <= 50; $i++) {
            $locs .= '<url><loc>https://www.fasterbhp.pl/'.$i.',produkt-stary-'.$i.'.html</loc></url>';
        }
        $locs .= '<url><loc>https://www.fasterbhp.pl/odziez-robocza,166.html</loc></url>';
        $home = '<!DOCTYPE html><html><body>'
            .'<a href="/odziez-robocza,166.html">Odzież</a>'
            .'</body></html>';
        $this->fakeHttp([
            'https://fasterbhp.pl/robots.txt' => Http::response(
                "Sitemap: https://www.fasterbhp.pl/sitemaps.xml\n",
                200
            ),
            'https://www.fasterbhp.pl/sitemaps.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'.$locs.'</urlset>',
                200,
                ['Content-Type' => 'application/xml']
            ),
            'https://www.fasterbhp.pl/' => Http::response($home, 200, ['Content-Type' => 'text/html']),
            'https://fasterbhp.pl/' => Http::response($home, 200, ['Content-Type' => 'text/html']),
            'https://www.fasterbhp.pl/odziez-robocza,166.html' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="/999,bluza-nowa-z-listingu.html">Nowa bluza</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('fasterbhp.pl');

        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.fasterbhp.pl/1,produkt-stary-1.html',
        ]);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.fasterbhp.pl/999,bluza-nowa-z-listingu.html',
        ]);
        $this->assertDatabaseMissing('catalog_pages', [
            'url' => 'https://www.fasterbhp.pl/odziez-robocza,166.html',
        ]);
    }

    public function test_uses_soteshop_sitemap_categories_as_crawl_seeds(): void
    {
        $locs = '';
        for ($i = 1; $i <= 50; $i++) {
            $locs .= '<url><loc>https://www.fasterbhp.pl/'.$i.',produkt-stary-'.$i.'.html</loc></url>';
        }
        $locs .= '<url><loc>https://www.fasterbhp.pl/odziez-robocza,166.html</loc></url>';
        $this->fakeHttp([
            'https://fasterbhp.pl/robots.txt' => Http::response(
                "Sitemap: https://www.fasterbhp.pl/sitemaps.xml\n",
                200
            ),
            'https://www.fasterbhp.pl/sitemaps.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'.$locs.'</urlset>',
                200,
                ['Content-Type' => 'application/xml']
            ),
            'https://www.fasterbhp.pl/' => Http::response(
                '<!DOCTYPE html><html><body><p>Menu bez kategorii</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://fasterbhp.pl/' => Http::response(
                '<!DOCTYPE html><html><body><p>Menu bez kategorii</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://www.fasterbhp.pl/odziez-robocza,166.html' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="/888,kurtka-tylko-z-kategorii.html">Kurtka</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('fasterbhp.pl');

        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.fasterbhp.pl/888,kurtka-tylko-z-kategorii.html',
        ]);
    }

    public function test_follows_soteshop_pagination_to_collect_more_cards(): void
    {
        $this->fakeHttp([
            'https://fasterbhp.pl/robots.txt' => Http::response(
                "Sitemap: https://www.fasterbhp.pl/sitemaps.xml\n",
                200
            ),
            'https://www.fasterbhp.pl/sitemaps.xml' => Http::response(
                '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.fasterbhp.pl/1,stara-karta.html</loc></url>'
                .'<url><loc>https://www.fasterbhp.pl/odziez-robocza,166.html</loc></url>'
                .'</urlset>',
                200,
                ['Content-Type' => 'application/xml']
            ),
            'https://www.fasterbhp.pl/' => Http::response(
                '<!DOCTYPE html><html><body><a href="/odziez-robocza,166.html">Odzież</a></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://fasterbhp.pl/' => Http::response(
                '<!DOCTYPE html><html><body><a href="/odziez-robocza,166.html">Odzież</a></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://www.fasterbhp.pl/odziez-robocza,166.html' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="/10,bluza-strona-1.html">Bluza 1</a>'
                .'<a href="/odziez-robocza,166,1634,2.html">2</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://www.fasterbhp.pl/odziez-robocza,166,1634,2.html' => Http::response(
                '<!DOCTYPE html><html><body>'
                .'<a href="/20,bluza-strona-2.html">Bluza 2</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        app(CatalogSitemapIndexer::class)->index('fasterbhp.pl');

        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.fasterbhp.pl/10,bluza-strona-1.html',
        ]);
        $this->assertDatabaseHas('catalog_pages', [
            'url' => 'https://www.fasterbhp.pl/20,bluza-strona-2.html',
        ]);
    }

    private function seedPage(string $url, ?string $manufacturer = null): void
    {
        $page = CatalogPage::query()->create([
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'manufacturer' => $manufacturer,
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'haystack' => mb_strtolower($url),
            'last_seen_at' => now(),
        ]);

        app(CatalogSitemapIndexer::class)->storeTokens([$page->url_hash]);
    }
}
