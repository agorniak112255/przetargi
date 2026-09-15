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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decyzja użytkownika 15.09.2026: pozycja przetargu trzyma cenę oferty z chwili dopasowania. Zmiana ceny karty
 * po imporcie cennika nie może cicho zmienić oferty przy późniejszej zmianie marży docelowej.
 */
final class TenderMarginKeepsOfferPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_margin_change_scales_offer_from_match_time_not_from_new_card_price(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $main = $this->makeProduct('MAIN', 10);
        $companion = $this->makeProduct('COMP', 5);
        $tender = $this->makeTender(18);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice z wkładką',
            'quantity' => 2,
            'status' => 'brak',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'main_product_id' => $main->id,
            'companion_product_id' => $companion->id,
        ])
            ->assertOk()
            ->assertJsonPath('offer_price', '11.80')
            ->assertJsonPath('companion_offer_price', '5.90');

        // import cennika po dopasowaniu podnosi ceny kart
        $main->update(['purchase_price' => 20, 'catalog_price_net' => 24]);
        $companion->update(['purchase_price' => 50, 'catalog_price_net' => 60]);

        $this->patchJson("/api/tenders/{$tender->id}", ['target_margin_percent' => 20])->assertOk();

        $item->refresh();
        // 11.80 × 1.20 / 1.18 — nie 20 × 1.20 = 24.00
        $this->assertSame('12.00', (string) $item->offer_price);
        // 5.90 × 1.20 / 1.18 — nie 50 × 1.20 = 60.00
        $this->assertSame('6.00', (string) $item->companion_offer_price);
        // marża pokazuje realny koszt z bieżących cen kart: (18.00 − 70) / 18.00
        $this->assertSame('-288.89', (string) $item->margin_percent);
        $this->assertSame('36.00', (string) $tender->fresh()->offer_value_net);
    }

    public function test_item_without_offer_price_still_gets_offer_from_card(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct('NOOFFER', 10);
        $tender = $this->makeTender(18);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice',
            'quantity' => 1,
            'main_product_id' => $product->id,
            'offer_price' => null,
            'status' => 'matched',
        ]);
        $product->update(['purchase_price' => 20]);

        $this->patchJson("/api/tenders/{$tender->id}", ['target_margin_percent' => 20])->assertOk();

        // brak ceny do utrzymania — dotychczasowe wyliczenie z bieżącej ceny karty
        $this->assertSame('24.00', (string) $item->fresh()->offer_price);
    }

    private function makeProduct(string $sku, float $purchase): Product
    {
        return Product::query()->create([
            'sku' => 'SKU-KEEP-'.$sku,
            'name' => 'Produkt '.$sku,
            'manufacturer' => 'X',
            'category' => 'Rękawice',
            'description' => 'Produkt testowy '.$sku,
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
            'number' => 'PRZ/KEEP/'.uniqid(),
            'title' => 'Marża trzyma ofertę',
            'client_id' => Client::query()->create(['name' => 'Klient '.uniqid()])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'target_margin_percent' => $targetMargin,
            'last_activity_at' => now(),
        ]);
    }
}
