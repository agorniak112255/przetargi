<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\IndexCatalogHostJob;
use App\Models\CatalogHost;
use App\Models\CatalogPage;
use App\Models\CatalogSearchSite;
use App\Models\CatalogSearchSiteExclusion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->getJson('/api/admin/catalog-search-sites/sklepbhp.pl/pages')->assertForbidden();
        $this->postJson('/api/admin/catalog-search-sites/sklepbhp.pl/reindex')->assertForbidden();
        $this->deleteJson('/api/admin/catalog-search-sites/sklepbhp.pl')->assertForbidden();
    }

    public function test_admin_deletes_site_pages_and_hides_config_host(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->seedPage('https://sklepbhp.pl/buty-s3');
        $this->seedPage('https://www.sklepbhp.pl/kalosze');

        $this->deleteJson('/api/admin/catalog-search-sites/sklepbhp.pl')
            ->assertOk()
            ->assertJsonPath('host', 'sklepbhp.pl')
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('pages', 2);

        $this->assertFalse(CatalogPage::query()->where('host', 'sklepbhp.pl')->exists());
        $this->assertFalse(CatalogPage::query()->where('host', 'www.sklepbhp.pl')->exists());
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
        $this->seedPage('https://www.sklepbhp.pl/buty-s3', 'Buty S3');
        $this->seedPage('https://sklepbhp.pl/kalosze');

        $this->getJson('/api/admin/catalog-search-sites/sklepbhp.pl/pages')
            ->assertOk()
            ->assertJsonPath('host', 'sklepbhp.pl')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['url' => 'https://www.sklepbhp.pl/buty-s3', 'title' => 'Buty S3']);

        $this->getJson('/api/admin/catalog-search-sites/sklepbhp.pl/pages?q=buty')
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

    private function seedPage(string $url, ?string $title = null): void
    {
        CatalogPage::query()->create([
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'title' => $title,
            'haystack' => mb_strtolower($url),
            'last_seen_at' => now(),
        ]);
    }
}
