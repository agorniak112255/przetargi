<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Client;
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
 * Cena specjalna dostawcy na karcie i na liście: ocena ceny konta UVEX względem cennika bazowego
 * (App\Support\SupplierSpecialPrice). Klucz supplier_special jest osobny od special_prices (ceny kontraktowe
 * klientów), a na liście ocena dotyczy tylko ceny, którą karta faktycznie pokazuje.
 */
final class ProductSupplierSpecialApiTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $uvex;

    private B2bAccount $anro;

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
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->uvex = B2bAccount::query()->create([
            'username' => 'uvex-login',
            'password' => 'uvex-haslo',
            'sites' => ['izam.system-b2b.pl'],
            'connector' => 'uvex',
        ]);
        $this->anro = B2bAccount::query()->create([
            'username' => 'anro-login',
            'password' => 'anro-haslo',
            'sites' => ['b2b.anro.net.pl'],
            'connector' => 'anro',
        ]);
    }

    public function test_karta_pokazuje_cennik_bazowy_i_ocene_slotu(): void
    {
        $product = $this->product('9160-120', 200);
        $this->uvexSlot($product, 200, ['base_price_code' => '9160120', 'base_price_source' => 'Cennik UVEX 2026.xlsx · Okulary ochronne · 9160-120 · 2026-09-22']);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('source_prices.0.base_price_net', '255.31')
            ->assertJsonPath('source_prices.0.base_price_category', 'Okulary ochronne')
            ->assertJsonPath('source_prices.0.base_price_code', '9160120')
            ->assertJsonPath('source_prices.0.base_price_source', 'Cennik UVEX 2026.xlsx · Okulary ochronne · 9160-120 · 2026-09-22')
            ->assertJsonPath('source_prices.0.standard_discount_percent', '15.00')
            ->assertJsonPath('source_prices.0.supplier_special.status', 'special')
            ->assertJsonPath('source_prices.0.supplier_special.standard_price', 217.01)
            ->assertJsonPath('source_prices.0.supplier_special.saving_net', 17.01)
            ->assertJsonPath('source_prices.0.supplier_special.actual_discount_percent', 21.66)
            ->assertJsonPath('supplier_special.status', 'special');
    }

    /** Heckel 255,31 × 0,85 = 217,01 przy cenie konta 216,75 — zaokrąglenie przeliczenia z euro, nie cena specjalna. */
    public function test_status_standard_w_tolerancji_i_gorzej_niz_standard(): void
    {
        $standard = $this->product('60598-1', 216.75);
        $this->uvexSlot($standard, 216.75);
        $worse = $this->product('60598-2', 230);
        $this->uvexSlot($worse, 230);

        $this->getJson('/api/products/'.$standard->id)
            ->assertOk()
            ->assertJsonPath('source_prices.0.supplier_special.status', 'standard')
            ->assertJsonPath('supplier_special.status', 'standard');
        $this->getJson('/api/products/'.$worse->id)
            ->assertOk()
            ->assertJsonPath('source_prices.0.supplier_special.status', 'worse_than_standard')
            ->assertJsonPath('source_prices.0.supplier_special.saving_net', -12.99)
            ->assertJsonPath('supplier_special.status', 'worse_than_standard');
    }

    public function test_bez_reguly_rabatu_brak_oceny(): void
    {
        $product = $this->product('9160-121', 200);
        $this->uvexSlot($product, 200, ['standard_discount_percent' => null]);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('source_prices.0.base_price_net', '255.31')
            ->assertJsonPath('source_prices.0.standard_discount_percent', null)
            ->assertJsonPath('source_prices.0.supplier_special', null)
            ->assertJsonPath('supplier_special', null);
    }

    public function test_slot_bez_cennika_bazowego_ma_puste_pola(): void
    {
        $product = $this->product('A-1', 36);
        ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => ProductSourcePrice::b2bKey((int) $this->anro->id),
            'b2b_account_id' => $this->anro->id,
            'catalog_price_net' => 48,
            'purchase_price' => 36,
            'currency' => 'PLN',
        ]);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('source_prices.0.base_price_net', null)
            ->assertJsonPath('source_prices.0.base_price_category', null)
            ->assertJsonPath('source_prices.0.base_price_code', null)
            ->assertJsonPath('source_prices.0.base_price_source', null)
            ->assertJsonPath('source_prices.0.standard_discount_percent', null)
            ->assertJsonPath('source_prices.0.supplier_special', null)
            ->assertJsonPath('supplier_special', null);
    }

    /** special_prices to ceny kontraktowe klientów — ocena dostawcy nie może się z nimi mieszać. */
    public function test_supplier_special_nie_koliduje_z_cenami_kontraktowymi(): void
    {
        $product = $this->product('9160-122', 200);
        $this->uvexSlot($product, 200);
        $client = Client::query()->create(['name' => 'Szpital Miejski']);
        $product->specialPrices()->create([
            'client_id' => $client->id,
            'client_name' => 'Szpital Miejski',
            'price' => 190,
            'currency' => 'PLN',
        ]);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonCount(1, 'special_prices')
            ->assertJsonPath('special_prices.0.client_name', 'Szpital Miejski')
            ->assertJsonMissingPath('special_prices.0.status')
            ->assertJsonPath('supplier_special.status', 'special');
    }

    public function test_lista_ocenia_tylko_cene_widoczna_na_karcie(): void
    {
        // cena karty = cena konta UVEX → ocena
        $shown = $this->product('9160-130', 200);
        $this->uvexSlot($shown, 200);
        // cenę karty ustala świeższe konto Anro — znacznik UVEX przy cenie 180 byłby nieprawdą
        $other = $this->product('9160-131', 180);
        $this->uvexSlot($other, 200, ['checked_at' => Carbon::parse('2026-09-01 08:00:00')]);
        ProductSourcePrice::query()->create([
            'product_id' => $other->id,
            'source_key' => ProductSourcePrice::b2bKey((int) $this->anro->id),
            'b2b_account_id' => $this->anro->id,
            'catalog_price_net' => 190,
            'purchase_price' => 180,
            'currency' => 'PLN',
            'checked_at' => Carbon::parse('2026-09-20 08:00:00'),
        ]);
        // ta sama liczba w innej walucie to inna cena
        $currency = $this->product('9160-132', 200, 'EUR');
        $this->uvexSlot($currency, 200);
        $none = $this->product('9160-133', 200);

        $rows = collect($this->getJson('/api/products?per_page=all')->assertOk()->json('data'))->keyBy('sku');

        $this->assertSame('special', $rows['9160-130']['supplier_special']['status']);
        $this->assertSame(217.01, $rows['9160-130']['supplier_special']['standard_price']);
        // cena normalna kategorii bez ponownego pobierania cennika: podstawa i kategoria przy ocenie
        $this->assertSame(255.31, $rows['9160-130']['supplier_special']['base_price']);
        $this->assertEquals(15, $rows['9160-130']['supplier_special']['standard_discount_percent']);
        $this->assertSame('Okulary ochronne', $rows['9160-130']['supplier_special']['category']);
        $this->assertNull($rows['9160-131']['supplier_special']);
        $this->assertNull($rows['9160-132']['supplier_special']);
        $this->assertNull($rows['9160-133']['supplier_special']);
        // karta z listy wyboru (ProductSearchSelect) dostaje ten sam znacznik
        $this->getJson('/api/products?q=9160-130')
            ->assertOk()
            ->assertJsonPath('data.0.sku', '9160-130')
            ->assertJsonPath('data.0.supplier_special.status', 'special');
    }

    public function test_filtr_listy_ceny_specjalne_zgodny_z_ocena_i_z_filtrem_cennika_konta(): void
    {
        // cennik 255,31 − 15% = 217,01; tolerancja 1,09 zł (0,5%)
        $special = $this->product('F-SPEC', 200);
        $this->uvexSlot($special, 200);
        $edge = $this->product('F-EDGE', 215.92);
        $this->uvexSlot($edge, 215.92);
        $standard = $this->product('F-STD', 216.75);
        $this->uvexSlot($standard, 216.75);
        $worse = $this->product('F-WORSE', 230);
        $this->uvexSlot($worse, 230);
        // cenę karty ustala inne źródło — nie ma znacznika, więc nie ma jej w filtrze
        $shadowed = $this->product('F-OTHER', 180);
        $this->uvexSlot($shadowed, 150);
        $foreign = $this->product('F-EUR', 200, 'EUR');
        $this->uvexSlot($foreign, 200);
        $noRule = $this->product('F-NORULE', 150);
        $this->uvexSlot($noRule, 150, ['standard_discount_percent' => null]);
        $this->product('F-NONE', 100);

        $specialRows = collect($this->getJson('/api/products?per_page=all&supplier_special=special')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['F-SPEC', 'F-EDGE'], $specialRows->pluck('sku')->all());
        // filtr w SQL i znacznik z PHP mówią to samo
        $this->assertSame(['special'], $specialRows->pluck('supplier_special.status')->unique()->values()->all());

        $worseRows = collect($this->getJson('/api/products?per_page=all&supplier_special=worse_than_standard')->assertOk()->json('data'));
        $this->assertSame(['F-WORSE'], $worseRows->pluck('sku')->all());
        $this->assertSame('worse_than_standard', $worseRows->first()['supplier_special']['status']);

        // nieznana wartość nie zawęża listy
        $this->assertCount(8, $this->getJson('/api/products?per_page=all&supplier_special=cokolwiek')->assertOk()->json('data'));

        // razem z filtrem „Cennik B2B: konto” — tylko karty tego konta
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->uvex->id,
            'remote_id' => 'F-SPEC',
            'remote_sku' => 'F-SPEC',
            'product_id' => $special->id,
        ]);
        $this->getJson('/api/products?per_page=all&supplier_special=special&b2b_account='.$this->uvex->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'F-SPEC');
    }

    private function product(string $sku, float $purchase, string $currency = 'PLN'): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Okulary UVEX '.$sku,
            'manufacturer' => 'UVEX',
            'catalog_price_net' => $purchase,
            'purchase_price' => $purchase,
            'currency' => $currency,
            'stock' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function uvexSlot(Product $product, float $purchase, array $values = []): ProductSourcePrice
    {
        return ProductSourcePrice::query()->create([
            'product_id' => $product->id,
            'source_key' => ProductSourcePrice::b2bKey((int) $this->uvex->id),
            'b2b_account_id' => $this->uvex->id,
            'catalog_price_net' => $purchase,
            'purchase_price' => $purchase,
            'currency' => 'PLN',
            'base_price_net' => 255.31,
            'base_price_category' => 'Okulary ochronne',
            'base_price_code' => '9160120',
            'base_price_source' => 'Cennik UVEX',
            'standard_discount_percent' => 15,
            'checked_at' => Carbon::parse('2026-09-22 08:00:00'),
            ...$values,
        ]);
    }
}
