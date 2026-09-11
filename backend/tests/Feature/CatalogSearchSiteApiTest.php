<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\IndexCatalogHostJob;
use App\Models\CatalogHost;
use App\Models\CatalogPage;
use App\Models\CatalogSearchSite;
use App\Models\CatalogSearchSiteExclusion;
use App\Models\Product;
use App\Models\User;
use App\Services\Enrichment\CatalogSitemapIndexer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CatalogSearchSiteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'enrichment.retailer_domains' => ['sklepbhp.pl', 'www.sklepbhp.pl'],
            'enrichment.preferred_domains' => ['sklepbhp.pl'],
            'enrichment.manufacturer_domains' => [],
        ]);
    }

    public function test_admin_lists_search_sites_with_link_counts(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->seedPage('https://sklepbhp.pl/buty-s3');
        $this->seedPage('https://www.sklepbhp.pl/kalosze');

        $this->getJson('/api/admin/catalog-search-sites')
            ->assertOk()
            ->assertJsonPath('links', 2)
            ->assertJsonFragment([
                'host' => 'sklepbhp.pl',
                'links' => 2,
                'source_label' => 'Konfiguracja',
            ]);
    }

    public function test_admin_adds_new_site_and_rejects_duplicate(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Queue::fake();

        $this->postJson('/api/admin/catalog-search-sites', [
            'url' => 'https://www.nowysklep-bhp.pl/oferta',
        ])
            ->assertCreated()
            ->assertJsonPath('host', 'nowysklep-bhp.pl')
            ->assertJsonPath('already', false);

        $this->assertTrue(CatalogSearchSite::hasHost('nowysklep-bhp.pl'));
        Queue::assertPushed(IndexCatalogHostJob::class, fn (IndexCatalogHostJob $job): bool => $job->host === 'nowysklep-bhp.pl');

        $this->postJson('/api/admin/catalog-search-sites', [
            'url' => 'nowysklep-bhp.pl',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.url.0', 'Strona nowysklep-bhp.pl jest już dodana (0 linków).');

        $this->postJson('/api/admin/catalog-search-sites', [
            'url' => 'https://sklepbhp.pl',
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['url' => ['Strona sklepbhp.pl jest już dodana (0 linków).']]);
    }

    public function test_handlowiec_cannot_manage_search_sites(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/admin/catalog-search-sites')->assertForbidden();
        $this->postJson('/api/admin/catalog-search-sites', ['url' => 'x.pl'])->assertForbidden();
        $this->getJson('/api/admin/catalog-search-sites/product-lookup?q=T5163000')->assertForbidden();
        $this->getJson('/api/admin/catalog-search-sites/sklepbhp.pl/pages')->assertForbidden();
        $this->getJson('/api/admin/catalog-search-sites/sklepbhp.pl/progress')->assertForbidden();
        $this->postJson('/api/admin/catalog-search-sites/sklepbhp.pl/reindex')->assertForbidden();
        $this->deleteJson('/api/admin/catalog-search-sites/sklepbhp.pl')->assertForbidden();
    }

    public function test_admin_deletes_site_pages_and_hides_config_host(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $kept = $this->seedPage('https://inny-sklep.pl/karta');
        $a = $this->seedPage('https://sklepbhp.pl/buty-s3');
        $b = $this->seedPage('https://www.sklepbhp.pl/kalosze');
        DB::table('catalog_page_tokens')->insert([
            ['catalog_page_id' => $a->id, 'token' => 'buty'],
            ['catalog_page_id' => $b->id, 'token' => 'kalosze'],
            ['catalog_page_id' => $kept->id, 'token' => 'inny'],
        ]);

        $this->deleteJson('/api/admin/catalog-search-sites/sklepbhp.pl')
            ->assertOk()
            ->assertJsonPath('host', 'sklepbhp.pl')
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('pages', 2);

        $this->assertFalse(CatalogPage::query()->where('host', 'sklepbhp.pl')->exists());
        $this->assertFalse(CatalogPage::query()->where('host', 'www.sklepbhp.pl')->exists());
        $this->assertFalse(DB::table('catalog_page_tokens')->whereIn('catalog_page_id', [$a->id, $b->id])->exists());
        $this->assertTrue(DB::table('catalog_page_tokens')->where('catalog_page_id', $kept->id)->where('token', 'inny')->exists());
        $this->assertTrue(CatalogSearchSiteExclusion::hasHost('sklepbhp.pl'));

        $this->getJson('/api/admin/catalog-search-sites')
            ->assertOk()
            ->assertJsonMissing(['host' => 'sklepbhp.pl']);

        $this->deleteJson('/api/admin/catalog-search-sites/sklepbhp.pl')
            ->assertStatus(422);
    }

    public function test_admin_can_add_site_again_after_delete(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Queue::fake();
        config(['enrichment.retailer_domains' => [], 'enrichment.preferred_domains' => []]);
        CatalogSearchSite::query()->create(['host' => 'nowysklep-bhp.pl', 'source' => 'manual']);

        $this->deleteJson('/api/admin/catalog-search-sites/nowysklep-bhp.pl')->assertOk();
        $this->assertFalse(CatalogSearchSite::hasHost('nowysklep-bhp.pl'));

        $this->postJson('/api/admin/catalog-search-sites', ['url' => 'nowysklep-bhp.pl'])
            ->assertCreated()
            ->assertJsonPath('host', 'nowysklep-bhp.pl');

        $this->assertTrue(CatalogSearchSite::hasHost('nowysklep-bhp.pl'));
        $this->assertFalse(CatalogSearchSiteExclusion::hasHost('nowysklep-bhp.pl'));
    }

    public function test_admin_lists_pages_for_host_including_www(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->seedPage('https://www.sklepbhp.pl/buty-s3', 'Buty S3', 'ardon');
        $this->seedPage('https://sklepbhp.pl/kalosze');

        $this->getJson('/api/admin/catalog-search-sites/sklepbhp.pl/pages')
            ->assertOk()
            ->assertJsonPath('host', 'sklepbhp.pl')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment([
                'url' => 'https://www.sklepbhp.pl/buty-s3',
                'title' => 'Buty S3',
                'manufacturer' => 'ardon',
            ]);

        $this->getJson('/api/admin/catalog-search-sites/sklepbhp.pl/pages?q=buty')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/admin/catalog-search-sites/sklepbhp.pl/pages?q=ardon')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/admin/catalog-search-sites/nieznana-domena.pl/pages')
            ->assertStatus(422);
    }

    public function test_admin_reindexes_existing_host(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Queue::fake();

        $this->postJson('/api/admin/catalog-search-sites/sklepbhp.pl/reindex')
            ->assertOk()
            ->assertJsonPath('host', 'sklepbhp.pl')
            ->assertJsonPath('queued', true);

        Queue::assertPushed(IndexCatalogHostJob::class, fn (IndexCatalogHostJob $job): bool => $job->host === 'sklepbhp.pl');

        $this->postJson('/api/admin/catalog-search-sites/nieznana-domena.pl/reindex')
            ->assertStatus(422);
    }

    public function test_admin_reads_reindex_progress(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Queue::fake();

        $this->postJson('/api/admin/catalog-search-sites/sklepbhp.pl/reindex')->assertOk();

        $this->getJson('/api/admin/catalog-search-sites/sklepbhp.pl/progress')
            ->assertOk()
            ->assertJsonPath('host', 'sklepbhp.pl')
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('lines.0.text', 'Zlecono sprawdzenie sklepbhp.pl.');
    }

    public function test_unknown_host_progress_is_idle(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/admin/catalog-search-sites/nieznana-domena.pl/progress')
            ->assertOk()
            ->assertJsonPath('host', 'nieznana-domena.pl')
            ->assertJsonPath('status', 'idle')
            ->assertJsonPath('lines', []);
    }

    public function test_junk_hosts_are_hidden_after_exclusion_seed(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        config([
            'enrichment.retailer_domains' => ['sklepbhp.pl', '3m.com', 'kaufland.pl'],
            'enrichment.manufacturer_domains' => [
                'uvex' => ['uvex-safety.com', 'media.uvex.de', 'd3nan4w00fsv2d.cloudfront.net'],
                '3m' => ['3m.com'],
            ],
            'enrichment.preferred_domains' => ['sklepbhp.pl'],
            'enrichment.catalog_skip_hosts' => [],
        ]);

        $this->getJson('/api/admin/catalog-search-sites')
            ->assertOk()
            ->assertJsonFragment(['host' => 'sklepbhp.pl'])
            ->assertJsonFragment(['host' => 'uvex-safety.com'])
            ->assertJsonMissing(['host' => '3m.com'])
            ->assertJsonMissing(['host' => 'kaufland.pl'])
            ->assertJsonMissing(['host' => 'media.uvex.de'])
            ->assertJsonMissing(['host' => 'd3nan4w00fsv2d.cloudfront.net']);
    }

    public function test_admin_unskips_and_reskips_config_blocked_host(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Queue::fake();
        config([
            'enrichment.retailer_domains' => ['sklepbhp.pl', 'blocked-shop.test'],
            'enrichment.catalog_skip_hosts' => ['blocked-shop.test'],
        ]);

        $this->getJson('/api/admin/catalog-search-sites')
            ->assertOk()
            ->assertJsonFragment([
                'host' => 'blocked-shop.test',
                'empty_reason' => 'Pominięta na liście catalog_skip_hosts.',
                'is_config_skip_listed' => true,
                'skip_overridden' => false,
            ]);

        $this->postJson('/api/admin/catalog-search-sites/blocked-shop.test/unskip')
            ->assertOk()
            ->assertJsonPath('host', 'blocked-shop.test')
            ->assertJsonPath('queued', true);

        Queue::assertPushed(IndexCatalogHostJob::class, fn (IndexCatalogHostJob $job): bool => $job->host === 'blocked-shop.test');

        $this->getJson('/api/admin/catalog-search-sites')
            ->assertOk()
            ->assertJsonFragment([
                'host' => 'blocked-shop.test',
                'skip_overridden' => true,
            ]);

        $this->postJson('/api/admin/catalog-search-sites/sklepbhp.pl/unskip')
            ->assertStatus(422)
            ->assertJsonPath('errors.host.0', 'Domena sklepbhp.pl nie jest na liście pomijanych (catalog_skip_hosts).');

        $this->postJson('/api/admin/catalog-search-sites/blocked-shop.test/reskip')
            ->assertOk()
            ->assertJsonPath('host', 'blocked-shop.test');

        $this->getJson('/api/admin/catalog-search-sites')
            ->assertOk()
            ->assertJsonFragment([
                'host' => 'blocked-shop.test',
                'empty_reason' => 'Pominięta na liście catalog_skip_hosts.',
                'skip_overridden' => false,
            ]);
    }

    public function test_handlowiec_cannot_unskip_or_reskip_hosts(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        config(['enrichment.catalog_skip_hosts' => ['blocked-shop.test']]);

        $this->postJson('/api/admin/catalog-search-sites/blocked-shop.test/unskip')->assertForbidden();
        $this->postJson('/api/admin/catalog-search-sites/blocked-shop.test/reskip')->assertForbidden();
    }

    public function test_empty_reason_explains_zero_link_hosts(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        config([
            'enrichment.retailer_domains' => ['sklepbhp.pl', 'blocked-shop.test', 'cdn.example.cloudfront.net'],
            'enrichment.catalog_skip_hosts' => ['blocked-shop.test'],
        ]);
        CatalogHost::query()->create([
            'host' => 'sklepbhp.pl',
            'pages_count' => 0,
            'last_attempt_at' => now(),
            'last_error' => 'Nie znalazłem sitemapy dla sklepbhp.pl.',
        ]);

        $this->getJson('/api/admin/catalog-search-sites')
            ->assertOk()
            ->assertJsonFragment([
                'host' => 'sklepbhp.pl',
                'links' => 0,
                'empty_reason' => 'Nie znalazłem sitemapy dla sklepbhp.pl.',
            ])
            ->assertJsonFragment([
                'host' => 'blocked-shop.test',
                'empty_reason' => 'Pominięta na liście catalog_skip_hosts.',
            ])
            ->assertJsonFragment([
                'host' => 'cdn.example.cloudfront.net',
                'empty_reason' => 'CDN — przy pełnym skanie pomijany.',
            ]);
    }

    public function test_admin_looks_up_mapping_by_exact_sku(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        config([
            'enrichment.manufacturer_domains' => [
                'secura' => ['infield-safety.com'],
            ],
        ]);
        $url = 'https://infield-safety.com/produkte/schutzbrillen/t5163000-raptor';
        $this->seedIndexedPage($url, 'raptor schwarz', 'infield');
        $this->createProduct('T5163000', 'Okulary Raptor przezroczyste', 'SECURA');

        $this->getJson('/api/admin/catalog-search-sites/product-lookup?q=T5163000')
            ->assertOk()
            ->assertJsonPath('product.sku', 'T5163000')
            ->assertJsonPath('mapped', true)
            ->assertJsonPath('hits.0.url', $url)
            ->assertJsonPath('hits.0.host', 'infield-safety.com')
            ->assertJsonPath('hit_hosts.0.host', 'infield-safety.com')
            ->assertJsonFragment(['host' => 'infield-safety.com', 'in_sites' => true]);
    }

    public function test_admin_looks_up_mapping_by_product_id(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $url = 'https://infield-safety.com/produkte/schutzbrillen/t5163000-raptor';
        $this->seedIndexedPage($url, 'raptor schwarz', 'infield');
        $product = $this->createProduct('T5163000', 'Okulary Raptor przezroczyste', 'SECURA');

        $this->getJson('/api/admin/catalog-search-sites/product-lookup?product_id='.$product->id)
            ->assertOk()
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('mapped', true)
            ->assertJsonPath('hits.0.url', $url);
    }

    public function test_lookup_lists_rejected_candidates_with_reason(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        config([
            'enrichment.manufacturer_domains' => [
                'secura' => ['infield-safety.com'],
            ],
        ]);
        $url = 'https://infield-safety.com/produkte/schutzbrillen/t5163000-raptor';
        $foreign = 'https://sklep-delta.pl/okulary-t5163000-raptor';
        $this->seedIndexedPage($url, 'raptor schwarz', 'infield');
        $this->seedIndexedPage($foreign, 'okulary raptor', 'delta-plus');
        $this->createProduct('T5163000', 'Okulary Raptor przezroczyste', 'SECURA');

        $this->getJson('/api/admin/catalog-search-sites/product-lookup?q=T5163000')
            ->assertOk()
            ->assertJsonPath('hits.0.url', $url)
            ->assertJsonFragment([
                'url' => $foreign,
                'host' => 'sklep-delta.pl',
                'reason' => 'manufacturer_conflict',
                'label' => 'strona innego producenta',
            ]);
    }

    public function test_name_search_does_not_auto_pick_when_many_match(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->createProduct('R-1', 'Okulary Raptor przezroczyste', 'SECURA');
        $this->createProduct('R-2', 'Okulary Raptor przyciemniane', 'SECURA');

        $this->getJson('/api/admin/catalog-search-sites/product-lookup?q=Raptor')
            ->assertOk()
            ->assertJsonPath('product', null)
            ->assertJsonPath('mapped', false)
            ->assertJsonCount(2, 'products');
    }

    public function test_lookup_requires_query_or_product(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/admin/catalog-search-sites/product-lookup')
            ->assertStatus(422);
        $this->getJson('/api/admin/catalog-search-sites/product-lookup?q=x')
            ->assertStatus(422);
        $this->getJson('/api/admin/catalog-search-sites/product-lookup?product_id=999999')
            ->assertStatus(422);
    }

    private function createProduct(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
    }

    private function seedIndexedPage(string $url, string $title, ?string $manufacturer = null): CatalogPage
    {
        $page = $this->seedPage($url, $title, $manufacturer);
        app(CatalogSitemapIndexer::class)->storeTokens([$page->url_hash]);

        return $page;
    }

    private function seedPage(string $url, ?string $title = null, ?string $manufacturer = null): CatalogPage
    {
        return CatalogPage::query()->create([
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'title' => $title,
            'manufacturer' => $manufacturer,
            'haystack' => mb_strtolower($url),
            'last_seen_at' => now(),
        ]);
    }
}
