<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class ProductAiSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_ai_search_ranks_matching_products(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $match = Product::query()->create([
            'sku' => 'GLOVE-NH3',
            'name' => 'Rękawice chemiczne AlphaTec',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'norms' => 'EN 374',
            'description' => 'Rękawice odporne na amoniak i kwasy. Praca z chemikaliami.',
            'catalog_price_net' => 12.5,
            'purchase_price' => 8,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'use_cases' => ['praca z amoniakiem', 'laboratorium'],
                'features' => ['odporność chemiczna'],
                'materials' => ['nitryl'],
            ],
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => 'BOOT-01',
            'name' => 'Buty robocze',
            'manufacturer' => 'X',
            'category' => 'Obuwie',
            'norms' => null,
            'description' => 'Obuwie ochronne S3',
            'catalog_price_net' => 90,
            'purchase_price' => 50,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn(
                [
                    'needed' => 'rękawice ochronne do pracy z amoniakiem',
                    'search_phrases' => ['rękawice chemiczne', 'amoniak', 'nitryl'],
                ],
                [
                    'matches' => [
                        ['id' => $match->id, 'score' => 92, 'reason' => 'Odporność na amoniak'],
                    ],
                ],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.id', $match->id)
            ->assertJsonPath('products.0.ai_match_percent', 92)
            ->assertJsonPath('products.0.ai_match_reason', 'Odporność na amoniak');
    }

    public function test_ai_search_requires_query(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->postJson('/api/products/ai-search', ['query' => 'ab'])
            ->assertStatus(422);
    }

    public function test_lab_coat_is_ranked_by_model_not_catalog_fallback(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $coat = Product::query()->create([
            'sku' => 'LAB-COAT',
            'name' => 'Fartuch laboratoryjny elano-bawełna',
            'manufacturer' => 'X',
            'category' => 'Odzież',
            'description' => 'Fartuch lab. biały, zatrzaski, gramatura 210g',
            'catalog_price_net' => 40,
            'purchase_price' => 25,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => 'PROS-106',
            'name' => '106 OB ZIMA BEZ PODNOSKA',
            'manufacturer' => 'URGENT',
            'category' => 'Obuwie',
            'description' => 'Trzewiki zimowe bez podnoska',
            'catalog_price_net' => 33,
            'purchase_price' => 29,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn(
                [
                    'needed' => 'fartuch laboratoryjny elano-bawełna',
                    'search_phrases' => ['fartuch', 'kitel', 'elano-bawełna', 'laboratoryjny'],
                ],
                [
                    'matches' => [
                        ['id' => $coat->id, 'score' => 91, 'reason' => 'Fartuch lab., elano-bawełna, ISO 13688'],
                    ],
                ],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'FARTUCH LAB. ELANO-BAWEŁNA prosty, biały, rękawy wykończone zatrzaską. EN ISO 13688',
        ])
            ->assertOk()
            ->assertJsonPath('needed', 'fartuch laboratoryjny elano-bawełna')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.id', $coat->id)
            ->assertJsonPath('products.0.ai_match_percent', 91);
    }

    public function test_empty_model_ranking_does_not_invent_catalog_hits(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'LAB-COAT',
            'name' => 'Fartuch laboratoryjny elano-bawełna',
            'manufacturer' => 'X',
            'category' => 'Odzież',
            'description' => 'Fartuch lab.',
            'catalog_price_net' => 40,
            'purchase_price' => 25,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'PROS-106',
            'name' => '106 OB ZIMA BEZ PODNOSKA',
            'manufacturer' => 'URGENT',
            'category' => 'Obuwie',
            'description' => 'Trzewiki zimowe',
            'catalog_price_net' => 33,
            'purchase_price' => 29,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn(
                [
                    'needed' => 'fartuch laboratoryjny',
                    'search_phrases' => ['fartuch', 'kitel'],
                ],
                ['matches' => []],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'FARTUCH LAB. ELANO-BAWEŁNA prosty, biały. EN ISO 13688',
        ])
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('products', [])
            ->assertJsonPath('external_hint', null);
    }

    public function test_empty_catalog_returns_external_link_not_product(): void
    {
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

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(
            [
                'needed' => 'ocieplana kurtka ochronna multi-ochronna',
                'search_phrases' => ['kurtka ochronna', 'ocieplana'],
            ],
            ['matches' => []],
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake([
            'api.tavily.com/search' => Http::response([
                'results' => [[
                    'url' => 'https://example.com/kurtka-ochronna',
                    'title' => 'Kurtka ochronna — karta producenta',
                ]],
            ], 200),
        ]);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Kurtka ochronna ocieplana z odpinanym kapturem, 1 klasa widzialności',
        ])
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('products', [])
            ->assertJsonPath('external_hint.url', 'https://example.com/kurtka-ochronna');
    }
}
