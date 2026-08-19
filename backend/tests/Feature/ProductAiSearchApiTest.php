<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                    'product_type' => 'rękawice',
                    'search_terms' => ['rękawice', 'amoniak', 'chemiczne'],
                    'exclude_types' => ['obuwie'],
                    'norms' => ['EN 374'],
                    'chemicals' => ['amoniak'],
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
                    'product_type' => 'fartuch laboratoryjny',
                    'search_terms' => ['fartuch', 'kitel', 'elano-bawełna', 'laboratoryjny'],
                    'exclude_types' => ['rękawice', 'obuwie', 'kombinezon'],
                    'norms' => ['EN ISO 13688'],
                    'chemicals' => [],
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
            ->assertJsonPath('facets.product_type', 'fartuch')
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
                    'product_type' => 'fartuch',
                    'search_terms' => ['fartuch', 'kitel'],
                    'exclude_types' => ['obuwie'],
                    'norms' => [],
                    'chemicals' => [],
                ],
                ['matches' => []],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'FARTUCH LAB. ELANO-BAWEŁNA prosty, biały. EN ISO 13688',
        ])
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('products', []);
    }
}
