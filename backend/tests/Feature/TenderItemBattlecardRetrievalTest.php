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
 * Zapas zamienników w battlecardzie brał pierwsze 20 kart rodziny w kolejności bazy i dopiero je
 * oceniał. Przy rodzinach po 1000–3000 kart pasująca karta prawie nigdy nie mieściła się w tym
 * wycinku — a gdy rodziny wymagania nie dało się rozpoznać, brane było pierwsze 20 kart całego
 * katalogu. Kandydaci mają pochodzić z tego samego retrievalu co wyszukiwarka AI: po treści wymagania.
 */
final class TenderItemBattlecardRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_matching_card_buried_behind_thirty_family_cards_still_becomes_a_substitute(): void
    {
        $ours = $this->glove('NASZA-PCV', 'Rękawice PCV długie do łokcia 600 mm', 'Rękawice PCV długie do łokcia, 600 mm.', ['pcv'], 30);
        // Trzydzieści kart tej samej rodziny założonych WCZEŚNIEJ niż pasująca — stary wycinek
        // „pierwsze 20 z bazy” kończył się, zanim do niej doszedł.
        for ($n = 1; $n <= 30; $n++) {
            $this->glove('SKORA-'.$n, 'Rękawice skórzane wzmacniane '.$n, 'Rękawice skórzane pięciopalcowe, krótkie.', ['skóra'], 5);
        }
        $buried = $this->glove('PCV-ZAKOPANA', 'Rękawice PCV długie do łokci 650 mm', 'Rękawice PCV o długości 650 mm, długie do łokci.', ['pcv', 'pvc'], 9);

        $item = $this->itemFor('Rękawice PCV długie do łokci', $ours);

        $skus = collect(
            $this->getJson("/api/tenders/{$item->tender_id}/items/{$item->id}/battlecard")
                ->assertOk()
                ->json('battlecard.substitutes')
        )->pluck('sku')->all();

        $this->assertContains($buried->sku, $skus, 'pasująca karta spoza pierwszych 20 kart rodziny nie trafiła do zamienników');
        $this->assertNotContains('SKORA-1', $skus, 'karta z innego materiału weszła jako zamiennik');
    }

    /** @param  list<string>  $materials */
    private function glove(string $sku, string $name, string $description, array $materials, float $purchase): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'TEST',
            'category' => 'Rękawice',
            'description' => $description,
            'catalog_price_net' => $purchase * 1.2,
            'purchase_price' => $purchase,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => $materials],
            'enriched_at' => now(),
        ]);
    }

    private function itemFor(string $requirement, Product $ours): TenderItem
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/BC/'.uniqid(),
            'title' => 'Battlecard retrieval',
            'client_id' => Client::query()->create(['name' => 'Klient '.uniqid()])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 90,
            'last_activity_at' => now(),
        ]);

        return TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => $requirement,
            'main_product_id' => $ours->id,
            'ai_match_percent' => 99,
            'quantity' => 10,
            'status' => 'ok',
        ]);
    }
}
