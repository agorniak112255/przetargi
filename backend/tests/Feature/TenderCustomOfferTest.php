<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\TenderOfferExportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenderCustomOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_custom_offer_saves_name_url_price_and_counts_in_coverage(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $tender = $this->makeTender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Gaśnica proszkowa 4 kg',
            'quantity' => 2,
            'status' => 'brak',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'custom_name' => 'Gaśnica GP-4x ABC',
            'custom_url' => 'https://sklep.example/produkt/gasnica-4kg',
            'offer_price' => 89.5,
            'quantity' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('custom_name', 'Gaśnica GP-4x ABC')
            ->assertJsonPath('custom_url', 'https://sklep.example/produkt/gasnica-4kg')
            ->assertJsonPath('match_source', 'custom')
            ->assertJsonPath('status', 'matched')
            ->assertJsonPath('main_product_id', null);

        $item->refresh();
        $this->assertSame('89.50', (string) $item->offer_price);

        $this->getJson("/api/tenders/{$tender->id}/coverage")
            ->assertOk()
            ->assertJsonPath('without_product', 0)
            ->assertJsonPath('without_price', 0)
            ->assertJsonPath('with_product', 1);

        $rows = $this->app->make(TenderOfferExportService::class)->rows($tender->fresh());
        $this->assertSame('Gaśnica GP-4x ABC', $rows[0]['product_name']);
        $this->assertSame('https://sklep.example/produkt/gasnica-4kg', $rows[0]['custom_url']);
    }

    public function test_rejects_custom_url_without_http(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $tender = $this->makeTender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Gaśnica',
            'quantity' => 1,
            'status' => 'brak',
        ]);

        $this->patchJson("/api/tenders/{$tender->id}/items/{$item->id}", [
            'custom_name' => 'Gaśnica',
            'custom_url' => 'sklep.example/gasnica',
        ])->assertStatus(422);
    }

    private function makeTender(): Tender
    {
        $owner = User::factory()->create();

        return Tender::query()->create([
            'number' => 'PRZ/CUST/1',
            'title' => 'Własna oferta',
            'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
    }
}
