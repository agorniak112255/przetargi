<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Pricing\ProductEffectivePrice;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ceny od najtańszej w API (etap E): pola porównania przy source_prices karty, kurs, „taniej u …” na liście
 * produktów i przy karcie pozycji przetargu. Cena karty i oferty bez zmian.
 */
final class SourcePriceComparisonApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        Cache::forget('nbp.table_a.rates');
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));
        $this->user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($this->user);
    }

    public function test_show_adds_comparison_fields_without_changing_order(): void
    {
        // bez Http::fake: same ceny w PLN nie pytają NBP (TestCase zgłasza każde żądanie bez atrapy)
        [$card, $atg, $ardon] = $this->manufacturerAndDistributorCard();
        $list = PriceList::query()->create([
            'manufacturer' => 'ATG', 'manufacturer_key' => 'atg', 'version' => 'sugerowany', 'suggested_prices' => true,
        ]);
        app(ProductEffectivePrice::class)->saveSlot($card, ProductSourcePrice::SOURCE_FILE, [
            'price_list_id' => $list->id, 'catalog_price_net' => 52.72, 'purchase_price' => 52.72, 'currency' => 'PLN',
            'checked_at' => Carbon::parse('2026-09-15 10:00'),
        ]);

        $response = $this->getJson('/api/products/'.$card->id)->assertOk();

        // kolejność jak dotąd: obowiązujący (konto producenta), dystrybutor z powodem, plik z powodem
        $response->assertJsonPath('source_prices.0.source_key', ProductSourcePrice::b2bKey($atg->id))
            ->assertJsonPath('source_prices.0.is_effective', true)
            ->assertJsonPath('source_prices.0.purchase_price_pln', 10)
            ->assertJsonPath('source_prices.0.comparable', true)
            ->assertJsonPath('source_prices.0.price_rank', 2)
            ->assertJsonPath('source_prices.0.is_cheapest', false)
            ->assertJsonPath('source_prices.0.diff_to_effective_pct', 0)
            ->assertJsonPath('source_prices.1.source_key', ProductSourcePrice::b2bKey($ardon->id))
            ->assertJsonPath('source_prices.1.source_label', 'B2B Ardon')
            ->assertJsonPath('source_prices.1.purchase_price_pln', 9)
            ->assertJsonPath('source_prices.1.price_rank', 1)
            ->assertJsonPath('source_prices.1.is_cheapest', true)
            ->assertJsonPath('source_prices.1.diff_to_effective_pct', -10)
            ->assertJsonPath('source_prices.1.not_comparable_reason', null)
            ->assertJsonPath('source_prices.2.source_key', ProductSourcePrice::SOURCE_FILE)
            ->assertJsonPath('source_prices.2.comparable', false)
            ->assertJsonPath('source_prices.2.not_comparable_reason', 'cennik sugerowany')
            ->assertJsonPath('source_prices.2.price_rank', null)
            // kurs nieużyty (same złote) — bez daty i bez ostrzeżenia o kursie zastępczym
            ->assertJsonPath('source_prices_rates', ['as_of' => null, 'source' => 'nbp'])
            ->assertJsonPath('purchase_price', '10.00');
        // JSON bez części ułamkowej przy liczbach całkowitych (9.0 → 9) — frontend dostaje number
    }

    public function test_show_with_eur_source_reports_nbp_rate_date(): void
    {
        $this->fakeNbp();
        [$card, , $ardon] = $this->manufacturerAndDistributorCard();
        ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', ProductSourcePrice::b2bKey($ardon->id))
            ->update(['purchase_price' => 2.10, 'currency' => 'EUR']);

        $response = $this->getJson('/api/products/'.$card->id)
            ->assertOk()
            ->assertJsonPath('source_prices_rates', ['as_of' => '2026-09-22', 'source' => 'nbp'])
            ->assertJsonPath('source_prices.1.purchase_price_pln', 8.4);
        $this->assertSame(-16, $response->json('source_prices.1.diff_to_effective_pct'));
    }

    public function test_show_reports_fallback_rate_when_nbp_does_not_answer(): void
    {
        Http::fake(['api.nbp.pl/*' => Http::response('', 500)]);
        [$card, , $ardon] = $this->manufacturerAndDistributorCard();
        ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', ProductSourcePrice::b2bKey($ardon->id))
            ->update(['purchase_price' => 2.00, 'currency' => 'EUR']);

        // kurs zastępczy EUR 4,25 (NbpExchangeRateService::FALLBACK)
        $this->getJson('/api/products/'.$card->id)
            ->assertOk()
            ->assertJsonPath('source_prices_rates', ['as_of' => null, 'source' => 'fallback'])
            ->assertJsonPath('source_prices.1.purchase_price_pln', 8.5);
    }

    public function test_show_without_slots_has_rates_and_empty_list(): void
    {
        $card = $this->card('Ansell');

        $this->getJson('/api/products/'.$card->id)
            ->assertOk()
            ->assertJsonPath('source_prices', [])
            ->assertJsonPath('source_prices_rates.source', 'nbp');
    }

    public function test_list_rows_carry_cheaper_source(): void
    {
        // bez Http::fake — strona listy w samych złotych nie pyta NBP
        [$card, , $ardon] = $this->manufacturerAndDistributorCard();
        $plain = $this->card('Ansell');

        $response = $this->getJson('/api/products?sort=sku')->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertSame([
            'source_key' => ProductSourcePrice::b2bKey($ardon->id),
            'label' => 'B2B Ardon',
            'purchase_price_pln' => 9,
            'diff_pct' => -10,
        ], $rows[$card->id]['cheaper_source']);
        $this->assertArrayHasKey('cheaper_source', $rows[$plain->id]);
        $this->assertNull($rows[$plain->id]['cheaper_source']);
    }

    public function test_tender_main_product_carries_cheaper_source_and_offer_price_stays(): void
    {
        // bez Http::fake — przetarg w samych złotych nie pyta NBP
        [$card, , $ardon] = $this->manufacturerAndDistributorCard();
        $other = $this->card('Ansell');
        $tender = Tender::query()->create([
            'number' => 'PRZ/CMP/1',
            'title' => 'Rękawice',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => $this->user->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'target_margin_percent' => 20,
            'last_activity_at' => now(),
        ]);
        foreach ([[1, $card], [2, $other], [3, $card]] as [$lineNo, $product]) {
            TenderItem::query()->create([
                'tender_id' => $tender->id, 'line_no' => $lineNo, 'requirement' => 'Rękawice chemoodporne', 'quantity' => 1,
                'main_product_id' => $product->id, 'offer_price' => 12.00, 'ai_match_percent' => 90, 'status' => 'ok',
                'ai_match_reasons' => ['dopasowanie ręczne'],
            ]);
        }

        $response = $this->getJson('/api/tenders/'.$tender->id)->assertOk();
        $items = collect($response->json('tender.items'))->keyBy('line_no');

        $this->assertSame(ProductSourcePrice::b2bKey($ardon->id), $items[1]['main_product']['cheaper_source']['source_key']);
        $this->assertSame(-10, $items[1]['main_product']['cheaper_source']['diff_pct']);
        $this->assertSame(ProductSourcePrice::b2bKey($ardon->id), $items[3]['main_product']['cheaper_source']['source_key']);
        $this->assertArrayHasKey('cheaper_source', $items[2]['main_product']);
        $this->assertNull($items[2]['main_product']['cheaper_source']);
        $this->assertSame('12.00', $items[1]['offer_price']);
    }

    public function test_list_and_show_carry_order_quantity_of_effective_source(): void
    {
        [$goggles, $uvex] = $this->uvexGogglesCard();
        // warunek przegranego dystrybutora: tylko w wierszu źródła, nie przy cenie karty
        [$card, , $ardon] = $this->manufacturerAndDistributorCard();
        ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', ProductSourcePrice::b2bKey($ardon->id))
            ->update(['order_min_qty' => 12, 'order_step_qty' => 12, 'order_unit' => 'par']);
        $expected = [
            'min' => 10, 'step' => 10, 'unit' => 'szt', 'varies' => false, 'price_note' => null, 'price_carton_qty' => null,
            'source_key' => ProductSourcePrice::b2bKey($uvex->id), 'source_label' => 'B2B UVEX',
        ];

        $rows = collect($this->getJson('/api/products?sort=sku')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($expected, $rows[$goggles->id]['order_quantity']);
        $this->assertArrayHasKey('order_quantity', $rows[$card->id]);
        $this->assertNull($rows[$card->id]['order_quantity']);

        $this->getJson('/api/products/'.$goggles->id)
            ->assertOk()
            ->assertJsonPath('order_quantity', $expected)
            ->assertJsonPath('source_prices.0.order_min_qty', 10)
            ->assertJsonPath('source_prices.0.order_step_qty', 10)
            ->assertJsonPath('source_prices.0.order_unit', 'szt')
            ->assertJsonPath('source_prices.0.order_varies', false)
            ->assertJsonPath('source_prices.0.price_note', null)
            ->assertJsonPath('source_prices.0.price_carton_qty', null);
        $this->getJson('/api/products/'.$card->id)
            ->assertOk()
            ->assertJsonPath('order_quantity', null)
            ->assertJsonPath('source_prices.0.order_min_qty', null)
            ->assertJsonPath('source_prices.0.order_varies', false)
            ->assertJsonPath('source_prices.1.source_key', ProductSourcePrice::b2bKey($ardon->id))
            ->assertJsonPath('source_prices.1.order_min_qty', 12)
            ->assertJsonPath('source_prices.1.order_step_qty', 12)
            ->assertJsonPath('source_prices.1.order_unit', 'par');
    }

    public function test_tender_main_product_carries_order_quantity(): void
    {
        [$goggles, $uvex] = $this->uvexGogglesCard();
        $other = $this->card('Ansell');
        $tender = Tender::query()->create([
            'number' => 'PRZ/OQ/1',
            'title' => 'Gogle',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => $this->user->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'target_margin_percent' => 20,
            'last_activity_at' => now(),
        ]);
        foreach ([[1, $goggles], [2, $other]] as [$lineNo, $product]) {
            TenderItem::query()->create([
                'tender_id' => $tender->id, 'line_no' => $lineNo, 'requirement' => 'Gogle ochronne', 'quantity' => 25,
                'main_product_id' => $product->id, 'offer_price' => 50.00, 'ai_match_percent' => 90, 'status' => 'ok',
                'ai_match_reasons' => ['dopasowanie ręczne'],
            ]);
        }

        $items = collect($this->getJson('/api/tenders/'.$tender->id)->assertOk()->json('tender.items'))->keyBy('line_no');

        $this->assertSame(10, $items[1]['main_product']['order_quantity']['step']);
        $this->assertSame(ProductSourcePrice::b2bKey($uvex->id), $items[1]['main_product']['order_quantity']['source_key']);
        $this->assertArrayHasKey('order_quantity', $items[2]['main_product']);
        $this->assertNull($items[2]['main_product']['order_quantity']);
    }

    /**
     * Gogle UVEX: jedno konto producenta, koszyk przyjmuje tylko wielokrotności 10 szt.
     *
     * @return array{0: Product, 1: B2bAccount}
     */
    private function uvexGogglesCard(): array
    {
        $card = $this->card('UVEX');
        $uvex = $this->account('uvex');
        B2bProductLink::query()->create(['b2b_account_id' => $uvex->id, 'remote_id' => 'R'.$uvex->id, 'product_id' => $card->id]);
        app(ProductEffectivePrice::class)->saveSlot($card, ProductSourcePrice::b2bKey($uvex->id), [
            'b2b_account_id' => $uvex->id, 'catalog_price_net' => 45.00, 'purchase_price' => 40.00, 'currency' => 'PLN',
            'order_min_qty' => 10, 'order_step_qty' => 10, 'order_unit' => 'szt', 'order_varies' => false,
            'checked_at' => Carbon::parse('2026-09-22 10:00'),
        ]);

        return [$card->fresh(), $uvex];
    }

    /**
     * Karta ATG: konto producenta 10,00 zł obowiązuje, dystrybutor Ardon 9,00 zł.
     *
     * @return array{0: Product, 1: B2bAccount, 2: B2bAccount}
     */
    private function manufacturerAndDistributorCard(): array
    {
        $card = $this->card('ATG');
        $atg = $this->account('atg');
        $ardon = $this->account('ardon');
        $prices = app(ProductEffectivePrice::class);
        foreach ([[$atg, 10.00, '2026-09-20 10:00'], [$ardon, 9.00, '2026-09-22 10:00']] as [$account, $price, $checked]) {
            B2bProductLink::query()->create([
                'b2b_account_id' => $account->id, 'remote_id' => 'R'.$account->id, 'product_id' => $card->id,
            ]);
            $prices->saveSlot($card, ProductSourcePrice::b2bKey($account->id), [
                'b2b_account_id' => $account->id, 'catalog_price_net' => $price, 'purchase_price' => $price,
                'currency' => 'PLN', 'checked_at' => Carbon::parse($checked),
            ]);
        }

        return [$card->fresh(), $atg, $ardon];
    }

    private function card(string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => 'API-'.$manufacturer,
            'name' => 'Rękawice '.$manufacturer,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 20.00,
            'discount_percent' => 0,
            'purchase_price' => 20.00,
            'currency' => 'PLN',
            'stock' => 1,
        ]);
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $connector.'-login', 'password' => 'sekret', 'sites' => ['b2b.example.pl'], 'connector' => $connector,
            'created_by' => $this->user->id, 'updated_by' => $this->user->id,
        ]);
    }

    private function fakeNbp(): void
    {
        Http::fake([
            'api.nbp.pl/*' => Http::response([[
                'effectiveDate' => '2026-09-22',
                'rates' => [['code' => 'EUR', 'mid' => 4.0]],
            ]]),
        ]);
    }
}
