<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CatalogPage;
use App\Models\ManufacturerSite;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Enrichment\HybridWebSearchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Cenniki → „Z pliku”: GET /price-lists/files, GET /price-lists/search-sites, POST /price-lists/{id}/site-check.
 */
final class PriceListFilesApiTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Rękawice robocze powlekane nitrylem, mankiet ściągacz, norma EN 388.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_files_counts_description_sources_hosts_and_open_batch(): void
    {
        $sitesChangedAt = Carbon::parse('2026-10-05 12:00:00');
        $before = $sitesChangedAt->copy()->subDay();
        $after = $sitesChangedAt->copy()->addHour();
        $list = $this->list('Testowy', [
            'enrichment_sites' => ['sklep-a.pl', 'sklep-b.pl', 'sklep-c.pl'],
            'enrichment_sites_mode' => PriceList::MODE_ONLY,
            'enrichment_sites_updated_at' => $sitesChangedAt,
        ]);

        // strona cennika (www. i subdomena liczą się do hosta), opis sprzed zmiany stron → „stare”
        $this->card($list, 'A-1', ['enriched_at' => $before, 'enrichment_status' => Product::ENRICHMENT_DONE], 'https://www.sklep-a.pl/p/a-1', 'shop');
        $this->card($list, 'A-2', ['enriched_at' => $after, 'enrichment_status' => Product::ENRICHMENT_DONE], 'https://hurt.sklep-a.pl/a-2', 'shop');
        $this->card($list, 'A-3', ['enriched_at' => $after, 'enrichment_status' => Product::ENRICHMENT_DONE], 'https://sklep-b.pl/a-3', 'manual');
        // producent i jego katalog PDF — przed zmianą stron, ale strony cennika ich nie zmieniają
        $this->card($list, 'A-4', ['enriched_at' => $before, 'enrichment_status' => Product::ENRICHMENT_DONE], 'https://producent.pl/a-4', 'manufacturer');
        $this->card($list, 'A-5', ['enriched_at' => $before, 'enrichment_status' => Product::ENRICHMENT_DONE], 'https://producent.pl/katalog.pdf', 'catalog');
        // inne źródło i opis bez zapisanego źródła
        $this->card($list, 'A-6', ['enriched_at' => $before, 'enrichment_status' => Product::ENRICHMENT_FAILED], 'https://inny-sklep.pl/a-6', 'shop');
        $this->card($list, 'A-7', ['enrichment_status' => Product::ENRICHMENT_MANUAL]);
        // bez opisu: pusty i sama nazwa z cennika
        $this->card($list, 'A-8', ['description' => null, 'enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $this->card($list, 'A-9', ['description' => 'Rękawice testowe A-9 długa nazwa', 'name' => 'Rękawice testowe A-9 długa nazwa', 'enrichment_status' => Product::ENRICHMENT_RUNNING]);
        // opis ze sklepu dostawcy B2B (powiązanie z tym samym skrótem opisu)
        $b2b = $this->card($list, 'A-10', ['description' => 'Opis ze sklepu dostawcy B2B, dzianina nylonowa 13.']);
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account()->id,
            'remote_id' => 'A-10',
            'product_id' => $b2b->id,
            'remote_sku' => 'A-10',
            'remote_name' => 'Rękawice',
            'description_hash' => sha1((string) $b2b->description),
        ]);
        // JSON null w ścieżce (MySQL json_unquote daje napis „null”)
        $this->card($list, 'A-11', ['enrichment_payload' => ['primary_source_url' => null, 'primary_source_kind' => null]]);

        foreach (['www.sklep-a.pl', 'www.sklep-a.pl', 'sklep-a.pl', 'sklep-c.pl', 'inny-sklep.pl'] as $i => $host) {
            $this->page($host, "https://{$host}/strona-{$i}");
        }
        ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRICE_LIST, 'scope_id' => $list->id, 'total' => 4, 'done' => 4,
            'failed' => 0, 'status' => ProductEnrichmentBatch::STATUS_DONE, 'created_by' => $this->admin->id,
        ]);
        $open = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRICE_LIST, 'scope_id' => $list->id, 'total' => 5, 'done' => 2,
            'failed' => 1, 'status' => ProductEnrichmentBatch::STATUS_RUNNING, 'created_by' => $this->admin->id,
        ]);
        ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRICE_LIST, 'scope_id' => $list->id, 'total' => 1, 'done' => 1,
            'failed' => 0, 'status' => ProductEnrichmentBatch::STATUS_DONE, 'created_by' => $this->admin->id,
        ]);

        // cennik bez slotów pliku (np. wpis konta B2B) nie należy do zakładki
        $this->list('Tylko B2B');

        $response = $this->getJson('/api/price-lists/files')->assertOk();
        $response->assertJsonCount(1, 'lists');
        $row = $response->json('lists.0');

        $this->assertSame([
            'id', 'manufacturer', 'version', 'enrichment_sites', 'enrichment_sites_mode', 'enrichment_sites_updated_at',
            'has_b2b_account', 'cards', 'described', 'sources', 'stale', 'queued', 'running', 'failed', 'manual',
            'batch', 'hosts', 'identity', 'to_review', 'with_image', 'models',
        ], array_keys($row));
        $this->assertSame($list->id, $row['id']);
        // marka bez profilu grupowania: karta = model
        $this->assertSame(11, $row['models']);
        $this->assertSame(['sklep-a.pl', 'sklep-b.pl', 'sklep-c.pl'], $row['enrichment_sites']);
        $this->assertSame('only', $row['enrichment_sites_mode']);
        $this->assertSame($sitesChangedAt->toIso8601String(), $row['enrichment_sites_updated_at']);
        $this->assertFalse($row['has_b2b_account']);
        $this->assertSame(11, $row['cards']);
        $this->assertSame(9, $row['described']);
        $this->assertSame([
            'price_list_sites' => 3,
            'manufacturer' => 2,
            'other' => 3,
            'b2b' => 1,
            'none' => 2,
        ], $row['sources']);
        // A-1 (strona cennika) i A-6 (inny sklep); producent i karty bez daty opisu nie
        $this->assertSame(2, $row['stale']);
        $this->assertSame(1, $row['queued']);
        $this->assertSame(1, $row['running']);
        $this->assertSame(1, $row['failed']);
        $this->assertSame(1, $row['manual']);
        $this->assertSame(['id' => $open->id, 'status' => 'running', 'total' => 5, 'done' => 2, 'failed' => 1], $row['batch']);
        $this->assertSame([
            ['host' => 'sklep-a.pl', 'position' => 0, 'on_search_sites' => true, 'indexed_pages' => 3, 'described_cards' => 2],
            ['host' => 'sklep-b.pl', 'position' => 1, 'on_search_sites' => false, 'indexed_pages' => 0, 'described_cards' => 1],
            ['host' => 'sklep-c.pl', 'position' => 2, 'on_search_sites' => true, 'indexed_pages' => 1, 'described_cards' => 0],
        ], $row['hosts']);
    }

    /**
     * Ręczny link (primary_source_kind „manual”) do strony producenta i opis sprzed pola primary_source_kind (13.09.2026:
     * same source_urls) liczą się od producenta po domenie adresu — tylko domenie przypisanej świadomie (konfiguracja,
     * „Strony wyszukiwarka” ręcznie), nie wykrytej automatem. Produkcja 07.10.2026: CEDERROTH 59 kart z ręcznym linkiem
     * do cederroth.com jako „inne”, Coba 559 opisów z coba.com jako „bez źródła”.
     */
    public function test_manual_link_and_legacy_source_urls_on_manufacturer_domain_count_as_manufacturer(): void
    {
        config(['enrichment.manufacturer_domains' => ['testowy' => ['producent-testowy.pl']]]);
        ManufacturerSite::remember('testowy', 'Testowy', ['producent-reczny.pl'], 'manual');
        ManufacturerSite::remember('testowy', 'Testowy', ['producent-wykryty.pl'], 'discovered');
        $list = $this->list('Testowy', ['enrichment_sites' => ['sklep-a.pl']]);

        // ręczny link: domena producenta z konfiguracji (www.) i przypisana ręcznie → producent
        $this->card($list, 'M-1', [], 'https://www.producent-testowy.pl/m-1', 'manual');
        $this->card($list, 'M-2', [], 'https://producent-reczny.pl/m-2', 'manual');
        // ręczny link do obcego sklepu → inne; do strony cennika → strona cennika (jak dotąd)
        $this->card($list, 'M-3', [], 'https://obcy-sklep.pl/m-3', 'manual');
        $this->card($list, 'M-4', [], 'https://sklep-a.pl/m-4', 'manual');
        // domena wykryta automatem to nie domena przypisana świadomie → inne
        $this->card($list, 'M-5', [], 'https://producent-wykryty.pl/m-5', 'manual');
        // ręczny link bez primary_source_url: adres z shop_source_url karty
        $this->card($list, 'M-6', [
            'shop_source_url' => 'https://producent-testowy.pl/m-6',
            'enrichment_payload' => ['primary_source_kind' => 'manual', 'source_urls' => ['https://obcy-sklep.pl/m-6']],
        ]);
        // opis bez zapisanego rodzaju: pierwszy adres z source_urls
        $this->card($list, 'L-1', ['enrichment_payload' => ['source_urls' => ['https://producent-testowy.pl/l-1', 'https://sklep-a.pl/l-1']]]);
        $this->card($list, 'L-2', ['enrichment_payload' => ['source_urls' => ['https://obcy-sklep.pl/l-2', 'https://producent-testowy.pl/l-2']]]);
        $this->card($list, 'L-3', ['enrichment_payload' => ['source_urls' => ['https://sklep-a.pl/l-3']]]);
        // bez source_urls i z pustą listą — jak dotąd „inne”
        $this->card($list, 'L-4', ['enrichment_payload' => ['description' => 'x']]);
        $this->card($list, 'L-5', ['enrichment_payload' => ['source_urls' => []]]);
        // rodzaj „shop” zapisany przy opisie nie jest przeliczany po domenie
        $this->card($list, 'S-1', [], 'https://producent-testowy.pl/s-1', 'shop');
        // opis z B2B wygrywa także z adresem producenta w source_urls; karta bez opisu zostaje „bez opisu”
        $b2b = $this->card($list, 'B-1', [
            'description' => 'Opis ze sklepu dostawcy B2B, dzianina nylonowa 13.',
            'enrichment_payload' => ['source_urls' => ['https://producent-testowy.pl/b-1']],
        ]);
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account()->id, 'remote_id' => 'B-1', 'product_id' => $b2b->id, 'remote_sku' => 'B-1',
            'remote_name' => 'Rękawice', 'description_hash' => sha1((string) $b2b->description),
        ]);
        $this->card($list, 'N-1', ['description' => null, 'enrichment_payload' => ['source_urls' => ['https://producent-testowy.pl/n-1']]]);

        $row = $this->getJson('/api/price-lists/files')->assertOk()->json('lists.0');

        $this->assertSame([
            'price_list_sites' => 2, // M-4, L-3
            'manufacturer' => 4, // M-1, M-2, M-6, L-1
            'other' => 6, // M-3, M-5, L-2, L-4, L-5, S-1
            'b2b' => 1,
            'none' => 1,
        ], $row['sources']);
        $this->assertSame(2, $row['hosts'][0]['described_cards']);
    }

    /** Marka z grupowaniem (Coba): modele = różne klucze modelu + karty bez klucza — tyle przebiegów modelu potrzebuje pełne pobranie. */
    public function test_files_count_models_for_brand_with_model_grouping(): void
    {
        $list = $this->list('Coba');
        $this->card($list, 'AF060001', ['name' => 'Orthomat Standard Szary 0.6m x 0.9m']);
        $this->card($list, 'AF060002', ['name' => 'Orthomat Standard Czarny 0.9m x 1.5m']);
        $this->card($list, 'CCLIP25', ['name' => 'Akcesoria Krata GRP - Uchwyt typu C - 25mm']);

        $row = $this->getJson('/api/price-lists/files')->assertOk()->json('lists.0');

        $this->assertSame(3, $row['cards']);
        $this->assertSame(2, $row['models']);
    }

    public function test_files_list_without_sites_and_with_b2b_account(): void
    {
        $list = $this->list('Konto i plik');
        $this->card($list, 'K-1', [], 'https://sklep-a.pl/k-1', 'shop');
        $this->account()->forceFill(['last_price_list_id' => $list->id])->save();

        $row = $this->getJson('/api/price-lists/files')->assertOk()->json('lists.0');

        $this->assertSame([], $row['enrichment_sites']);
        $this->assertSame('first', $row['enrichment_sites_mode']);
        $this->assertNull($row['enrichment_sites_updated_at']);
        $this->assertTrue($row['has_b2b_account']);
        $this->assertSame([], $row['hosts']);
        $this->assertSame(0, $row['stale']);
        $this->assertNull($row['batch']);
        $this->assertSame(1, $row['sources']['other']);
    }

    /**
     * Wpis wspólny z kontem B2B (Bolle na produkcji: product_ids = 414 kart konta, 1 karta z pliku) — zakładka liczy
     * i sprawdza tylko karty ze slotem pliku, bo tylko do nich stosują się strony cennika.
     */
    public function test_cards_from_last_b2b_update_without_file_slot_are_not_counted(): void
    {
        $list = $this->list('Wspólny', ['enrichment_sites' => ['sklep-a.pl']]);
        $this->card($list, 'P-1', [], 'https://sklep-a.pl/p-1', 'shop');
        $accountCard = Product::query()->create([
            'sku' => 'K-9', 'name' => 'Rękawice z konta', 'manufacturer' => 'Wspólny', 'description' => self::DESCRIPTION,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 0,
        ]);
        $list->forceFill(['product_ids' => [$accountCard->id]])->save();

        $row = $this->getJson('/api/price-lists/files')->assertOk()->json('lists.0');
        $this->assertSame(1, $row['cards']);

        $this->postJson("/api/price-lists/{$list->id}/site-check", ['product_id' => $accountCard->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');

        // link producenta i podpowiedzi „Sprawdź na karcie” — ten sam zbiór kart co zakładka
        $this->assertSame(['P-1'], array_column($this->getJson("/api/products?price_list={$list->id}&price_list_file=1")->assertOk()->json('data'), 'sku'));
        $this->assertCount(2, $this->getJson("/api/products?price_list={$list->id}")->assertOk()->json('data'));
    }

    /**
     * „Opisy sprzed zmiany stron”: opis z zapisanym odciskiem stron cennika porównujemy z bieżącym (przebieg trwający
     * w chwili zmiany pisze według starych stron, choć datą jest nowszy); bez odcisku — datą opisu.
     */
    public function test_stale_compares_hosts_fingerprint_before_the_date(): void
    {
        $changedAt = Carbon::parse('2026-10-05 12:00:00');
        $list = $this->list('Odcisk', [
            'enrichment_sites' => ['sklep-a.pl'],
            'enrichment_sites_updated_at' => $changedAt,
        ]);
        $current = $list->enrichmentHostsSha1();
        $after = $changedAt->copy()->addHour();
        $this->card($list, 'S-1', ['enriched_at' => $after, 'enrichment_payload' => [
            'primary_source_url' => 'https://inny.pl/s-1', 'primary_source_kind' => 'shop',
            'price_list_sources' => ['price_list_id' => $list->id, 'mode' => 'first', 'hosts_sha1' => sha1('first'."\n".'stary.pl')],
        ]]);
        $this->card($list, 'S-2', ['enriched_at' => $changedAt->copy()->subDay(), 'enrichment_payload' => [
            'primary_source_url' => 'https://sklep-a.pl/s-2', 'primary_source_kind' => 'shop',
            'price_list_sources' => ['price_list_id' => $list->id, 'mode' => 'first', 'hosts_sha1' => $current],
        ]]);
        $this->card($list, 'S-3', ['enriched_at' => $changedAt->copy()->subDay()], 'https://inny.pl/s-3', 'shop');

        $row = $this->getJson('/api/price-lists/files')->assertOk()->json('lists.0');

        // S-1: nowsza data, ale stare strony; S-2: starsza data, ale bieżące strony; S-3: bez odcisku, sprzed zmiany
        $this->assertSame(2, $row['stale']);
    }

    public function test_files_and_search_sites_routes_do_not_hit_price_list_binding(): void
    {
        $list = $this->list('Testowy');

        $this->getJson('/api/price-lists/files')->assertOk()->assertExactJson(['lists' => []]);
        $this->getJson('/api/price-lists/search-sites')->assertOk()->assertJsonStructure(['sites']);
        $this->getJson("/api/price-lists/{$list->id}")->assertOk()->assertJsonPath('id', $list->id);
    }

    public function test_permissions_view_for_files_import_for_search_sites_and_site_check(): void
    {
        $list = $this->list('Testowy', ['enrichment_sites' => ['sklep-a.pl']]);
        $card = $this->card($list, 'P-1');

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/price-lists/files')->assertOk();
        $this->getJson('/api/price-lists/search-sites')->assertForbidden();
        $this->postJson("/api/price-lists/{$list->id}/site-check", ['product_id' => $card->id])->assertForbidden();

        // bez przypisanej roli (uprawnień): bez price_lists.view
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/price-lists/files')->assertForbidden();
    }

    public function test_search_sites_returns_only_picker_fields_and_is_cached(): void
    {
        $this->page('sklep-a.pl', 'https://sklep-a.pl/1');
        $this->page('www.sklep-a.pl', 'https://www.sklep-a.pl/2');

        $sites = $this->getJson('/api/price-lists/search-sites')->assertOk()->json('sites');
        $row = collect($sites)->firstWhere('host', 'sklep-a.pl');

        $this->assertNotNull($row);
        $this->assertSame(['host', 'links', 'manufacturers', 'priority', 'sources'], array_keys($row));
        $this->assertSame(2, $row['links']);
        $this->assertSame([], $row['manufacturers']);
        $this->assertNull($row['priority']);
        $this->assertSame(['indeks'], $row['sources']);
        foreach ($sites as $site) {
            $this->assertSame(['host', 'links', 'manufacturers', 'priority', 'sources'], array_keys($site));
        }

        // pamięć 10 min: nowa strona w indeksie nie zmienia odpowiedzi od razu
        $this->page('sklep-a.pl', 'https://sklep-a.pl/3');
        $again = collect($this->getJson('/api/price-lists/search-sites')->assertOk()->json('sites'))->firstWhere('host', 'sklep-a.pl');
        $this->assertSame(2, $again['links']);
    }

    public function test_site_check_returns_local_index_hits_with_position_and_code(): void
    {
        $list = $this->list('Testowy', ['enrichment_sites' => ['sklep-a.pl', 'sklep-b.pl']]);
        $card = $this->card($list, 'RK-2040');

        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('catalogHitsOnHosts')
            ->once()
            ->withArgs(static fn (Product $product, array $hosts): bool => $product->id === $card->id
                && $hosts === ['sklep-a.pl', 'sklep-b.pl'])
            ->andReturn([
                ['url' => 'https://www.sklep-b.pl/rekawice/rk-2040-nitryl', 'title' => 'Rękawice', 'snippet' => ''],
                ['url' => 'https://sklep-a.pl/rekawice-nitrylowe', 'title' => 'Rękawice RK 2040 nitrylowe', 'snippet' => ''],
                // inny wariant: kod karty jako początek dłuższego kodu
                ['url' => 'https://sklep-a.pl/rk-2040x', 'title' => 'Rękawice', 'snippet' => ''],
            ]);
        $search->shouldNotReceive('searchOnHosts');
        $this->app->instance(HybridWebSearchService::class, $search);

        $this->postJson("/api/price-lists/{$list->id}/site-check", ['product_id' => $card->id])
            ->assertOk()
            ->assertExactJson([
                'product' => ['id' => $card->id, 'sku' => 'RK-2040', 'name' => 'Rękawice testowe RK-2040'],
                'hits' => [
                    ['url' => 'https://www.sklep-b.pl/rekawice/rk-2040-nitryl', 'title' => 'Rękawice', 'host' => 'sklep-b.pl', 'position' => 1, 'coded' => true],
                    ['url' => 'https://sklep-a.pl/rekawice-nitrylowe', 'title' => 'Rękawice RK 2040 nitrylowe', 'host' => 'sklep-a.pl', 'position' => 0, 'coded' => true],
                    ['url' => 'https://sklep-a.pl/rk-2040x', 'title' => 'Rękawice', 'host' => 'sklep-a.pl', 'position' => 0, 'coded' => false],
                ],
            ]);
    }

    public function test_site_check_rejects_card_of_other_list_and_list_without_sites(): void
    {
        $list = $this->list('Testowy', ['enrichment_sites' => ['sklep-a.pl']]);
        $other = $this->list('Inny', ['enrichment_sites' => ['sklep-a.pl']]);
        $foreign = $this->card($other, 'O-1');
        $bare = $this->list('Bez stron');
        $own = $this->card($bare, 'B-1');

        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldNotReceive('catalogHitsOnHosts');
        $this->app->instance(HybridWebSearchService::class, $search);

        $this->postJson("/api/price-lists/{$list->id}/site-check", ['product_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
        $this->postJson("/api/price-lists/{$bare->id}/site-check", ['product_id' => $own->id])->assertStatus(422);
        $this->postJson("/api/price-lists/{$list->id}/site-check", ['product_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
        $this->postJson("/api/price-lists/{$list->id}/site-check", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function list(string $manufacturer, array $attributes = []): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer,
            'version' => '2026',
            'original_filename' => 'plik.xlsx',
            'rows_total' => 1,
            'products_created' => 1,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [],
            ...$attributes,
        ])->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(PriceList $list, string $sku, array $attributes = [], ?string $sourceUrl = null, ?string $sourceKind = null): Product
    {
        $card = Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice testowe '.$sku,
            'manufacturer' => (string) $list->manufacturer,
            'description' => self::DESCRIPTION,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            'enrichment_payload' => $sourceUrl !== null
                ? ['primary_source_url' => $sourceUrl, 'primary_source_kind' => $sourceKind, 'description' => 'x']
                : null,
            ...$attributes,
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $list->id,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);

        return $card;
    }

    private function page(string $host, string $url): void
    {
        CatalogPage::query()->create([
            'host' => $host,
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'title' => 'Karta',
            'haystack' => mb_strtolower($url),
            'last_seen_at' => now(),
        ]);
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(['username' => 'jan'], [
            'password' => 'sekret',
            'sites' => ['b2b.anro.net.pl'],
            'connector' => 'anro',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }
}
