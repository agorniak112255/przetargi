<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Support\OfferPricing;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenderTargetMarginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_create_tender_stores_target_margin(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $client = Client::query()->create(['name' => 'Klient M']);

        $this->postJson('/api/tenders', [
            'title' => 'Pakiet',
            'client_id' => $client->id,
            'target_margin_percent' => 25,
        ])
            ->assertCreated()
            ->assertJsonPath('target_margin_percent', '25.00');
    }

    public function test_selecting_product_sets_offer_from_purchase_plus_target_margin(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct(10);
        $tender = $this->makeTender(25);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice',
            'quantity' => 5,
            'status' => 'brak',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'main_product_id' => $product->id,
        ])
            ->assertOk()
            ->assertJsonPath('offer_price', '12.50');
    }

    public function test_changing_target_margin_reprices_catalog_and_scales_external(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct(10);
        $tender = $this->makeTender(18);
        $catalog = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Katalog',
            'quantity' => 1,
            'main_product_id' => $product->id,
            'offer_price' => 11.80,
            'status' => 'matched',
        ]);
        $external = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 2,
            'requirement' => 'Zewnętrzny',
            'quantity' => 1,
            'custom_name' => 'Kalesony web',
            'custom_url' => 'https://example.com/p',
            'match_source' => 'custom',
            'offer_price' => 118,
            'status' => 'matched',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}", [
            'target_margin_percent' => 20,
        ])->assertOk()->assertJsonPath('target_margin_percent', '20.00');

        $this->assertEquals(12.0, (float) $catalog->fresh()->offer_price);
        $this->assertEquals(120.0, (float) $external->fresh()->offer_price);
    }

    public function test_scale_by_margin_change_uses_markup_ratio(): void
    {
        $this->assertEquals(120.0, OfferPricing::scaleByMarginChange(118.0, 18, 20));
        $this->assertEquals(11.8, OfferPricing::fromPurchase(10, 18));
        $this->assertEquals(12.5, OfferPricing::fromPurchase(10, 25));
    }

    private function makeProduct(float $purchase): Product
    {
        return Product::query()->create([
            'sku' => 'SKU-M-'.uniqid(),
            'name' => 'Rękawice test',
            'manufacturer' => 'X',
            'category' => 'Rękawice',
            'description' => 'Rękawice testowe',
            'catalog_price_net' => $purchase * 1.2,
            'purchase_price' => $purchase,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    private function makeTender(float $targetMargin): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/M/'.uniqid(),
            'title' => 'Marża',
            'client_id' => Client::query()->create(['name' => 'Klient '.uniqid()])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'target_margin_percent' => $targetMargin,
            'last_activity_at' => now(),
        ]);
    }
}
