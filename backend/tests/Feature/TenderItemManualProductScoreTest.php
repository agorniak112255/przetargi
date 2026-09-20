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
 * Ocena dopasowania musi opisywać kartę wpisaną w pozycji, a nie poprzednią. Panel przy „Zapisz”
 * wysyła cenę oferty razem ze zmianą produktu, a przeliczenie oceny wisiało dotąd pod warunkiem
 * „żądanie nie niesie ceny” — więc po ręcznej podmianie wyrobu przy pozycji zostawał procent
 * i uzasadnienie karty, której już tam nie ma.
 */
final class TenderItemManualProductScoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_manual_product_change_with_price_recalculates_score_and_reasons(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $matched = $this->makeProduct('STARY', 'Rękawice powlekane nitrylem EN 388');
        $other = $this->makeProduct('NOWY', 'Hełm ochronny EN 397');
        $tender = $this->makeTender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice powlekane nitrylem EN 388',
            'quantity' => 1,
            'status' => 'matched',
            'main_product_id' => $matched->id,
            'ai_match_percent' => 93,
            'ai_match_reasons' => [['code' => 'ai', 'label' => 'Ocena poprzedniej karty', 'points' => 93]],
            'match_source' => 'ai',
        ]);

        // Ręczna podmiana wyrobu przez panel: produkt i cena oferty w jednym żądaniu.
        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'main_product_id' => $other->id,
            'quantity' => 1,
            'offer_price' => 25.0,
        ])->assertOk();

        $item->refresh();
        $this->assertSame($other->id, $item->main_product_id);
        $this->assertSame('manual', $item->match_source);
        $this->assertNotSame(93, $item->ai_match_percent, 'Przy pozycji został procent poprzedniej karty.');
        $this->assertNotSame(
            'Ocena poprzedniej karty',
            $item->ai_match_reasons[0]['label'] ?? null,
            'Uzasadnienie dalej opisuje poprzednią kartę.'
        );
        $this->assertEquals(25.0, (float) $item->offer_price, 'Cena z żądania ma zostać nietknięta.');
    }

    public function test_quantity_change_keeps_model_score(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $matched = $this->makeProduct('ILOSC', 'Rękawice powlekane nitrylem EN 388');
        $tender = $this->makeTender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice powlekane nitrylem EN 388',
            'quantity' => 1,
            'status' => 'matched',
            'main_product_id' => $matched->id,
            'ai_match_percent' => 93,
            'ai_match_reasons' => [['code' => 'ai', 'label' => 'Ocena modelu', 'points' => 93]],
            'match_source' => 'ai',
        ]);

        // Panel przy „Zapisz” wysyła komplet pól, także gdy zmieniana jest sama ilość.
        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'main_product_id' => $matched->id,
            'quantity' => 7,
            'offer_price' => 25.0,
        ])->assertOk();

        $item->refresh();
        $this->assertSame(7, (int) $item->quantity);
        $this->assertSame(93, $item->ai_match_percent, 'Zmiana ilości skasowała ocenę modelu.');
        $this->assertSame('ai', $item->match_source);
        $this->assertSame('Ocena modelu', $item->ai_match_reasons[0]['label'] ?? null);
    }

    public function test_explicit_score_from_request_is_not_overwritten(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct('WYBOR', 'Rękawice powlekane nitrylem EN 388');
        $tender = $this->makeTender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice powlekane nitrylem EN 388',
            'quantity' => 1,
            'status' => 'brak',
        ]);

        // Wybór z okna wyszukiwania AI: panel przysyła ocenę modelu wraz z uzasadnieniem.
        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'main_product_id' => $product->id,
            'offer_price' => 30.0,
            'ai_match_percent' => 88,
            'ai_match_reasons' => [['code' => 'ai', 'label' => 'Wybór z wyszukiwania AI', 'points' => 88]],
            'match_source' => 'ai',
        ])->assertOk();

        $item->refresh();
        $this->assertSame(88, $item->ai_match_percent);
        $this->assertSame('ai', $item->match_source);
        $this->assertSame('Wybór z wyszukiwania AI', $item->ai_match_reasons[0]['label'] ?? null);
    }

    private function makeProduct(string $sku, string $name): Product
    {
        return Product::query()->create([
            'sku' => 'SKU-MAN-'.$sku,
            'name' => $name,
            'manufacturer' => 'X',
            'category' => 'BHP',
            'description' => $name.' — karta testowa z opisem.',
            'catalog_price_net' => 30,
            'purchase_price' => 20,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    private function makeTender(): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/MAN/'.uniqid(),
            'title' => 'Ręczna podmiana wyrobu',
            'client_id' => Client::query()->create(['name' => 'Klient '.uniqid()])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'target_margin_percent' => 18,
            'last_activity_at' => now(),
        ]);
    }
}
