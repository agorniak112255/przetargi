<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RegisterManufacturerCatalogJob;
use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Pricing\ProductEffectivePrice;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PriceListUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_update_manufacturer_propagates_to_products(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $p1 = Product::query()->create([
            'sku' => 'PL-1',
            'name' => 'Produkt 1',
            'manufacturer' => 'Zly Producent',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        $p2 = Product::query()->create([
            'sku' => 'PL-2',
            'name' => 'Produkt 2',
            'manufacturer' => 'Zly Producent',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 1,
        ]);

        $list = PriceList::query()->create([
            'manufacturer' => 'Zly Producent',
            'version' => '2026',
            'original_filename' => 'c.xlsx',
            'rows_total' => 2,
            'products_created' => 2,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [$p1->id, $p2->id],
        ]);

        Bus::fake([RegisterManufacturerCatalogJob::class]);

        $this->patchJson("/api/price-lists/{$list->id}", [
            'manufacturer' => 'ATG',
            'version' => '2026-07',
        ])
            ->assertOk()
            ->assertJsonPath('products_updated', 2)
            ->assertJsonPath('price_list.manufacturer', 'ATG')
            ->assertJsonPath('price_list.version', '2026-07');

        $this->assertDatabaseHas('products', ['id' => $p1->id, 'manufacturer' => 'ATG']);
        $this->assertDatabaseHas('products', ['id' => $p2->id, 'manufacturer' => 'ATG']);
        Bus::assertDispatched(RegisterManufacturerCatalogJob::class);
    }

    public function test_suggested_prices_flag_recomputes_cards_so_distributor_purchase_price_wins(): void
    {
        // decyzja użytkownika 23.09.2026: „ATG-sugerowany.xlsx” to ceny sugerowane, ATG kupowane tylko przez Ardon
        $admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($admin);
        $card = Product::query()->create([
            'sku' => '19-007', 'name' => 'Rękawice MaxiFlex', 'manufacturer' => 'ATG',
            'catalog_price_net' => 17.88, 'purchase_price' => 11.04, 'discount_percent' => 0, 'currency' => 'PLN',
        ]);
        $list = PriceList::query()->create([
            'manufacturer' => 'ATG', 'manufacturer_key' => 'atg', 'version' => '2026-09', 'product_ids' => [$card->id],
        ]);
        $ardon = B2bAccount::query()->create([
            'username' => 'ardon', 'password' => 'sekret', 'sites' => ['b2b.ardon.pl'], 'connector' => 'ardon',
            'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $prices = app(ProductEffectivePrice::class);
        $prices->saveSlot($card, ProductSourcePrice::SOURCE_FILE, [
            'price_list_id' => $list->id, 'catalog_price_net' => 17.88, 'purchase_price' => 17.88, 'currency' => 'PLN',
            'checked_at' => Carbon::parse('2026-09-18 13:55'),
        ]);
        $prices->saveSlot($card, ProductSourcePrice::b2bKey($ardon->id), [
            'b2b_account_id' => $ardon->id, 'catalog_price_net' => 17.88, 'purchase_price' => 11.04, 'currency' => 'PLN',
            'checked_at' => Carbon::parse('2026-09-21 15:27'),
        ]);
        // plik producenta ma pierwszeństwo przed dystrybutorem — cena sugerowana wypiera cenę zakupu
        $this->assertSame('17.88', $card->fresh()->purchase_price);

        $this->patchJson("/api/price-lists/{$list->id}", ['suggested_prices' => true])
            ->assertOk()
            ->assertJsonPath('price_list.suggested_prices', true)
            ->assertJsonPath('prices_changed', 1);
        $this->assertSame('11.04', $card->fresh()->purchase_price);

        // odznaczenie przywraca pierwszeństwo cennika producenta
        $this->patchJson("/api/price-lists/{$list->id}", ['suggested_prices' => false])
            ->assertOk()
            ->assertJsonPath('prices_changed', 1);
        $this->assertSame('17.88', $card->fresh()->purchase_price);
    }
}
