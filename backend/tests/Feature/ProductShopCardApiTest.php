<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductShopCard;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Karta produktu pokazuje dane z karty wyrobu u dostawcy osobno dla każdego konta B2B — to nie jest opis wyrobu,
 * tylko tabelka ze sklepu z zachowaną proweniencją (konto, adres karty, czas pobrania).
 */
final class ProductShopCardApiTest extends TestCase
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

    public function test_show_lists_shop_cards_newest_first_with_account_label(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('SHOP-1');
        $anro = $this->account('anro', 'b2b.anro.net.pl');
        $noConnector = $this->account(null, 'b2b.example.pl');

        // starsza karta zapisana pierwsza — o kolejności decyduje synced_at, nie id
        ProductShopCard::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $noConnector->id,
            'source_url' => 'https://b2b.example.pl/products/42',
            'fields' => [
                ['section' => '', 'rows' => [['name' => 'Kolor', 'value' => 'czarny']]],
            ],
            'synced_at' => Carbon::parse('2026-09-10 08:00:00'),
        ]);
        ProductShopCard::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $anro->id,
            'source_url' => 'https://b2b.anro.net.pl/products/15937',
            'fields' => [
                ['section' => 'Informacje handlowe', 'rows' => [
                    ['name' => 'Kod towaru', 'value' => '67E/2-LS PC'],
                    ['name' => 'Jednostka sprzedaży', 'value' => 'para'],
                ]],
                ['section' => 'Parametry techniczne', 'rows' => [['name' => 'Norma', 'value' => 'EN 166']]],
            ],
            'synced_at' => Carbon::parse('2026-09-16 08:00:00'),
        ]);

        $response = $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonCount(2, 'shop_fields')
            ->assertJsonPath('shop_fields.0.source_key', 'b2b:'.$anro->id)
            ->assertJsonPath('shop_fields.0.source_label', 'B2B Anro')
            ->assertJsonPath('shop_fields.0.b2b_account_id', $anro->id)
            ->assertJsonPath('shop_fields.0.source_url', 'https://b2b.anro.net.pl/products/15937')
            ->assertJsonPath('shop_fields.0.synced_at', Carbon::parse('2026-09-16 08:00:00')->toISOString())
            ->assertJsonCount(2, 'shop_fields.0.sections')
            ->assertJsonPath('shop_fields.0.sections.0.section', 'Informacje handlowe')
            ->assertJsonPath('shop_fields.0.sections.0.rows.0.name', 'Kod towaru')
            ->assertJsonPath('shop_fields.0.sections.0.rows.0.value', '67E/2-LS PC')
            ->assertJsonPath('shop_fields.0.sections.1.section', 'Parametry techniczne')
            // konto bez łącznika: pierwsza witryna, nie login
            ->assertJsonPath('shop_fields.1.source_key', 'b2b:'.$noConnector->id)
            ->assertJsonPath('shop_fields.1.source_label', 'B2B b2b.example.pl')
            ->assertJsonPath('shop_fields.1.sections.0.section', '')
            ->assertJsonPath('shop_fields.1.sections.0.rows.0.value', 'czarny');

        // surowa relacja nie wycieka obok shop_fields
        $response->assertJsonMissingPath('shop_cards');

        $body = (string) $response->getContent();
        foreach (['login-anro-tajny', 'haslo-anro-tajne', 'login-inny-tajny', 'haslo-inne-tajne'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_show_without_shop_cards_returns_empty_list(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('SHOP-2');

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('shop_fields', []);
    }

    public function test_section_without_rows_is_skipped(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('SHOP-3');
        $account = $this->account('anro', 'b2b.anro.net.pl');

        ProductShopCard::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $account->id,
            'source_url' => null,
            'fields' => [
                ['section' => 'Pusta'],
                ['section' => 'Z wierszami', 'rows' => [['name' => 'Kod towaru', 'value' => 'ABC']]],
            ],
            'synced_at' => Carbon::parse('2026-09-16 08:00:00'),
        ]);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonCount(1, 'shop_fields')
            ->assertJsonPath('shop_fields.0.source_url', null)
            ->assertJsonCount(1, 'shop_fields.0.sections')
            ->assertJsonPath('shop_fields.0.sections.0.section', 'Z wierszami');
    }

    public function test_index_flags_cards_with_shop_fields(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $withCard = $this->product('SHOP-4');
        $withoutCard = $this->product('SHOP-5');
        $account = $this->account('anro', 'b2b.anro.net.pl');

        ProductShopCard::query()->create([
            'product_id' => $withCard->id,
            'b2b_account_id' => $account->id,
            'source_url' => null,
            'fields' => [
                ['section' => 'Informacje handlowe', 'rows' => [['name' => 'Kod towaru', 'value' => '67E-LS PC']]],
            ],
            'synced_at' => Carbon::parse('2026-09-16 08:00:00'),
        ]);

        $rows = collect($this->getJson('/api/products?per_page=50')->assertOk()->json('data'))->keyBy('sku');

        $this->assertTrue($rows['SHOP-4']['has_shop_fields']);
        $this->assertFalse($rows['SHOP-5']['has_shop_fields']);
        // sama flaga: wiersze idą dopiero w karcie szczegółów
        $this->assertArrayNotHasKey('shop_fields', $rows['SHOP-4']);
        $this->assertArrayNotHasKey('shop_cards_exists', $rows['SHOP-4']);
    }

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Okulary '.$sku,
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 50,
            'purchase_price' => 40,
            'stock' => 1,
        ]);
    }

    private function account(?string $connector, string $site): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector === null ? 'login-inny-tajny' : 'login-anro-tajny',
            'password' => $connector === null ? 'haslo-inne-tajne' : 'haslo-anro-tajne',
            'sites' => [$site],
            'connector' => $connector,
        ]);
    }
}
