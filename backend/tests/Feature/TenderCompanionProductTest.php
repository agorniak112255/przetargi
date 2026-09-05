<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\TenderOfferExportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenderCompanionProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_companion_auto_prices_and_counts_in_tender_value(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $jacket = $this->makeProduct(10, 'Bluza KOLPEO');
        $pants = $this->makeProduct(20, 'Spodnie KOLPEO');
        $tender = $this->makeTender(25);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Ubranie (bluza + spodnie do pasa lub ogrodniczki)',
            'quantity' => 2,
            'main_product_id' => $jacket->id,
            'offer_price' => 12.50,
            'status' => 'matched',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'companion_product_id' => $pants->id,
        ])
            ->assertOk()
            ->assertJsonPath('companion_product_id', $pants->id)
            ->assertJsonPath('companion_offer_price', '25.00')
            ->assertJsonPath('companion_product.sku', $pants->sku);

        $tender->refresh();
        $this->assertEquals(75.0, (float) $tender->offer_value_net);

        $item->refresh();
        $this->assertEquals(12.50, (float) $item->offer_price);
        $this->assertEquals(25.00, (float) $item->companion_offer_price);
        $this->assertEquals(20.0, (float) $item->margin_percent);
    }

    public function test_single_product_clears_companion(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $jacket = $this->makeProduct(10, 'Bluza');
        $pants = $this->makeProduct(20, 'Spodnie');
        $other = $this->makeProduct(8, 'Inna bluza');
        $tender = $this->makeTender(25);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Ubranie (bluza + spodnie)',
            'quantity' => 1,
            'main_product_id' => $jacket->id,
            'companion_product_id' => $pants->id,
            'offer_price' => 12.50,
            'companion_offer_price' => 25.00,
            'status' => 'matched',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'main_product_id' => $other->id,
            'companion_product_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath('main_product_id', $other->id)
            ->assertJsonPath('companion_product_id', null)
            ->assertJsonPath('companion_offer_price', null);
    }

    public function test_companion_cannot_equal_main(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $jacket = $this->makeProduct(10, 'Bluza');
        $tender = $this->makeTender(25);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Bluza + spodnie',
            'quantity' => 1,
            'main_product_id' => $jacket->id,
            'offer_price' => 12.50,
            'status' => 'matched',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'companion_product_id' => $jacket->id,
        ])->assertStatus(422);
    }

    public function test_target_margin_reprices_companion(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $jacket = $this->makeProduct(10, 'Bluza');
        $pants = $this->makeProduct(20, 'Spodnie');
        $tender = $this->makeTender(18);
        TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Bluza + spodnie',
            'quantity' => 1,
            'main_product_id' => $jacket->id,
            'companion_product_id' => $pants->id,
            'offer_price' => 11.80,
            'companion_offer_price' => 23.60,
            'status' => 'matched',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}", [
            'target_margin_percent' => 25,
        ])->assertOk();

        $item = $tender->items()->first();
        $this->assertNotNull($item);
        $this->assertEquals(12.5, (float) $item->offer_price);
        $this->assertEquals(25.0, (float) $item->companion_offer_price);
    }

    public function test_export_joins_companion_sku_and_prices(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $jacket = $this->makeProduct(10, 'Bluza KOLPEO');
        $pants = $this->makeProduct(20, 'Spodnie KOLPEO');
        $tender = $this->makeTender(25);
        TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Bluza + spodnie',
            'quantity' => 3,
            'main_product_id' => $jacket->id,
            'companion_product_id' => $pants->id,
            'offer_price' => 12.50,
            'companion_offer_price' => 25.00,
            'status' => 'matched',
        ]);

        $rows = $this->app->make(TenderOfferExportService::class)->rows($tender->fresh());
        $this->assertSame($jacket->sku.' + '.$pants->sku, $rows[0]['sku']);
        $this->assertEquals(30.0, $rows[0]['purchase_price']);
        $this->assertEquals(37.5, $rows[0]['offer_price']);
        $this->assertEquals(112.5, $rows[0]['line_value']);
        $this->assertStringContainsString('Bluza', (string) $rows[0]['product_name']);
        $this->assertStringContainsString('Spodnie', (string) $rows[0]['product_name']);
    }

    private function makeProduct(float $purchase, string $name): Product
    {
        return Product::query()->create([
            'sku' => 'SKU-C-'.uniqid(),
            'name' => $name,
            'manufacturer' => 'Kolpeo',
            'category' => 'Odzież',
            'description' => $name,
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
            'number' => 'PRZ/C/'.uniqid(),
            'title' => 'Komplet',
            'client_id' => Client::query()->create(['name' => 'Klient '.uniqid()])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'target_margin_percent' => $targetMargin,
            'last_activity_at' => now(),
        ]);
    }
}
