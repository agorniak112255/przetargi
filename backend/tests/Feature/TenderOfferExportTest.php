<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenderOfferExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_excel_export_contains_battlecard_columns(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $ours = Product::query()->create([
            'sku' => 'EXP-1',
            'name' => 'Uncoated',
            'manufacturer' => 'ATG',
            'category' => 'Rękawice',
            'description' => 'Rękawica robocza ATG to liner ochronny bezpowłokowy.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['HPPE']],
        ]);
        $sub = Product::query()->create([
            'sku' => 'EXP-SUB',
            'name' => 'Rękawice zamiennik',
            'manufacturer' => 'ATG',
            'category' => 'Rękawice',
            'description' => 'Rękawice zamiennik tanie',
            'catalog_price_net' => 8,
            'purchase_price' => 5,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['nitryl']],
        ]);
        ProductSubstitute::query()->create([
            'main_product_id' => $ours->id,
            'substitute_product_id' => $sub->id,
            'type' => 'tanszy',
            'match_percent' => 88,
            'approval_status' => 'zatwierdzony',
        ]);

        $owner = User::factory()->create();
        $client = Client::query()->create(['name' => 'Klient EXP']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/EXP/1',
            'title' => 'Eksport',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 70,
            'last_activity_at' => now(),
        ]);
        TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice ochronne',
            'main_product_id' => $ours->id,
            'ai_match_percent' => 80,
            'ai_match_reasons' => [
                ['code' => 'sku', 'label' => 'Zgodność SKU', 'points' => 40],
            ],
            'match_source' => 'heuristic',
            'quantity' => 10,
            'offer_price' => 12,
            'status' => 'ok',
        ]);

        $res = $this->get("/api/tenders/{$tender->id}/export/excel");
        $res->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $res->headers->get('content-type')
        );
    }
}
