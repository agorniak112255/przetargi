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

    public function test_renaming_distributor_account_list_keeps_manufacturers_of_its_cards(): void
    {
        // wpis konta dystrybutora wielu marek (P4S): karty mają producentów z asortymentu, a karta producenta (3M)
        // trafia na tę listę także po łączeniu kart — nazwa cennika nie jest ich producentem
        [$list, $threeM, $uvex] = $this->distributorAccountList();
        Bus::fake([RegisterManufacturerCatalogJob::class]);

        $this->patchJson("/api/price-lists/{$list->id}", ['manufacturer' => 'P4S Sp. z o.o.'])
            ->assertOk()
            ->assertJsonPath('price_list.manufacturer', 'P4S Sp. z o.o.')
            ->assertJsonPath('products_updated', 0)
            ->assertJsonPath('products_other_manufacturer', 2)
            ->assertJsonPath('message', 'Zapisano cennik (producent: „P4S” → „P4S Sp. z o.o.”), karty z innym producentem bez zmian: 2.');

        $this->assertSame('3M', $threeM->fresh()->manufacturer);
        $this->assertSame('UVEX', $uvex->fresh()->manufacturer);
        $this->assertSame('p4s sp z o o', $list->fresh()->manufacturer_key);
        // bez przykładowej karty — domeny karty 3M zapisałyby się jako strona P4S
        Bus::assertDispatched(
            RegisterManufacturerCatalogJob::class,
            static fn (RegisterManufacturerCatalogJob $job): bool => $job->manufacturer === 'P4S Sp. z o.o.' && $job->sampleProductId === 0,
        );
    }

    public function test_saving_form_without_name_change_leaves_cards_untouched(): void
    {
        // formularz w Cennikach wysyła nazwę przy każdym zapisie, także samej wersji i znacznika cennika sugerowanego
        [$list, $threeM, $uvex] = $this->distributorAccountList();
        Bus::fake([RegisterManufacturerCatalogJob::class]);

        $this->patchJson("/api/price-lists/{$list->id}", [
            'manufacturer' => 'P4S',
            'version' => 'wrzesień 2026',
            'suggested_prices' => false,
        ])
            ->assertOk()
            ->assertJsonPath('price_list.version', 'wrzesień 2026')
            ->assertJsonPath('products_updated', 0)
            ->assertJsonPath('products_other_manufacturer', 0)
            ->assertJsonPath('message', 'Zapisano cennik.');

        $this->assertSame('3M', $threeM->fresh()->manufacturer);
        $this->assertSame('UVEX', $uvex->fresh()->manufacturer);
        Bus::assertNotDispatched(RegisterManufacturerCatalogJob::class);
    }

    public function test_rename_reaches_only_cards_saved_under_the_list_name(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $card = fn (string $sku, string $manufacturer): Product => Product::query()->create([
            'sku' => $sku, 'name' => 'Karta '.$sku, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 5,
        ]);
        // karta producenta przepięta na tę listę przy łączeniu kart stoi na początku product_ids
        $producer = $card('3M-1', '3M');
        $artra = $card('AR-1', 'ARTRA');
        // ten sam klucz cennika — zapis z importu sprzed zwinięcia wpisów jednego producenta
        $variant = $card('AR-2', 'Artra.');
        // ta sama marka po pierwszym słowie (CanonicalBrand), ale inna nazwa — zwinięcie cenników ich nie scala
        $safety = $card('AR-3', 'ARTRA SAFETY');
        $blank = $card('AR-4', '');
        $list = PriceList::query()->create([
            'manufacturer' => 'ARTRA', 'manufacturer_key' => 'artra', 'version' => '2026-09',
            'original_filename' => 'artra.xlsx',
            'product_ids' => [$producer->id, $artra->id, $variant->id, $safety->id, $blank->id],
        ]);
        Bus::fake([RegisterManufacturerCatalogJob::class]);

        $this->patchJson("/api/price-lists/{$list->id}", ['manufacturer' => 'Artra Sp. z o.o.'])
            ->assertOk()
            ->assertJsonPath('products_updated', 2)
            ->assertJsonPath('products_other_manufacturer', 3);

        $this->assertSame('Artra Sp. z o.o.', $artra->fresh()->manufacturer);
        $this->assertSame('Artra Sp. z o.o.', $variant->fresh()->manufacturer);
        $this->assertSame('3M', $producer->fresh()->manufacturer);
        $this->assertSame('ARTRA SAFETY', $safety->fresh()->manufacturer);
        $this->assertSame('', $blank->fresh()->manufacturer);
        Bus::assertDispatched(
            RegisterManufacturerCatalogJob::class,
            static fn (RegisterManufacturerCatalogJob $job): bool => $job->sampleProductId === $artra->id,
        );
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

    /**
     * Wpis konta B2B dystrybutora (b2b_accounts.last_price_list_id) z kartami dwóch marek.
     *
     * @return array{0: PriceList, 1: Product, 2: Product}
     */
    private function distributorAccountList(): array
    {
        $admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($admin);
        $threeM = Product::query()->create([
            'sku' => '3M-H540', 'name' => 'Nauszniki Peltor Optime III', 'manufacturer' => '3M',
            'catalog_price_net' => 120, 'purchase_price' => 90,
        ]);
        $uvex = Product::query()->create([
            'sku' => 'UV-9190', 'name' => 'Okulary uvex i-3', 'manufacturer' => 'UVEX',
            'catalog_price_net' => 60, 'purchase_price' => 45,
        ]);
        $list = PriceList::query()->create([
            'manufacturer' => 'P4S', 'manufacturer_key' => 'p4s', 'version' => 'B2B · aktualizacja 2026-09-25 02:10',
            'original_filename' => 'b2b.p4s.pl (API)', 'product_ids' => [$threeM->id, $uvex->id],
        ]);
        B2bAccount::query()->create([
            'username' => 'supon', 'password' => 'sekret', 'sites' => ['b2b.p4s.pl'], 'connector' => 'p4s',
            'last_price_list_id' => $list->id, 'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);

        return [$list, $threeM, $uvex];
    }
}
