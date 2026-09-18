<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogHostPriority;
use App\Models\CatalogPage;
use App\Models\ManufacturerSite;
use App\Models\Product;
use App\Models\User;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ProductEnrichmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use Tests\TestCase;

/**
 * Ręczna ranga domeny (1–100) i jej wpływ na wybór źródła opisu karty. Hierarchia właściciela:
 * producent, potem zmapowane strony wg rangi, potem pozostałe zmapowane, na końcu reszta.
 */
final class CatalogHostPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'enrichment.retailer_domains' => ['sklepbhp.pl', 'inny-sklep.pl'],
            'enrichment.preferred_domains' => [],
            'enrichment.manufacturer_domains' => [],
        ]);
    }

    public function test_priority_can_be_set_for_a_host_that_lives_only_in_config(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        // sklepbhp.pl nie ma wiersza w catalog_search_sites — jest tylko w configu
        $this->postJson('/api/admin/catalog-search-sites/sklepbhp.pl/priority', ['priority' => 3])
            ->assertOk()
            ->assertJsonPath('priority', 3);

        $this->getJson('/api/admin/catalog-search-sites')
            ->assertOk()
            ->assertJsonFragment(['host' => 'sklepbhp.pl', 'priority' => 3]);

        $this->deleteJson('/api/admin/catalog-search-sites/sklepbhp.pl/priority')
            ->assertOk()
            ->assertJsonPath('priority', null);
        $this->assertSame([], CatalogHostPriority::map());
    }

    public function test_priority_outside_the_range_and_unknown_host_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/admin/catalog-search-sites/sklepbhp.pl/priority', ['priority' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('priority');
        $this->postJson('/api/admin/catalog-search-sites/sklepbhp.pl/priority', ['priority' => 101])
            ->assertStatus(422)->assertJsonValidationErrors('priority');
        $this->postJson('/api/admin/catalog-search-sites/sklepbhp.pl/priority', ['priority' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors('priority');
        $this->postJson('/api/admin/catalog-search-sites/nieznana-domena.pl/priority', ['priority' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('host');
    }

    public function test_priority_needs_admin_access(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->postJson('/api/admin/catalog-search-sites/sklepbhp.pl/priority', ['priority' => 5])
            ->assertForbidden();
    }

    public function test_priority_does_not_turn_a_manufacturer_domain_into_a_shop(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        ManufacturerSite::remember('artra', 'ARTRA', ['artra.pl'], 'manual');
        $this->seedPage('https://artra.pl/products/aragon-920');

        $this->postJson('/api/admin/catalog-search-sites/artra.pl/priority', ['priority' => 1])->assertOk();

        // ranga to wyłącznie kolejność źródeł opisu: domena producenta nią nie przestaje być
        $product = new Product(['manufacturer' => 'ARTRA', 'sku' => 'ARAGON-920', 'name' => 'Trzewik ARAGON']);
        $this->assertTrue(app(ManufacturerDomainResolver::class)->isManufacturerUrl(
            'https://artra.pl/products/aragon-920',
            $product,
            ['artra.pl']
        ));
    }

    public function test_ranked_hosts_come_before_unranked_ones_but_after_the_hinted_card(): void
    {
        CatalogHostPriority::set('inny-sklep.pl', 1);
        CatalogHostPriority::set('sklepbhp.pl', 90);

        $product = new Product(['manufacturer' => 'ARTRA', 'sku' => 'ARAGON-920', 'name' => 'Trzewik ARAGON']);
        $ranked = $this->invoke('rankResultsForDescription', [
            [
                ['url' => 'https://trzeci-sklep.pl/p/aragon', 'title' => '', 'snippet' => ''],
                ['url' => 'https://sklepbhp.pl/p/aragon', 'title' => '', 'snippet' => ''],
                ['url' => 'https://inny-sklep.pl/p/aragon', 'title' => '', 'snippet' => ''],
            ],
            $product,
            ['artra.pl'],
        ]);

        $this->assertSame(
            ['inny-sklep.pl', 'sklepbhp.pl', 'trzeci-sklep.pl'],
            array_map(static fn (array $r): string => (string) parse_url((string) $r['url'], PHP_URL_HOST), $ranked)
        );
    }

    public function test_without_any_priority_the_order_is_the_one_from_before(): void
    {
        $product = new Product(['manufacturer' => 'Uvex', 'sku' => '60549', 'name' => 'uvex C300 Dry']);
        $results = [
            ['url' => 'https://www.uvex-safety.com/en/product/c300-dry', 'title' => '', 'snippet' => ''],
            ['url' => 'https://sklepbhp.pl/produkt/c300-dry', 'title' => '', 'snippet' => ''],
            ['url' => 'https://other-shop.example/item/60549', 'title' => '', 'snippet' => ''],
        ];

        $ranked = $this->invoke('rankResultsForDescription', [$results, $product, ['uvex-safety.com']]);

        // sklep zmapowany, potem reszta, producent na końcu (ma osobną ścieżkę pobrania)
        $this->assertSame(
            ['sklepbhp.pl', 'other-shop.example', 'www.uvex-safety.com'],
            array_map(static fn (array $r): string => (string) parse_url((string) $r['url'], PHP_URL_HOST), $ranked)
        );
    }

    public function test_pages_for_the_prompt_go_manufacturer_then_ranked_then_rest(): void
    {
        CatalogHostPriority::set('inny-sklep.pl', 2);
        $long = str_repeat('Trzewik ochronny z podnoskiem kompozytowym. ', 20);

        $product = new Product(['manufacturer' => 'ARTRA', 'sku' => 'ARAGON-920', 'name' => 'Trzewik ARAGON']);
        $ordered = $this->invoke('orderPagesForDescription', [
            [
                ['url' => 'https://trzeci-sklep.pl/p/aragon', 'text' => $long],
                ['url' => 'https://inny-sklep.pl/p/aragon', 'text' => $long],
                ['url' => 'https://artra.pl/products/aragon-920', 'text' => $long],
            ],
            $product,
            ['artra.pl'],
        ]);

        $this->assertSame(
            ['artra.pl', 'inny-sklep.pl', 'trzeci-sklep.pl'],
            array_map(static fn (array $p): string => (string) parse_url((string) $p['url'], PHP_URL_HOST), $ordered)
        );
    }

    /**
     * @param  list<mixed>  $args
     * @return list<array<string, mixed>>
     */
    private function invoke(string $method, array $args): array
    {
        $service = app(ProductEnrichmentService::class);
        $ref = (new ReflectionClass($service))->getMethod($method);
        $ref->setAccessible(true);

        /** @var list<array<string, mixed>> $out */
        $out = $ref->invokeArgs($service, $args);

        return $out;
    }

    private function seedPage(string $url): void
    {
        CatalogPage::query()->create([
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'title' => 'Karta',
            'haystack' => mb_strtolower($url),
            'last_seen_at' => now(),
        ]);
    }
}
