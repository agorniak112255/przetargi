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
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class CatalogDescriptionMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'tavily_api_key' => 'tvly-test',
        ]);
    }

    public function test_ai_search_skips_products_without_description(): void
    {
        Product::query()->create([
            'sku' => 'BARE-1',
            'name' => 'Fartuch laboratoryjny',
            'manufacturer' => 'X',
            'category' => 'Odzież',
            'description' => null,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'fartuch laboratoryjny',
            'search_phrases' => ['fartuch'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        Http::fake();

        $this->postJson('/api/products/ai-search', [
            'query' => 'FARTUCH LAB. ELANO-BAWEŁNA prosty biały EN ISO 13688',
        ])
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('products', []);
    }

    public function test_no_catalog_match_saves_external_link_not_product(): void
    {
        Product::query()->create([
            'sku' => 'BARE-2',
            'name' => '1019 ZIMA',
            'manufacturer' => 'URGENT',
            'description' => null,
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'fartuch laboratoryjny',
            'search_phrases' => ['fartuch'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake([
            'api.tavily.com/search' => Http::response([
                'results' => [[
                    'url' => 'https://example.com/lab-coat',
                    'title' => 'Fartuch lab. — karta producenta',
                ]],
            ], 200),
        ]);

        $owner = User::factory()->create();
        $tender = Tender::query()->create([
            'number' => 'PRZ/EXT/1',
            'title' => 'Test',
            'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'FARTUCH LAB. ELANO-BAWEŁNA prosty, biały, EN ISO 13688',
            'quantity' => 10,
            'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/items/{$item->id}/match", ['force' => true])
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('product_id', null);

        $item->refresh();
        $this->assertNull($item->main_product_id);
        $this->assertSame('matched', $item->status);
        $this->assertSame('external', $item->match_source);
        $this->assertSame('https://example.com/lab-coat', $item->custom_url);
    }
}
