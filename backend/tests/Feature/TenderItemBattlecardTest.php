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

final class TenderItemBattlecardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_battlecard_returns_main_and_two_substitutes(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $ours = Product::query()->create([
            'sku' => 'RNITZ-OURS',
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe RNITZ',
            'catalog_price_net' => 3.50,
            'purchase_price' => 2.00,
            'stock' => 100,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['nitryl']],
            'enriched_at' => now(),
        ]);

        $sub = Product::query()->create([
            'sku' => 'RNITZ-SUB',
            'name' => 'Rękawice nitrylowe zamiennik',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Zamiennik nitryl RNITZ',
            'catalog_price_net' => 2.80,
            'purchase_price' => 1.80,
            'stock' => 50,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['nitryl']],
            'enriched_at' => now(),
        ]);

        $alt = Product::query()->create([
            'sku' => 'NITRIL-ALT',
            'name' => 'Rękawice nitrylowe katalog',
            'manufacturer' => 'OTHERBRAND',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe ze ściągaczem',
            'catalog_price_net' => 2.40,
            'purchase_price' => 1.50,
            'stock' => 20,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['nitryl']],
            'enriched_at' => now(),
        ]);

        ProductSubstitute::query()->create([
            'main_product_id' => $ours->id,
            'substitute_product_id' => $sub->id,
            'type' => 'tanszy',
            'match_percent' => 92,
            'approval_status' => 'zatwierdzony',
            'reason' => 'Tańszy odpowiednik',
        ]);

        $owner = User::factory()->create();
        $client = Client::query()->create(['name' => 'Klient BC']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/BC/1',
            'title' => 'Battlecard',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 80,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice robocze nitrylowe REJS RNITZ kat. 2 ze ściągaczem',
            'main_product_id' => $ours->id,
            'ai_match_percent' => 88,
            'ai_match_reasons' => [
                ['code' => 'sku', 'label' => 'SKU', 'points' => 40],
            ],
            'match_source' => 'heuristic',
            'quantity' => 100,
            'offer_price' => 3.90,
            'status' => 'ok',
        ]);

        $res = $this->getJson("/api/tenders/{$tender->id}/items/{$item->id}/battlecard")
            ->assertOk()
            ->assertJsonPath('battlecard.ours.sku', 'RNITZ-OURS')
            ->assertJsonPath('battlecard.ours.offer_price', 3.9)
            ->assertJsonPath('battlecard.ours.suggested_offer_price', 2.36)
            ->assertJsonPath('battlecard.competitors', []);

        $subs = $res->json('battlecard.substitutes');
        $this->assertCount(2, $subs);
        $skus = collect($subs)->pluck('sku')->all();
        $this->assertContains('RNITZ-SUB', $skus);
        $this->assertContains($alt->sku, $skus);
        // najtańszy po upuście pierwszy
        $this->assertSame('NITRIL-ALT', $subs[0]['sku']);
    }

    public function test_apply_cheaper_substitutes_dry_run_and_apply(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $ours = Product::query()->create([
            'sku' => 'EXPENSIVE',
            'name' => 'Rękawice drogie',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe drogie',
            'catalog_price_net' => 10,
            'purchase_price' => 10,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['nitryl']],
            'enriched_at' => now(),
        ]);
        $cheap = Product::query()->create([
            'sku' => 'CHEAP-SUB',
            'name' => 'Rękawice tanie',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe tanie',
            'catalog_price_net' => 5,
            'purchase_price' => 5,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['nitryl']],
            'enriched_at' => now(),
        ]);
        ProductSubstitute::query()->create([
            'main_product_id' => $ours->id,
            'substitute_product_id' => $cheap->id,
            'type' => 'tanszy',
            'match_percent' => 90,
            'approval_status' => 'zatwierdzony',
        ]);

        $owner = User::factory()->create();
        $client = Client::query()->create(['name' => 'Klient CHEAP']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/BC/CHEAP',
            'title' => 'Tańsze',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 50,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice robocze nitrylowe',
            'main_product_id' => $ours->id,
            'ai_match_percent' => 80,
            'quantity' => 10,
            'offer_price' => 12,
            'status' => 'ok',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/items/apply-cheaper-substitutes", [
            'dry_run' => true,
            'min_save_percent' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('candidates_count', 1)
            ->assertJsonPath('candidates.0.to_sku', 'CHEAP-SUB');

        $this->assertSame($ours->id, $item->fresh()->main_product_id);

        $this->postJson("/api/tenders/{$tender->id}/items/apply-cheaper-substitutes", [
            'dry_run' => false,
            'min_save_percent' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('applied_count', 1)
            ->assertJsonPath('applied.0.to_sku', 'CHEAP-SUB');

        $item->refresh();
        $this->assertSame($cheap->id, $item->main_product_id);
        $this->assertEquals(5.9, (float) $item->offer_price);
    }

    public function test_match_item_includes_battlecard(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = Product::query()->create([
            'sku' => 'RNITZ-M',
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe RNITZ ze ściągaczem',
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['nitryl']],
            'enriched_at' => now(),
        ]);

        $owner = User::factory()->create();
        $client = Client::query()->create(['name' => 'Klient M']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/BC/2',
            'title' => 'Match BC',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Rękawice robocze nitrylowe REJS RNITZ kat. 2 ze ściągaczem',
            'main_product_id' => $product->id,
            'ai_match_percent' => 90,
            'quantity' => 10,
            'status' => 'ok',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/items/{$item->id}/match")
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonStructure(['battlecard' => ['ours', 'substitutes', 'competitors', 'highlights']]);
    }
}
