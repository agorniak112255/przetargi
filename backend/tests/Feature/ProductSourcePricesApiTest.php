<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Karta produktu pokazuje ceny osobno dla każdego źródła (plik, konta B2B) i zaznacza, z którego slotu pochodzi
 * cena karty.
 */
final class ProductSourcePricesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::fake([
            'api.nbp.pl/*' => Http::response([[
                'effectiveDate' => '2026-09-15',
                'rates' => [['code' => 'EUR', 'mid' => 4.0]],
            ]]),
        ]);
    }

    public function test_show_lists_source_prices_effective_first_then_b2b_then_file(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('SRC-1');
        $list = PriceList::query()->create(['manufacturer' => 'Ansell', 'version' => '2026-09']);
        $anro = B2bAccount::query()->create([
            'username' => 'login-anro-tajny',
            'password' => 'haslo-anro-tajne',
            'sites' => ['b2b.anro.net.pl'],
            'connector' => 'anro',
        ]);
        $noConnector = B2bAccount::query()->create([
            'username' => 'login-inny-tajny',
            'password' => 'haslo-inne-tajne',
            'sites' => ['b2b.example.pl'],
            'connector' => null,
        ]);

        $this->slot($product, ProductSourcePrice::SOURCE_FILE, [
            'price_list_id' => $list->id,
            'catalog_price_net' => 50,
            'purchase_price' => 40,
            'discount_percent' => 20,
            'currency' => 'PLN',
            'checked_at' => Carbon::parse('2026-09-01 08:00:00'),
            'migrated' => true,
        ]);
        $this->slot($product, ProductSourcePrice::b2bKey($noConnector->id), [
            'b2b_account_id' => $noConnector->id,
            'catalog_price_net' => 49,
            'purchase_price' => 39,
            'currency' => 'EUR',
            'checked_at' => Carbon::parse('2026-09-05 08:00:00'),
        ]);
        $this->slot($product, ProductSourcePrice::b2bKey($anro->id), [
            'b2b_account_id' => $anro->id,
            'catalog_price_net' => 48,
            'purchase_price' => 36,
            'discount_percent' => 25,
            'currency' => 'PLN',
            'checked_at' => Carbon::parse('2026-09-10 08:00:00'),
        ]);

        $response = $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonCount(3, 'source_prices')
            // najświeżej sprawdzone B2B ustala cenę karty
            ->assertJsonPath('source_prices.0.source_key', 'b2b:'.$anro->id)
            ->assertJsonPath('source_prices.0.source_label', 'B2B Anro')
            ->assertJsonPath('source_prices.0.is_effective', true)
            ->assertJsonPath('source_prices.0.catalog_price_net', '48.00')
            ->assertJsonPath('source_prices.0.purchase_price', '36.00')
            ->assertJsonPath('source_prices.0.discount_percent', '25.00')
            ->assertJsonPath('source_prices.0.currency', 'PLN')
            ->assertJsonPath('source_prices.0.checked_at', Carbon::parse('2026-09-10 08:00:00')->toISOString())
            ->assertJsonPath('source_prices.0.migrated', false)
            // konto bez łącznika: pierwsza witryna, nie login
            ->assertJsonPath('source_prices.1.source_key', 'b2b:'.$noConnector->id)
            ->assertJsonPath('source_prices.1.source_label', 'B2B b2b.example.pl')
            ->assertJsonPath('source_prices.1.is_effective', false)
            ->assertJsonPath('source_prices.1.discount_percent', null)
            ->assertJsonPath('source_prices.2.source_key', 'file')
            ->assertJsonPath('source_prices.2.source_label', 'Cennik z pliku · Ansell 2026-09')
            ->assertJsonPath('source_prices.2.is_effective', false)
            ->assertJsonPath('source_prices.2.migrated', true);

        $body = (string) $response->getContent();
        foreach (['login-anro-tajny', 'haslo-anro-tajne', 'login-inny-tajny', 'haslo-inne-tajne'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_file_slot_is_effective_without_b2b_and_label_without_price_list(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('SRC-2');
        $this->slot($product, ProductSourcePrice::SOURCE_FILE, [
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
        ]);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonCount(1, 'source_prices')
            ->assertJsonPath('source_prices.0.source_label', 'Cennik z pliku')
            ->assertJsonPath('source_prices.0.is_effective', true);
    }

    public function test_show_without_slots_returns_empty_list(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('SRC-3');

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('source_prices', []);
    }

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice '.$sku,
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 50,
            'purchase_price' => 40,
            'stock' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function slot(Product $product, string $sourceKey, array $values): ProductSourcePrice
    {
        return ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => $sourceKey,
            ...$values,
        ]);
    }
}
