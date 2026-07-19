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

final class TenderCoverageAndAuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_coverage_and_explain_and_comment_and_activity(): void
    {
        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());

        $product = Product::query()->create([
            'sku' => 'RNITZ-1',
            'name' => 'Rekawice nitrylowe RNITZ',
            'manufacturer' => 'REJS',
            'category' => 'Rekawice',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 10,
        ]);

        $owner = User::factory()->create();
        $client = Client::query()->create(['name' => 'Klient']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/COV/1',
            'title' => 'Coverage',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);

        $empty = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rekawice nitrylowe',
            'quantity' => 10,
            'status' => 'brak',
        ]);

        $matched = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 2,
            'requirement' => 'Rekawice nitrylowe RNITZ',
            'main_product_id' => $product->id,
            'ai_match_percent' => 80,
            'offer_price' => 8,
            'quantity' => 5,
            'status' => 'matched',
        ]);

        $this->getJson("/api/tenders/{$tender->id}/coverage")
            ->assertOk()
            ->assertJsonPath('without_product', 1)
            ->assertJsonPath('without_price', 1)
            ->assertJsonPath('ready', false);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$empty->id}", [
            'main_product_id' => $product->id,
            'quantity' => 10,
        ])->assertOk()
            ->assertJsonStructure(['ai_match_reasons']);

        $this->postJson("/api/tenders/{$tender->id}/comments", [
            'body' => 'Sprawdzic cene pozycji 2',
            'tender_item_id' => $matched->id,
        ])->assertCreated();

        $this->getJson("/api/tenders/{$tender->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Sprawdzic cene pozycji 2');

        $this->getJson("/api/tenders/{$tender->id}/activities")
            ->assertOk()
            ->assertJsonFragment(['action' => 'item_updated']);

        $this->getJson('/api/reports/summary')->assertOk()->assertJsonStructure(['by_status', 'by_owner']);
    }

    public function test_reject_requires_note(): void
    {
        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());

        $owner = User::factory()->create();
        $client = Client::query()->create(['name' => 'Klient']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/COV/2',
            'title' => 'Reject',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'akceptacja_km',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);

        $this->postJson("/api/tenders/{$tender->id}/transition", [
            'status' => 'odrzucony',
        ])->assertStatus(422);

        $this->postJson("/api/tenders/{$tender->id}/transition", [
            'status' => 'odrzucony',
            'note' => 'Za niska marza na pakiecie',
        ])->assertOk()->assertJsonPath('tender.status', 'odrzucony');
    }
}
