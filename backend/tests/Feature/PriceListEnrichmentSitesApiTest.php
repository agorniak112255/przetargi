<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PATCH /price-lists/{id}: źródła opisów cennika z pliku (Cenniki → „Z pliku”, kontrakt z planu 05.10.2026).
 */
final class PriceListEnrichmentSitesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_saves_hosts_in_given_order_without_duplicates_and_mode(): void
    {
        $list = $this->fileList();

        $this->patchJson("/api/price-lists/{$list->id}", [
            'enrichment_sites' => ['https://www.Sklep-B.pl/katalog/rekawice', 'sklep-a.pl', '', 'sklep-b.pl', null],
            'enrichment_sites_mode' => PriceList::MODE_ONLY,
        ])
            ->assertOk()
            ->assertJsonPath('enrichment_sites', ['sklep-b.pl', 'sklep-a.pl'])
            ->assertJsonPath('enrichment_sites_mode', 'only')
            ->assertJsonPath('price_list.enrichment_sites', ['sklep-b.pl', 'sklep-a.pl'])
            ->assertJsonPath('price_list.enrichment_sites_mode', 'only');

        $list->refresh();
        $this->assertSame(['sklep-b.pl', 'sklep-a.pl'], $list->enrichment_sites);
        $this->assertSame(PriceList::MODE_ONLY, $list->enrichment_sites_mode);
        $this->assertNotNull($list->enrichment_sites_updated_at);
    }

    public function test_more_than_twenty_sites_is_rejected(): void
    {
        $list = $this->fileList();
        $sites = array_map(static fn (int $i): string => "sklep{$i}.pl", range(1, 21));

        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => $sites])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enrichment_sites');

        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => array_slice($sites, 0, 20)])
            ->assertOk()
            ->assertJsonCount(20, 'enrichment_sites');
    }

    public function test_invalid_host_and_mode_are_rejected(): void
    {
        $list = $this->fileList();

        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => ['to nie jest domena']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enrichment_sites');
        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites_mode' => 'najpierw'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enrichment_sites_mode');
        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => 'sklep-a.pl'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enrichment_sites');

        $this->assertNull($list->fresh()->enrichment_sites);
    }

    public function test_blocked_hosts_and_own_shop_with_subdomains_are_rejected(): void
    {
        config([
            'enrichment.blocked_source_hosts' => ['supon.rzeszow.pl', 'outlet.pros.pl'],
            'prestashop.shop_url' => 'https://www.moj-sklep.pl',
        ]);
        $list = $this->fileList();

        foreach (['supon.rzeszow.pl', 'https://sklep.supon.rzeszow.pl/x', 'outlet.pros.pl', 'moj-sklep.pl', 'b2b.moj-sklep.pl'] as $site) {
            $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => ['sklep-a.pl', $site]])
                ->assertStatus(422)
                ->assertJsonValidationErrors('enrichment_sites');
        }
        $this->assertNull($list->fresh()->enrichment_sites);

        // domena nadrzędna wykluczonej subdomeny to inna strona (pros.pl ≠ outlet.pros.pl)
        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => ['pros.pl']])
            ->assertOk()
            ->assertJsonPath('enrichment_sites', ['pros.pl']);
    }

    public function test_price_list_without_file_cards_rejects_sites_but_may_clear_them(): void
    {
        $list = $this->list();

        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => ['sklep-a.pl']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enrichment_sites');
        $this->assertNull($list->fresh()->enrichment_sites);

        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => []])->assertOk();
        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => null])
            ->assertOk()
            ->assertJsonPath('enrichment_sites', []);
        // sam tryb bez stron niczego nie zmienia w źródłach — znacznik zmiany zostaje pusty
        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites_mode' => 'only'])->assertOk();
        $this->assertNull($list->fresh()->enrichment_sites_updated_at);
    }

    public function test_updated_at_moves_only_on_real_change_of_hosts_order_or_mode(): void
    {
        $list = $this->fileList();

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => ['sklep-a.pl', 'sklep-b.pl']])->assertOk();
        $this->assertSame('2026-10-05 10:00:00', $list->fresh()->enrichment_sites_updated_at?->format('Y-m-d H:i:s'));

        // te same hosty innym zapisem, tryb bez zmian, sama wersja — bez zmiany znacznika
        Carbon::setTestNow('2026-10-05 11:00:00');
        $this->patchJson("/api/price-lists/{$list->id}", [
            'enrichment_sites' => ['https://www.sklep-a.pl/', 'SKLEP-B.pl'],
            'enrichment_sites_mode' => 'first',
        ])->assertOk();
        $this->patchJson("/api/price-lists/{$list->id}", ['version' => '2026-11'])->assertOk();
        $this->assertSame('2026-10-05 10:00:00', $list->fresh()->enrichment_sites_updated_at?->format('Y-m-d H:i:s'));

        // kolejność to ważność
        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => ['sklep-b.pl', 'sklep-a.pl']])->assertOk();
        $this->assertSame('2026-10-05 12:00:00', $list->fresh()->enrichment_sites_updated_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-10-05 13:00:00');
        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites_mode' => 'only'])
            ->assertOk()
            ->assertJsonPath('enrichment_sites_updated_at', Carbon::parse('2026-10-05 13:00:00')->toIso8601String());
        $this->assertSame(['sklep-b.pl', 'sklep-a.pl'], $list->fresh()->enrichment_sites);

        Carbon::setTestNow('2026-10-05 14:00:00');
        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => null])->assertOk();
        $fresh = $list->fresh();
        $this->assertNull($fresh->enrichment_sites);
        $this->assertSame('2026-10-05 14:00:00', $fresh->enrichment_sites_updated_at?->format('Y-m-d H:i:s'));
    }

    public function test_empty_request_still_has_no_fields(): void
    {
        $list = $this->fileList();

        $this->patchJson("/api/price-lists/{$list->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Brak pól do aktualizacji.');
    }

    public function test_index_and_show_carry_new_fields(): void
    {
        $list = $this->fileList();
        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => ['sklep-a.pl'], 'enrichment_sites_mode' => 'only'])->assertOk();

        $this->getJson('/api/price-lists')
            ->assertOk()
            ->assertJsonPath('0.enrichment_sites', ['sklep-a.pl'])
            ->assertJsonPath('0.enrichment_sites_mode', 'only');
        $this->getJson("/api/price-lists/{$list->id}")
            ->assertOk()
            ->assertJsonPath('enrichment_sites', ['sklep-a.pl'])
            ->assertJsonPath('enrichment_sites_mode', 'only');
        $this->assertNotNull($this->getJson("/api/price-lists/{$list->id}")->json('enrichment_sites_updated_at'));
    }

    public function test_view_permission_alone_cannot_change_sites(): void
    {
        $list = $this->fileList();
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->patchJson("/api/price-lists/{$list->id}", ['enrichment_sites' => ['sklep-a.pl']])->assertForbidden();
        $this->assertNull($list->fresh()->enrichment_sites);
    }

    private function list(): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => 'Testowy',
            'version' => '2026',
            'original_filename' => 'testowy.xlsx',
            'rows_total' => 1,
            'products_created' => 1,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [],
        ]);
    }

    private function fileList(): PriceList
    {
        $list = $this->list();
        $card = Product::query()->create([
            'sku' => 'T-1',
            'name' => 'Rękawice testowe',
            'manufacturer' => 'Testowy',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
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

        return $list->fresh();
    }
}
