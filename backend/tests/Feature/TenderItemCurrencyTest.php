<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenderItemCurrencyTest extends TestCase
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
                'rates' => [
                    ['code' => 'EUR', 'mid' => 4.0],
                ],
            ]]),
        ]);
    }

    public function test_selecting_eur_product_sets_offer_in_pln(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->eurHoodie();
        $tender = $this->tender(18);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Bluza damska',
            'quantity' => 1,
            'status' => 'brak',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'main_product_id' => $product->id,
        ])
            ->assertOk()
            ->assertJsonPath('offer_price', '365.04');

        $item->refresh();
        $this->assertEquals(365.04, (float) $item->offer_price);
    }

    public function test_battlecard_shows_eur_prices_converted_to_pln(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->eurHoodie();
        $tender = $this->tender(18);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Bluza damska z kapturem',
            'quantity' => 1,
            'main_product_id' => $product->id,
            'offer_price' => 91.26,
            'ai_match_percent' => 90,
            'status' => 'ok',
        ]);

        $this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")
            ->assertOk()
            ->assertJsonPath('battlecard.ours.sku', 'EY212GF')
            ->assertJsonPath('battlecard.ours.source_currency', 'EUR')
            ->assertJsonPath('battlecard.ours.currency', 'PLN')
            ->assertJsonPath('battlecard.ours.purchase_price', 309.36)
            ->assertJsonPath('battlecard.ours.catalog_price_net', 347.6)
            ->assertJsonPath('battlecard.ours.suggested_offer_price', 365.04)
            ->assertJsonPath('battlecard.ours.offer_price', 365.04);
    }

    public function test_tender_show_includes_main_product_purchase_in_pln(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->eurHoodie();
        $tender = $this->tender(18);
        TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Bluza damska',
            'quantity' => 1,
            'main_product_id' => $product->id,
            'offer_price' => 91.26,
            'ai_match_percent' => 90,
            'ai_match_reasons' => [['code' => 'test', 'label' => 'test', 'points' => 90]],
            'status' => 'ok',
        ]);

        $this->getJson("/api/tenders/{$tender->id}")
            ->assertOk()
            ->assertJsonPath('tender.items.0.main_product.sku', 'EY212GF')
            ->assertJsonPath('tender.items.0.main_product.purchase_price_pln', 309.36);
    }

    private function eurHoodie(): Product
    {
        return Product::query()->create([
            'sku' => 'EY212GF',
            'name' => 'JUPITER LADY Grey Fucsia',
            'manufacturer' => 'U-Power',
            'category' => 'Odzież',
            'description' => 'Bluza damska z kapturem',
            'catalog_price_net' => 86.90,
            'purchase_price' => 77.34,
            'currency' => 'EUR',
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    private function tender(float $targetMargin): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/FX/'.uniqid(),
            'title' => 'Waluta',
            'client_id' => Client::query()->create(['name' => 'Klient FX'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'target_margin_percent' => $targetMargin,
            'last_activity_at' => now(),
        ]);
    }
}
