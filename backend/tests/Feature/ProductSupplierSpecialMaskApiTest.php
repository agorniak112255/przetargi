<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\ProductSubstitute;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Presta\PrestaCatalogGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\FakePrestaCatalogGateway;
use Tests\Support\SupplierSpecialFixture;
use Tests\TestCase;

/**
 * Ukrywanie ceny specjalnej B2B na kartach i w katalogu (prices.supplier_special.view, decyzja właściciela
 * 30.09.2026): handlowiec i kierownik widzą cenę standardową 211,37 zł zamiast ceny konta UVEX 173,19 zł, historię
 * cen konta bez kwot i zakupy z ERP XL bez cen; dyrektor dostaje dokładnie to samo co admin.
 */
final class ProductSupplierSpecialMaskApiTest extends TestCase
{
    use RefreshDatabase;
    use SupplierSpecialFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSupplierSpecial();
    }

    public function test_handlowiec_widzi_liste_w_cenie_standardowej(): void
    {
        $card = $this->supplierSpecialCard()['product'];
        $this->actingAsRole('handlowiec');

        $response = $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('data.0.id', $card->id)
            ->assertJsonPath('data.0.purchase_price', self::SPECIAL_STANDARD)
            ->assertJsonPath('data.0.catalog_price_net', self::SPECIAL_STANDARD)
            ->assertJsonPath('data.0.discount_percent', '0.00')
            ->assertJsonPath('data.0.purchase_price_pln', 211.37)
            ->assertJsonPath('data.0.price_pln', 211.37)
            ->assertJsonPath('data.0.supplier_special.status', 'standard')
            ->assertJsonPath('data.0.supplier_special.standard_price', 211.37)
            ->assertJsonPath('data.0.order_quantity.size_price_max', self::SPECIAL_SIZE_MAX_MASKED);
        $this->assertNoSpecialLeak((string) $response->getContent());
    }

    public function test_handlowiec_widzi_karte_w_cenie_standardowej(): void
    {
        ['product' => $card, 'slot' => $slot, 'variants' => $variants] = $this->supplierSpecialCard();
        $this->withErpStockValue($card);

        $this->actingAsRole('admin');
        $this->getJson('/api/products/'.$card->id)->assertOk()
            ->assertJsonPath('erp_xl.items.0.warehouses.0.value', 1731.9)
            ->assertJsonPath('erp_xl.prices_hidden', false);

        $this->actingAsRole('handlowiec');

        $response = $this->getJson('/api/products/'.$card->id)->assertOk()
            ->assertJsonPath('purchase_price', self::SPECIAL_STANDARD)
            ->assertJsonPath('catalog_price_net', self::SPECIAL_STANDARD)
            ->assertJsonPath('supplier_special.status', 'standard')
            ->assertJsonPath('source_prices.0.source_key', $slot->source_key)
            ->assertJsonPath('source_prices.0.purchase_price', self::SPECIAL_STANDARD)
            ->assertJsonPath('source_prices.0.catalog_price_net', self::SPECIAL_STANDARD)
            ->assertJsonPath('source_prices.0.supplier_special.status', 'standard')
            // cennik bazowy i rabat standardowy to nie tajemnica — z nich wynika cena standardowa
            ->assertJsonPath('source_prices.0.base_price_net', self::SPECIAL_BASE)
            ->assertJsonPath('source_prices.0.purchase_price_pln', 211.37)
            ->assertJsonPath('order_quantity.size_price_max', self::SPECIAL_SIZE_MAX_MASKED)
            ->assertJsonPath('variants.kind', 'size')
            ->assertJsonPath('variants.min_price', self::SPECIAL_STANDARD)
            ->assertJsonPath('variants.max_price', self::SPECIAL_SIZE_MAX_MASKED)
            ->assertJsonPath('variants.items.0.id', $variants[0]->id)
            ->assertJsonPath('variants.items.0.purchase_price', self::SPECIAL_STANDARD)
            ->assertJsonPath('variants.items.1.purchase_price', self::SPECIAL_SIZE_MAX_MASKED)
            ->assertJsonPath('erp_xl.prices_hidden', true)
            ->assertJsonPath('erp_xl.last_purchase.unit_price_pln', null)
            ->assertJsonPath('erp_xl.last_purchase.document_price', null)
            ->assertJsonPath('erp_xl.items.0.purchases.0.unit_price_pln', null)
            // stan z XL zostaje
            ->assertJsonPath('erp_xl.stock_trade', 10)
            // wartość księgowa partii / ilość = cena zakupu — też ukryta; ilość i kod magazynu zostają
            ->assertJsonPath('erp_xl.items.0.warehouses.0.value', null)
            ->assertJsonPath('erp_xl.items.0.warehouses.0.quantity', 10)
            ->assertJsonPath('erp_xl.items.0.warehouses.0.code', '01H')
            ->assertJsonPath('erp_xl.items.0.matched_value', 'XL-60148-UVEX');
        $this->assertNoSpecialLeak((string) $response->getContent());
        $this->assertNoErpValueLeak((string) $response->getContent());
    }

    public function test_historia_cen_karty_i_rozmiaru_bez_kwot_dla_handlowca(): void
    {
        ['product' => $card, 'variants' => $variants] = $this->supplierSpecialCard();
        $this->actingAsRole('handlowiec');

        $history = $this->getJson('/api/products/'.$card->id.'/price-history')->assertOk()
            ->assertJsonPath('data.0.prices_hidden', true)
            ->assertJsonPath('data.0.purchase_price', null)
            ->assertJsonPath('data.0.catalog_price_net', null)
            ->assertJsonPath('data.0.source', 'b2b:uvex');
        $this->assertNoSpecialLeak((string) $history->getContent());

        $variantHistory = $this->getJson('/api/products/'.$card->id.'/variants/'.$variants[1]->id.'/price-history')->assertOk()
            ->assertJsonPath('data.0.prices_hidden', true)
            ->assertJsonPath('data.0.purchase_price', null)
            ->assertJsonPath('data.0.list_price_net', null);
        $this->assertNoSpecialLeak((string) $variantHistory->getContent());
    }

    public function test_ostatnia_zmiana_ceny_pomija_ukryta_grupe_konta(): void
    {
        ['product' => $card, 'run' => $run] = $this->supplierSpecialCard();
        // starsza cena konta UVEX (zmiana 180,00 → 173,19 to ostatnia zmiana karty) i dwie ceny z pliku przed nią
        $this->historyRow($card, 180, 180, 'b2b:uvex', '2026-09-01 06:00:00', $run->id);
        $this->historyRow($card, 100, 90, 'price_list_import', '2026-08-01 08:00:00');
        $this->historyRow($card, 110, 99, 'price_list_import', '2026-08-15 08:00:00');

        $this->actingAsRole('admin');
        $this->getJson('/api/products/'.$card->id)->assertOk()
            ->assertJsonPath('last_price_change.source', 'b2b:uvex')
            ->assertJsonPath('last_price_change.purchase_new', 173.19)
            ->assertJsonPath('price_change_percent', -3.8);

        $this->actingAsRole('handlowiec');
        $response = $this->getJson('/api/products/'.$card->id)->assertOk()
            ->assertJsonPath('last_price_change.source', 'price_list_import')
            ->assertJsonPath('last_price_change.purchase_old', 90)
            ->assertJsonPath('last_price_change.purchase_new', 99)
            ->assertJsonPath('price_change_percent', 10);
        $this->assertNoSpecialLeak((string) $response->getContent());
        $list = $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('data.0.last_price_change.source', 'price_list_import');
        $this->assertNoSpecialLeak((string) $list->getContent());

        // wiersze pliku widoczne z cenami, wiersze konta bez
        $rows = collect($this->getJson('/api/products/'.$card->id.'/price-history')->assertOk()->json('data'));
        $this->assertSame([true, true, false, false], $rows->pluck('prices_hidden')->all());
        $this->assertSame('99.00', $rows[2]['purchase_price']);
        $this->assertEquals(10, $rows[2]['catalog_pct']);
    }

    public function test_filtr_cen_specjalnych_zabroniony_bez_uprawnienia(): void
    {
        $card = $this->supplierSpecialCard()['product'];

        $this->actingAsRole('handlowiec');
        $this->getJson('/api/products?supplier_special=special')
            ->assertForbidden()
            ->assertJsonPath('message', 'Brak uprawnienia do cen specjalnych B2B.');
        $this->getJson('/api/products?supplier_special=worse_than_standard')->assertOk();

        $this->actingAsRole('dyrektor');
        $this->getJson('/api/products?supplier_special=special')->assertOk()
            ->assertJsonPath('data.0.id', $card->id)
            ->assertJsonPath('data.0.supplier_special.status', 'special');
    }

    public function test_dyrektor_dostaje_to_samo_co_admin(): void
    {
        ['product' => $card, 'variants' => $variants] = $this->supplierSpecialCard();
        $urls = [
            '/api/products',
            '/api/products/'.$card->id,
            '/api/products/'.$card->id.'/price-history',
            '/api/products/'.$card->id.'/variants/'.$variants[1]->id.'/price-history',
        ];

        $this->actingAsRole('admin');
        $admin = array_map(fn (string $url): string => (string) $this->getJson($url)->assertOk()->getContent(), $urls);
        $this->actingAsRole('dyrektor');
        $dyrektor = array_map(fn (string $url): string => (string) $this->getJson($url)->assertOk()->getContent(), $urls);

        $this->assertSame($admin, $dyrektor);
        $this->assertStringContainsString('"purchase_price":"173.19"', $dyrektor[1]);
        $this->assertStringContainsString('"prices_hidden":false', $dyrektor[1]);
        $this->assertStringContainsString('"status":"special"', $dyrektor[0]);
    }

    public function test_karta_z_ceną_z_pliku_bez_zmian_a_slot_uvex_maskowany(): void
    {
        // karta Ansella: obowiązuje plik producenta (200,00 zł), UVEX to dystrybutor z ceną specjalną poza ceną karty
        ['product' => $card, 'slot' => $slot] = $this->supplierSpecialCard('ANS-11-840', [
            'manufacturer' => 'Ansell',
            'catalog_price_net' => '250.00',
            'purchase_price' => '200.00',
            'discount_percent' => 20,
        ]);
        $list = PriceList::query()->create(['manufacturer' => 'Ansell', 'manufacturer_key' => 'ansell', 'version' => '2026']);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $list->id,
            'catalog_price_net' => 250,
            'purchase_price' => 200,
            'discount_percent' => 20,
            'currency' => 'PLN',
            'checked_at' => Carbon::now()->subDay(),
        ]);

        $this->actingAsRole('admin');
        $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('data.0.cheaper_source.source_key', $slot->source_key);

        $this->actingAsRole('handlowiec');
        $list = $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('data.0.purchase_price', '200.00')
            ->assertJsonPath('data.0.catalog_price_net', '250.00')
            ->assertJsonPath('data.0.discount_percent', '20.00')
            ->assertJsonPath('data.0.supplier_special', null)
            // 211,37 zł u UVEX nie jest taniej niż 200,00 zł
            ->assertJsonPath('data.0.cheaper_source', null);
        $this->assertNoSpecialLeak((string) $list->getContent());

        $response = $this->getJson('/api/products/'.$card->id)->assertOk()
            ->assertJsonPath('purchase_price', '200.00')
            ->assertJsonPath('catalog_price_net', '250.00')
            ->assertJsonPath('supplier_special', null)
            ->assertJsonPath('source_prices.0.source_key', ProductSourcePrice::SOURCE_FILE)
            ->assertJsonPath('source_prices.0.is_effective', true)
            ->assertJsonPath('source_prices.1.source_key', $slot->source_key)
            ->assertJsonPath('source_prices.1.purchase_price', self::SPECIAL_STANDARD)
            ->assertJsonPath('source_prices.1.supplier_special.status', 'standard')
            ->assertJsonPath('source_prices.1.is_cheapest', false);
        $this->assertNoSpecialLeak((string) $response->getContent());
    }

    public function test_cennik_konta_bez_cen_zmian_dla_handlowca(): void
    {
        $card = $this->supplierSpecialCard()['product'];
        $list = $this->uvexPriceList($card);

        $this->actingAsRole('admin');
        $this->assertStringContainsString('173.19', (string) $this->getJson('/api/price-lists/'.$list->id)->assertOk()->getContent());

        $this->actingAsRole('handlowiec');
        $response = $this->getJson('/api/price-lists/'.$list->id)->assertOk()
            ->assertJsonPath('price_changes.0.sku', $card->sku)
            ->assertJsonPath('price_changes.0.purchase_new', null)
            ->assertJsonPath('price_changes.0.direction', 'down')
            ->assertJsonPath('updated_products.0.catalog_new', null);
        $this->assertNoSpecialLeak((string) $response->getContent());
    }

    public function test_karta_secura_z_ceną_specjalną_z_pliku_w_cenie_standardowej_dla_handlowca(): void
    {
        ['product' => $card] = $this->securaFileCard();
        $this->withErpStockValue($card, 17.30);

        $this->actingAsRole('admin');
        $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('data.0.purchase_price', self::FILE_SPECIAL_PRICE)
            ->assertJsonPath('data.0.supplier_special.status', 'special')
            ->assertJsonPath('data.0.supplier_special.standard_price', 22.78)
            ->assertJsonPath('data.0.supplier_special.source', 'file');
        $this->getJson('/api/products/'.$card->id)->assertOk()
            ->assertJsonPath('purchase_price', self::FILE_SPECIAL_PRICE)
            ->assertJsonPath('supplier_special.status', 'special')
            ->assertJsonPath('supplier_special.source', 'file')
            ->assertJsonPath('source_prices.0.source_key', ProductSourcePrice::SOURCE_FILE)
            ->assertJsonPath('source_prices.0.purchase_price', self::FILE_SPECIAL_PRICE)
            ->assertJsonPath('source_prices.0.supplier_special.status', 'special')
            ->assertJsonPath('source_prices.0.supplier_special.source', 'file')
            ->assertJsonPath('erp_xl.prices_hidden', false);
        $this->getJson('/api/products/'.$card->id.'/price-history')->assertOk()
            ->assertJsonPath('data.0.prices_hidden', false)
            ->assertJsonPath('data.0.purchase_price', self::FILE_SPECIAL_PRICE);
        $this->getJson('/api/products?supplier_special=special')->assertOk()
            ->assertJsonPath('data.0.id', $card->id);

        foreach (['handlowiec', 'kierownik'] as $role) {
            $this->actingAsRole($role);
            $list = $this->getJson('/api/products')->assertOk()
                ->assertJsonPath('data.0.id', $card->id)
                ->assertJsonPath('data.0.purchase_price', self::FILE_STANDARD)
                // katalogowa = cennik bazowy (nie tajemnica), rabat liczony od niej na nowo
                ->assertJsonPath('data.0.catalog_price_net', self::FILE_CATALOG)
                ->assertJsonPath('data.0.discount_percent', self::FILE_STANDARD_DISCOUNT)
                ->assertJsonPath('data.0.purchase_price_pln', 22.78)
                ->assertJsonPath('data.0.supplier_special.status', 'standard')
                ->assertJsonPath('data.0.supplier_special.source', 'file')
                ->assertJsonPath('data.0.last_price_change', null);
            $this->assertNoSpecialLeak((string) $list->getContent());

            $details = $this->getJson('/api/products/'.$card->id)->assertOk()
                ->assertJsonPath('purchase_price', self::FILE_STANDARD)
                ->assertJsonPath('catalog_price_net', self::FILE_CATALOG)
                ->assertJsonPath('supplier_special.status', 'standard')
                ->assertJsonPath('supplier_special.source', 'file')
                ->assertJsonPath('source_prices.0.source_key', ProductSourcePrice::SOURCE_FILE)
                ->assertJsonPath('source_prices.0.purchase_price', self::FILE_STANDARD)
                ->assertJsonPath('source_prices.0.discount_percent', self::FILE_STANDARD_DISCOUNT)
                ->assertJsonPath('source_prices.0.supplier_special.status', 'standard')
                ->assertJsonPath('source_prices.0.base_price_net', self::FILE_CATALOG)
                ->assertJsonPath('last_price_change', null)
                ->assertJsonPath('erp_xl.prices_hidden', true)
                ->assertJsonPath('erp_xl.last_purchase.unit_price_pln', null)
                ->assertJsonPath('erp_xl.items.0.warehouses.0.value', null)
                ->assertJsonPath('erp_xl.items.0.warehouses.0.quantity', 10);
            $this->assertNoSpecialLeak((string) $details->getContent());

            $history = $this->getJson('/api/products/'.$card->id.'/price-history')->assertOk()
                ->assertJsonPath('data.0.source', 'price_list_import')
                ->assertJsonPath('data.0.prices_hidden', true)
                ->assertJsonPath('data.0.purchase_price', null)
                ->assertJsonPath('data.0.catalog_price_net', null);
            $this->assertNoSpecialLeak((string) $history->getContent());

            $this->getJson('/api/products?supplier_special=special')->assertForbidden();
            $other = Product::query()->create([
                'sku' => 'INNE-'.$role, 'name' => 'Półmaska inna', 'manufacturer' => 'Ansell',
                'catalog_price_net' => 30, 'purchase_price' => 20, 'currency' => 'PLN', 'stock' => 1,
            ]);
            foreach ([
                $this->getJson('/api/products?q=SEC-1001'),
                $this->getJson('/api/products/compare?ids[]='.$card->id.'&ids[]='.$other->id),
                $this->getJson('/api/products/cross-ref?code='.$card->sku),
                $this->getJson('/api/price-lists'),
            ] as $response) {
                $response->assertSuccessful();
                $this->assertNoSpecialLeak((string) $response->getContent());
            }
        }
    }

    public function test_cennik_z_pliku_z_ceną_specjalną_bez_cen_zmian_dla_handlowca(): void
    {
        ['product' => $card, 'list' => $list] = $this->securaFileCard();

        $this->actingAsRole('admin');
        $this->getJson('/api/price-lists/'.$list->id)->assertOk()
            ->assertJsonPath('price_changes.0.purchase_new', 17.3)
            ->assertJsonPath('b2b_account', null);

        $this->actingAsRole('handlowiec');
        $response = $this->getJson('/api/price-lists/'.$list->id)->assertOk()
            ->assertJsonPath('price_changes.0.sku', $card->sku)
            ->assertJsonPath('price_changes.0.purchase_new', null)
            ->assertJsonPath('price_changes.0.purchase_old', null)
            ->assertJsonPath('price_changes.0.discount_new', null)
            ->assertJsonPath('price_changes.0.direction', 'down')
            ->assertJsonPath('updated_products.0.purchase_new', null);
        $this->assertNoSpecialLeak((string) $response->getContent());
    }

    public function test_cennik_konta_b2b_ze_slotem_pliku_z_oceną_bez_cen_dla_handlowca(): void
    {
        // wpis wspólny: konto Anro (bez slotów z oceną) i import pliku SECURA z ceną specjalną
        ['product' => $card, 'list' => $list] = $this->securaFileCard();
        $anro = $this->otherAccount();
        $anro->forceFill(['last_price_list_id' => $list->id])->save();

        $this->actingAsRole('admin');
        $this->getJson('/api/price-lists/'.$list->id)->assertOk()
            ->assertJsonPath('b2b_account.id', $anro->id)
            ->assertJsonPath('price_changes.0.purchase_new', 17.3);

        $this->actingAsRole('handlowiec');
        $response = $this->getJson('/api/price-lists/'.$list->id)->assertOk()
            ->assertJsonPath('price_changes.0.sku', $card->sku)
            ->assertJsonPath('price_changes.0.purchase_new', null);
        $this->assertNoSpecialLeak((string) $response->getContent());

        // flaga cennika wystarcza, nawet gdy slot pliku stracił ocenę
        ProductSourcePrice::query()->where('product_id', $card->id)->update(['base_price_net' => null, 'standard_discount_percent' => null]);
        $this->getJson('/api/price-lists/'.$list->id)->assertOk()
            ->assertJsonPath('price_changes.0.purchase_new', null);
    }

    public function test_slot_przejęty_przez_inny_cennik_dalej_ukrywa_erp_i_historię_secury(): void
    {
        ['product' => $card, 'slot' => $slot] = $this->securaFileCard();
        $this->withErpStockValue($card, 17.30);
        $other = PriceList::query()->create(['manufacturer' => 'SECURA', 'manufacturer_key' => 'secura-2', 'version' => '2027']);
        $slot->forceFill(['price_list_id' => $other->id, 'purchase_price' => 25, 'discount_percent' => 13.31, 'base_price_net' => null, 'standard_discount_percent' => null])->save();
        $card->forceFill(['purchase_price' => 25, 'discount_percent' => 13.31])->save();

        $this->actingAsRole('handlowiec');
        $response = $this->getJson('/api/products/'.$card->id)->assertOk()
            ->assertJsonPath('purchase_price', '25.00')
            ->assertJsonPath('supplier_special', null)
            ->assertJsonPath('erp_xl.prices_hidden', true)
            ->assertJsonPath('erp_xl.last_purchase.unit_price_pln', null);
        $this->assertNoSpecialLeak((string) $response->getContent());
        $history = $this->getJson('/api/products/'.$card->id.'/price-history')->assertOk()
            ->assertJsonPath('data.0.prices_hidden', true)
            ->assertJsonPath('data.0.purchase_price', null);
        $this->assertNoSpecialLeak((string) $history->getContent());
    }

    public function test_cennik_z_pliku_bez_oceny_bez_zmian_dla_handlowca(): void
    {
        // plik bez kolumny ceny specjalnej (bez ceny bazowej i rabatu standardowego) — ceny jak dotąd
        ['product' => $card, 'list' => $list] = $this->securaFileCard('SEC-PLAIN', [], [
            'base_price_net' => null,
            'standard_discount_percent' => null,
        ]);
        $this->actingAsRole('handlowiec');

        $this->getJson('/api/price-lists/'.$list->id)->assertOk()
            ->assertJsonPath('price_changes.0.purchase_new', 17.3);
        $this->getJson('/api/products/'.$card->id)->assertOk()
            ->assertJsonPath('purchase_price', self::FILE_SPECIAL_PRICE)
            ->assertJsonPath('supplier_special', null)
            ->assertJsonPath('erp_xl.prices_hidden', false);
        $this->getJson('/api/products/'.$card->id.'/price-history')->assertOk()
            ->assertJsonPath('data.0.prices_hidden', false)
            ->assertJsonPath('data.0.purchase_price', self::FILE_SPECIAL_PRICE);
    }

    public function test_handlowiec_i_kierownik_nie_dostaja_ceny_specjalnej_nigdzie(): void
    {
        ['product' => $card, 'variants' => $variants] = $this->supplierSpecialCard('60148-UVEX', [
            'category' => 'Rękawice',
            'description' => 'Rękawice ochronne UVEX do pracy z olejami, powłoka nitrylowa. Norma EN 388.',
            'norms' => 'EN 388',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $other = Product::query()->create([
            'sku' => 'INNE-1', 'name' => 'Rękawice nitrylowe inne', 'manufacturer' => 'Ansell', 'category' => 'Rękawice',
            'catalog_price_net' => 30, 'purchase_price' => 20, 'currency' => 'PLN', 'stock' => 1,
        ]);
        ProductSubstitute::query()->create([
            'main_product_id' => $other->id, 'substitute_product_id' => $card->id, 'type' => 'tanszy',
            'match_percent' => 80, 'approval_status' => 'oczekuje',
        ]);
        ProductSubstitute::query()->create([
            'main_product_id' => $card->id, 'substitute_product_id' => $other->id, 'type' => 'tanszy',
            'match_percent' => 70, 'approval_status' => 'oczekuje',
        ]);
        $priceList = $this->uvexPriceList($card);
        $this->withErpStockValue($card);
        $this->fakeAiSearch($card);

        foreach (['handlowiec', 'kierownik'] as $role) {
            $this->actingAsRole($role);
            $responses = [
                $this->getJson('/api/products'),
                $this->getJson('/api/products?q=60148'),
                $this->getJson('/api/products?supplier_special=worse_than_standard'),
                $this->getJson('/api/products/'.$card->id),
                $this->getJson('/api/products/'.$card->id.'/price-history'),
                $this->getJson('/api/products/'.$card->id.'/variants/'.$variants[0]->id.'/price-history'),
                $this->getJson('/api/products/'.$card->id.'/variants/'.$variants[1]->id.'/price-history'),
                $this->getJson('/api/substitutes'),
                $this->getJson('/api/products/'.$card->id.'/substitutes'),
                $this->getJson('/api/products/'.$other->id.'/substitutes'),
                $this->getJson('/api/products/'.$other->id),
                $this->getJson('/api/products/compare?ids[]='.$card->id.'&ids[]='.$other->id),
                $this->getJson('/api/products/cross-ref?code='.$card->sku),
                $this->getJson('/api/products/cross-ref/options?code='.$card->sku),
                $this->getJson('/api/price-lists'),
                $this->getJson('/api/price-lists/'.$priceList->id),
            ];
            $main = Product::query()->create([
                'sku' => 'MAIN-'.$role, 'name' => 'Rękawice główne', 'manufacturer' => 'Ansell',
                'catalog_price_net' => 40, 'purchase_price' => 30, 'currency' => 'PLN', 'stock' => 1,
            ]);
            $store = $this->postJson('/api/substitutes', [
                'main_product_id' => $main->id,
                'substitute_product_id' => $card->id,
                'type' => 'tanszy',
                'match_percent' => 60,
            ])->assertCreated();
            $responses[] = $store;
            $responses[] = $this->patchJson('/api/substitutes/'.$store->json('id'), ['match_percent' => 65]);
            $ai = $this->postJson('/api/products/ai-search', ['query' => 'rękawice UVEX do pracy z olejami'])
                ->assertJsonPath('products.0.id', $card->id)
                ->assertJsonPath('products.0.purchase_price', self::SPECIAL_STANDARD)
                ->assertJsonPath('products.0.purchase_price_pln', 211.37);
            $responses[] = $ai;

            foreach ($responses as $i => $response) {
                $response->assertSuccessful();
                $this->assertNoSpecialLeak((string) $response->getContent());
                $this->assertNoErpValueLeak((string) $response->getContent());
            }
            $this->assertSame(211.37, $responses[11]->json('products.0.catalog_price_net'));
            $this->assertSame(211.37, $responses[12]->json('seed.catalog_price_net'));
            $this->assertSame(self::SPECIAL_STANDARD, $responses[9]->json('substitutes.0.substitute_product.catalog_price_net'));
            $this->assertSame(self::SPECIAL_STANDARD, $responses[8]->json('main_product.purchase_price'));
        }
    }

    public function test_kierownik_po_wzbogaceniu_i_presta_dostaje_cene_standardowa(): void
    {
        Queue::fake();
        Storage::fake('public');
        // karta Ansella z ceną konta UVEX w cenie karty — wzbogacanie tej marki ma sprawdzoną ścieżkę w testach
        $card = $this->supplierSpecialCard('ANS-48-126', ['manufacturer' => 'Ansell', 'name' => 'Rękawice testowe'])['product'];
        $this->fakeEnrichment($card);
        $presta = new FakePrestaCatalogGateway;
        $presta->rows = [[
            'id_product' => 10,
            'reference' => $card->sku,
            'ean13' => '',
            'name' => $card->name,
            'link_rewrite' => 'rekawice',
            'description_short' => 'Krótki opis rękawic.',
            'description' => '<p>Pełny opis rękawic. EN 388.</p>',
            'manufacturer' => 'Ansell',
            'features' => 'EN 388',
            'url' => 'https://supon.rzeszow.pl/10-rekawice.html',
        ]];
        $this->app->instance(PrestaCatalogGateway::class, $presta);
        $this->actingAsRole('kierownik');

        $enrich = $this->postJson('/api/products/'.$card->id.'/enrich');
        $this->assertSame(200, $enrich->status(), (string) $enrich->getContent());
        $enrich->assertJsonPath('product.purchase_price', self::SPECIAL_STANDARD)
            ->assertJsonPath('product.catalog_price_net', self::SPECIAL_STANDARD);
        $this->assertNoSpecialLeak((string) $enrich->getContent());

        $apply = $this->postJson('/api/products/'.$card->id.'/presta-apply', ['presta_id' => 10, 'force' => true])->assertOk()
            ->assertJsonPath('product.purchase_price', self::SPECIAL_STANDARD);
        $this->assertNoSpecialLeak((string) $apply->getContent());

        // karta w bazie zostaje z ceną konta
        $this->assertSame(self::SPECIAL_PRICE, (string) $card->fresh()->purchase_price);
    }

    private function tinyJpeg(): string
    {
        $img = imagecreatetruecolor(220, 220);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 40, 120, 200));
        ob_start();
        imagejpeg($img, null, 85);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    public function test_lista_zamiennikow_bez_zapytan_na_karte_dla_handlowca(): void
    {
        $card = $this->supplierSpecialCard()['product'];
        $addPairs = function (int $from, int $count) use ($card): void {
            for ($i = $from; $i < $from + $count; $i++) {
                $main = Product::query()->create([
                    'sku' => 'MAIN-'.$i, 'name' => 'Karta główna '.$i, 'manufacturer' => 'Ansell',
                    'catalog_price_net' => 40, 'purchase_price' => 30, 'currency' => 'PLN', 'stock' => 1,
                ]);
                $other = Product::query()->create([
                    'sku' => 'SUB-'.$i, 'name' => 'Zamiennik '.$i, 'manufacturer' => 'Ansell',
                    'catalog_price_net' => 20, 'purchase_price' => 15, 'currency' => 'PLN', 'stock' => 1,
                ]);
                foreach ([$card, $other] as $substitute) {
                    ProductSubstitute::query()->create([
                        'main_product_id' => $main->id, 'substitute_product_id' => $substitute->id, 'type' => 'tanszy',
                        'match_percent' => 70, 'approval_status' => 'oczekuje',
                    ]);
                }
            }
        };
        $this->actingAsRole('handlowiec');
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->getJson('/api/substitutes')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertNoSpecialLeak((string) $response->getContent());
            $this->assertSame(self::SPECIAL_STANDARD, $response->json('0.substitute_product.catalog_price_net'));

            return $queries;
        };

        $addPairs(0, 2);
        // pierwsze żądanie wczytuje uprawnienia roli do pamięci podręcznej — mierzymy od drugiego
        $count();
        $few = $count();
        $addPairs(2, 8);
        $many = $count();

        // karty główne (bez cen) i zamienne hurtem — liczba zapytań nie rośnie z liczbą par
        $this->assertSame($few, $many);
    }

    /** Wartość księgowa partii w magazynie towaru XL: 10 szt. × cena jednostkowa (domyślnie 173,19 zł = 1731,90 zł). */
    private function withErpStockValue(Product $card, float $unitPrice = 173.19): void
    {
        $value = round($unitPrice * 10, 2);
        $item = ErpItem::query()->whereHas('links', static fn ($q) => $q->where('product_id', $card->id))->firstOrFail();
        $item->forceFill([
            'stock_value' => $value,
            'stock_by_warehouse' => [[
                'code' => '01H', 'name' => 'Magazyn HANDEL', 'quantity' => 10, 'value' => $value, 'oldest_lot' => '2026-09-20',
            ]],
        ])->save();
    }

    private function assertNoErpValueLeak(string $json): void
    {
        $this->assertDoesNotMatchRegularExpression('/(?<!\d)1731[.,]90?(?!\d)/', $json, 'Wyciek wartości księgowej partii ERP (1731,90).');
    }

    private function actingAsRole(string $role): User
    {
        $user = $this->userWithRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function historyRow(Product $card, float $catalog, float $purchase, string $source, string $at, ?int $runId = null): void
    {
        $row = new ProductPriceHistory;
        $row->forceFill([
            'product_id' => $card->id,
            'b2b_sync_run_id' => $runId,
            'catalog_price_net' => $catalog,
            'purchase_price' => $purchase,
            'currency' => 'PLN',
            'source' => $source,
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ])->save();
    }

    /** Wpis konta UVEX w Cennikach ze szczegółami ostatniego przebiegu (zmiana 180,00 → 173,19). */
    private function uvexPriceList(Product $card): PriceList
    {
        $change = [
            'sku' => $card->sku,
            'name' => $card->name,
            'catalog_old' => 180.0,
            'catalog_new' => 173.19,
            'catalog_pct' => -3.8,
            'purchase_old' => 180.0,
            'purchase_new' => 173.19,
            'discount_old' => 0.0,
            'discount_new' => 0.0,
            'direction' => 'down',
        ];
        $list = PriceList::query()->create([
            'manufacturer' => 'UVEX',
            'manufacturer_key' => 'uvex',
            'version' => '2026-09-29',
            'original_filename' => 'izam.system-b2b.pl (API)',
            'rows_total' => 1,
            'price_changes' => [$change],
            'updated_products' => [[...$change, 'price_changed' => true, 'fields' => ['purchase_price']]],
            'product_ids' => [$card->id],
        ]);
        $this->uvexAccount()->forceFill(['last_price_list_id' => $list->id])->save();

        return $list;
    }

    /** Wyszukiwarka AI z modelem-atrapą, który wybiera kartę UVEX (jak ProductAiSearchApiTest). */
    private function fakeAiSearch(Product $card): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'rękawice do pracy z olejami',
            'search_phrases' => ['rękawice UVEX', 'olej'],
            'constraints' => [],
            'matches' => [['id' => $card->id, 'score' => 90, 'reason' => 'Rękawice do olejów']],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    /** Wzbogacanie z wyszukiwarką i modelem-atrapą (jak ProductEnrichmentApiTest::test_single_product_enrichment_runs_synchronously). */
    private function fakeEnrichment(Product $card): void
    {
        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('dropListingResults')->andReturnUsing(static fn (array $results): array => $results)->byDefault();
        $search->shouldReceive('moreCatalogHits')->andReturn([])->byDefault();
        $search->shouldReceive('searchMappedRetailers')->andReturn([])->byDefault();
        $search->shouldReceive('searchWebWithoutLocalIndex')->andReturn(['results' => [], 'images' => [], 'errors' => []])->byDefault();
        $search->shouldReceive('forgetProductCache')->byDefault();
        $search->shouldReceive('searchBothPhases')->andReturn([
            'results' => [[
                'url' => 'https://example.com/product/'.$card->sku,
                'title' => 'Karta',
                'snippet' => 'Rękawice Ansell '.$card->sku,
            ]],
            'errors' => [],
        ]);
        $this->app->instance(HybridWebSearchService::class, $search);

        $extract = [
            'description' => 'Rękawice nitrylowe Ansell '.$card->sku.' do pracy w przemyśle. Spełniają normy EN 388 i chronią przed ścieraniem. Przeznaczone do montażu oraz prac precyzyjnych w warunkach suchych. Trwała powłoka nitrylowa zwiększa żywotność przy codziennym użytkowaniu.',
            'features' => ['nitryl'],
            'specs' => ['Długość: 30 cm'],
            'norms' => ['EN 388'],
            'certificates' => [],
            'materials' => ['nitryl'],
            'use_cases' => ['montaż'],
            'image_urls' => ['https://cdn.example.com/glove-'.$card->sku.'.jpg'],
            'source_urls' => ['https://example.com/product/'.$card->sku],
            'confidence' => 0.9,
        ];
        $handler = static function (array $messages) use ($extract): array {
            if (str_contains((string) ($messages[0]['content'] ?? ''), 'filtrem treści')) {
                return ['pages' => [['url' => 'https://example.com/product/x', 'text' => 'Produkt BHP. Norma EN 388.']]];
            }

            return $extract;
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->andReturn(['candidates' => []])->byDefault();
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake([
            'https://example.com/*' => Http::response(
                '<html><body>Ansell Rękawice testowe '.$card->sku.' <img src="https://cdn.example.com/glove-'.$card->sku.'.jpg" alt="'.$card->sku.'"></body></html>',
                200,
            ),
            'https://cdn.example.com/*' => Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://supon.rzeszow.pl/*' => Http::response('', 404),
        ]);
    }
}
