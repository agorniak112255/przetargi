<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\IndexCatalogHostJob;
use App\Models\CatalogPage;
use App\Models\CatalogSearchSite;
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
