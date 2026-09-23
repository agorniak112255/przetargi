<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Support\ProductPriceChangeResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductPriceChangeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::forget('nbp.table_a.rates');
        Http::fake([
            'api.nbp.pl/*' => Http::response([[
                'effectiveDate' => '2026-08-27',
                'rates' => [['code' => 'EUR', 'mid' => 4.0]],
            ]]),
        ]);
    }

    public function test_first_history_row_is_not_a_change(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('NEW-1', 40.8, 36.72);
        $this->history($product, 40.8, 36.72, 'b2b:anro', '2026-09-10 10:00:00');

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.last_price_change', null);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('last_price_change', null);

        $this->getJson('/api/products/'.$product->id.'/price-history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source_label', 'Anro B2B')
            ->assertJsonPath('data.0.purchase_old', null)
            ->assertJsonPath('data.0.catalog_old', null)
            ->assertJsonPath('data.0.purchase_pct', null)
            ->assertJsonPath('data.0.catalog_pct', null);
    }

    public function test_b2b_sync_change_is_labeled_with_connector_name(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('ANRO-1', 40.8, 39.9);
        $this->history($product, 40.8, 36.72, 'b2b:anro', '2026-09-01 08:00:00');
        $this->history($product, 40.8, 39.9, 'b2b:anro', '2026-09-15 02:14:00');
        // Ta sama cena ponownie — nie jest nowszą zmianą.
        $this->history($product, 40.8, 39.9, 'b2b:anro', '2026-09-16 02:14:00');

        $expected = [
            'at' => '2026-09-15T02:14:00.000000Z',
            'currency' => null,
            'source' => 'b2b:anro',
            'source_label' => 'Anro B2B',
            'purchase_old' => 36.72,
            'purchase_new' => 39.9,
            'catalog_old' => 40.8,
            'catalog_new' => 40.8,
            'pct' => 8.66,
            'pct_basis' => 'purchase',
            'purchase_pct' => 8.66,
            'catalog_pct' => 0,
        ];

        $list = $this->getJson('/api/products')->assertOk();
        $this->assertEquals($expected, $list->json('data.0.last_price_change'));

        $detail = $this->getJson('/api/products/'.$product->id)->assertOk();
        $this->assertEquals($expected, $detail->json('last_price_change'));

        $this->getJson('/api/products/'.$product->id.'/price-history')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.source_label', 'Anro B2B')
            ->assertJsonPath('data.0.purchase_pct', 0)
            ->assertJsonPath('data.1.purchase_price', '39.90')
            ->assertJsonPath('data.1.purchase_old', 36.72)
            ->assertJsonPath('data.1.catalog_old', 40.8)
            ->assertJsonPath('data.1.purchase_pct', 8.66)
            ->assertJsonPath('data.1.catalog_pct', 0)
            ->assertJsonPath('data.1.first_in_source', false)
            ->assertJsonPath('data.2.purchase_old', null)
            ->assertJsonPath('data.2.first_in_source', true);
    }

    public function test_rows_from_different_sources_are_not_compared(): void
    {
        // 23.09.2026: konto producenta Bolle w EUR i dystrybutor Procera w PLN na jednej karcie — skok ~+393%
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('BOLLE-1', 34.17, 34.17);
        $this->history($product, 8.0, 6.93, 'b2b:bolle', '2026-09-10 02:00:00', null, 'EUR');
        $this->history($product, 34.17, 34.17, 'b2b:procera', '2026-09-11 02:00:00', null, 'PLN');
        $this->history($product, 8.0, 6.93, 'b2b:bolle', '2026-09-12 02:00:00', null, 'EUR');

        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.last_price_change', null);
        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('last_price_change', null)
            ->assertJsonPath('price_change_percent', null);
        $this->getJson('/api/products/'.$product->id.'/price-history')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.currency', 'EUR')
            ->assertJsonPath('data.0.purchase_old', 6.93)
            ->assertJsonPath('data.0.purchase_pct', 0)
            ->assertJsonPath('data.1.currency', 'PLN')
            ->assertJsonPath('data.1.purchase_old', null)
            ->assertJsonPath('data.1.first_in_source', true)
            ->assertJsonPath('data.2.first_in_source', true);

        // prawdziwa zmiana u dystrybutora — względem jego poprzedniej ceny, nie ceny Bolle
        $this->history($product, 36.0, 36.0, 'b2b:procera', '2026-09-13 02:00:00', null, 'PLN');

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('last_price_change.source', 'b2b:procera')
            ->assertJsonPath('last_price_change.currency', 'PLN')
            ->assertJsonPath('last_price_change.purchase_old', 34.17)
            ->assertJsonPath('last_price_change.purchase_new', 36)
            ->assertJsonPath('last_price_change.pct', 5.36)
            ->assertJsonPath('price_change_percent', 5.4);
    }

    public function test_latest_change_is_the_newest_across_sources(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('MIX-1', 20, 12);
        $this->history($product, 10, 5, 'price_list_import', '2026-09-01 08:00:00');
        $this->history($product, 20, 11, 'b2b:anro', '2026-09-02 08:00:00');
        $this->history($product, 20, 12, 'b2b:anro', '2026-09-03 08:00:00');
        // rabat z okna cennika zmienia cenę tego samego slotu pliku co import
        $this->history($product, 10, 4, 'price_list_discount', '2026-09-04 08:00:00');

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('last_price_change.source', 'price_list_discount')
            ->assertJsonPath('last_price_change.purchase_old', 5)
            ->assertJsonPath('last_price_change.purchase_new', 4)
            ->assertJsonPath('last_price_change.pct', -20);
    }

    public function test_currency_change_within_source_is_not_compared_and_unknown_currency_is(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('CUR-1', 43, 43);
        // wiersz sprzed zapisu waluty (null) porównywany z pierwszym z walutą — to samo źródło
        $this->history($product, 10, 9, 'b2b:anro', '2026-09-01 08:00:00');
        $this->history($product, 10, 10, 'b2b:anro', '2026-09-02 08:00:00', null, 'EUR');
        $this->history($product, 43, 43, 'b2b:anro', '2026-09-03 08:00:00', null, 'PLN');

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('last_price_change.at', '2026-09-02T08:00:00.000000Z')
            ->assertJsonPath('last_price_change.purchase_old', 9)
            ->assertJsonPath('last_price_change.purchase_new', 10);
        $this->getJson('/api/products/'.$product->id.'/price-history')
            ->assertOk()
            ->assertJsonPath('data.0.purchase_old', null)
            ->assertJsonPath('data.0.purchase_pct', null)
            ->assertJsonPath('data.0.first_in_source', false);
    }

    public function test_history_limit_keeps_previous_value_of_same_source_outside_the_page(): void
    {
        $product = $this->product('LIM-1', 12, 12);
        $this->history($product, 10, 10, 'price_list_import', '2026-09-01 08:00:00');
        $this->history($product, 50, 50, 'b2b:anro', '2026-09-02 08:00:00');
        $this->history($product, 50, 50, 'b2b:anro', '2026-09-03 08:00:00');
        $this->history($product, 12, 12, 'price_list_import', '2026-09-04 08:00:00');

        $rows = app(ProductPriceChangeResolver::class)->history((int) $product->id, 2);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-04T08:00:00.000000Z', $rows[0]['created_at']);
        $this->assertEquals(10, $rows[0]['purchase_old']);
        $this->assertEquals(20, $rows[0]['purchase_pct']);
        $this->assertFalse($rows[0]['first_in_source']);
        $this->assertEquals(50, $rows[1]['purchase_old']);
        $this->assertFalse($rows[1]['first_in_source']);
    }

    public function test_price_list_import_label_uses_manufacturer_and_version(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('UVEX-9', 55, 30);
        $old = PriceList::query()->create(['manufacturer' => 'Uvex', 'version' => '2026-01']);
        $new = PriceList::query()->create(['manufacturer' => 'Uvex', 'version' => '2026-09']);
        $this->history($product, 50, 30, 'price_list_import', '2026-01-05 09:00:00', $old->id);
        $this->history($product, 55, 30, 'price_list_import', '2026-09-05 09:00:00', $new->id);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('last_price_change.source_label', 'Cennik Uvex 2026-09')
            ->assertJsonPath('last_price_change.catalog_old', 50)
            ->assertJsonPath('last_price_change.catalog_new', 55)
            // Zakup bez zmian — procent od katalogu, nie mylące 0%.
            ->assertJsonPath('last_price_change.pct', 10)
            ->assertJsonPath('last_price_change.pct_basis', 'catalog');

        $this->getJson('/api/products/'.$product->id.'/price-history')
            ->assertOk()
            ->assertJsonPath('data.0.source_label', 'Cennik Uvex 2026-09')
            ->assertJsonPath('data.0.price_list.manufacturer', 'Uvex')
            ->assertJsonPath('data.0.price_list.version', '2026-09')
            ->assertJsonPath('data.0.catalog_pct', 10)
            ->assertJsonPath('data.1.source_label', 'Cennik Uvex 2026-01');

        $new->delete();

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('last_price_change.source_label', 'Cennik (usunięty)');
    }

    public function test_list_computes_changes_without_query_per_product(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $list = PriceList::query()->create(['manufacturer' => 'Portwest', 'version' => 'v1']);
        for ($i = 1; $i <= 3; $i++) {
            $this->product('SOLO-'.$i, 10, 5);
        }
        for ($i = 1; $i <= 12; $i++) {
            $product = $this->product('CHG-'.$i, 10 + $i, 5 + $i);
            $this->history($product, 10, 5, 'price_list_import', '2026-09-01 08:00:00', $list->id);
            $this->history($product, 10 + $i, 5 + $i, $i % 2 === 0 ? 'b2b:anro' : 'price_list_import', '2026-09-10 08:00:00', $i % 2 === 0 ? null : $list->id);
        }

        /** @var list<string> $log */
        $log = [];
        $countQueries = function () use (&$log): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/products?per_page=50')->assertOk();
            $log = array_map(static fn (array $q): string => $q['query'], DB::getQueryLog());
            DB::disableQueryLog();

            return count($log);
        };

        // Pierwsze żądanie rozgrzewa pamięć podręczną kursów NBP — nie liczymy go.
        $countQueries();
        $withChanges = $countQueries();
        $withChangesLog = $log;
        ProductPriceHistory::query()->delete();
        $withoutHistory = $countQueries();

        // Zmiany cen dokładają stałą liczbę zapytań (historia + cenniki), nie jedno na produkt.
        $this->assertLessThanOrEqual($withoutHistory + 1, $withChanges, implode("\n", $withChangesLog));
        $historyQueries = array_filter($withChangesLog, static fn (string $q): bool => str_contains($q, 'product_price_history'));
        $this->assertCount(1, $historyQueries, implode("\n", $withChangesLog));

        $response = $this->getJson('/api/products?per_page=50')->assertOk();
        foreach ($response->json('data') as $row) {
            $this->assertArrayHasKey('last_price_change', $row);
            $this->assertNull($row['last_price_change']);
        }
    }

    public function test_list_rows_mix_changed_and_unchanged_products(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $changed = $this->product('A-CHG', 12, 6);
        $this->history($changed, 10, 5, 'b2b_api', '2026-09-01 08:00:00');
        $this->history($changed, 12, 6, 'b2b_api', '2026-09-02 08:00:00');
        $this->product('B-NONE', 10, 5);

        $response = $this->getJson('/api/products?sort=sku')->assertOk();
        $this->assertSame('A-CHG', $response->json('data.0.sku'));
        $this->assertSame('B2B', $response->json('data.0.last_price_change.source_label'));
        $this->assertEquals(20, $response->json('data.0.last_price_change.pct'));
        $this->assertSame('B-NONE', $response->json('data.1.sku'));
        $this->assertNull($response->json('data.1.last_price_change'));
    }

    public function test_price_endpoints_still_require_products_view(): void
    {
        $product = $this->product('PERM-1', 10, 5);
        $this->history($product, 10, 5, 'b2b:anro', '2026-09-01 08:00:00');
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products')->assertForbidden();
        $this->getJson('/api/products/'.$product->id)->assertForbidden();
        $this->getJson('/api/products/'.$product->id.'/price-history')->assertForbidden();
    }

    private function product(string $sku, float $catalog, float $purchase): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Produkt '.$sku,
            'manufacturer' => 'X',
            'catalog_price_net' => $catalog,
            'purchase_price' => $purchase,
            'stock' => 1,
        ]);
    }

    private function history(Product $product, float $catalog, float $purchase, string $source, string $at, ?int $priceListId = null, ?string $currency = null): void
    {
        $row = new ProductPriceHistory;
        $row->forceFill([
            'product_id' => $product->id,
            'price_list_id' => $priceListId,
            'catalog_price_net' => $catalog,
            'purchase_price' => $purchase,
            'currency' => $currency,
            'source' => $source,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ])->save();
    }
}
