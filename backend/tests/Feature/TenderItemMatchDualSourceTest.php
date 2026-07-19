<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class TenderItemMatchDualSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_match_item_returns_ai_top_candidates(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);

        $ai = Product::query()->create([
            'sku' => 'RNITZ-AI',
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe RNITZ ze ściągaczem.',
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['nitryl']],
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => 'BOOT-X',
            'name' => 'GLOSS UP WINTER S3',
            'manufacturer' => 'X',
            'category' => 'Obuwie',
            'description' => 'Buty S3 winter',
            'catalog_price_net' => 90,
            'purchase_price' => 50,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'matches' => [
                ['id' => $ai->id, 'score' => 88, 'reason' => 'Nitryl + ściągacz + RNITZ'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $owner = User::factory()->create();
        $client = Client::query()->create(['name' => 'Klient Test']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/TEST/1',
            'title' => 'Test',
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
            'quantity' => 100,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/items/{$item->id}/match", ['force' => true])
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('product.id', $ai->id)
            ->assertJsonPath('sources.ai.0.score', 88)
            ->assertJsonPath('sources.ai.0.sku', 'RNITZ-AI')
            ->assertJsonStructure(['candidates', 'sources' => ['heuristic', 'ai']]);
    }
}
