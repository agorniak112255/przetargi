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
            ->once()
            ->andReturn(
                [
                    'needed' => 'fartuch laboratoryjny elano-bawełna',
                    'search_phrases' => ['fartuch', 'kitel', 'elano-bawełna', 'laboratoryjny'],
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
            ->assertJsonPath('products.0.sku', 'LAB-COAT');
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
            ->once()
            ->andReturn(
                [
                    'needed' => 'fartuch laboratoryjny',
                    'search_phrases' => ['fartuch', 'kitel'],
                ],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'FARTUCH LAB. ELANO-BAWEŁNA prosty, biały. EN ISO 13688',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'LAB-COAT')
            ->assertJsonMissing(['sku' => 'PROS-106']);
    }

    public function test_ai_search_finds_sku_without_description(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $mask = Product::query()->create([
            'sku' => '6503-EN',
            'name' => 'Półmaska 6503 część twarzowa, rozmiar: L duży',
            'manufacturer' => '3M',
            'description' => null,
            'catalog_price_net' => 28.64,
            'purchase_price' => 20,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
        Product::query()->create([
            'sku' => 'HF-803',
            'name' => '3M Secure Click Półmaska HF-803',
            'manufacturer' => '3M',
            'description' => 'Półmaska wielokrotnego użytku Secure Click z opisem karty katalogowej.',
            'catalog_price_net' => 40,
            'purchase_price' => 30,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn(
                [
                    'needed' => 'półmaska 3M 6503',
                    'search_phrases' => ['półmaska', '6503'],
                ],
                [
                    'matches' => [
                        ['id' => $mask->id, 'score' => 94, 'reason' => 'Kod 6503 w SKU'],
                    ],
                ],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Półmaska 3M 6503',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', '6503-EN');
    }

    public function test_ai_search_finds_catalog_model_despite_siwz_typo(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $mapa = Product::query()->create([
            'sku' => '34700018',
            'name' => 'TEMP-ICE 700',
            'manufacturer' => 'MAPA',
            'description' => null,
            'catalog_price_net' => 4.9,
            'purchase_price' => 3,
            'stock' => 8,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
        Product::query()->create([
            'sku' => '60592',
            'name' => 'Rękawice zimowe Unilite Thermo Plus',
            'manufacturer' => 'uvex',
            'norms' => 'EN 388, EN 511, EN ISO 21420',
            'description' => 'Rękawice zimowe Unilite Thermo Plus zgodne z EN 388 EN 511 EN ISO 21420.',
            'catalog_price_net' => 28.6,
            'purchase_price' => 21.45,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturn(
                [
                    'needed' => 'rękawice MAPA TEPM-ICE 700',
                    'search_phrases' => ['mapa', 'tepm-ice', 'rękawice zimowe'],
                ],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice MAPA TEPM-ICE 700 · EN 388 EN 511 EN ISO 21420',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', '34700018')
            ->assertJsonPath('products.0.id', $mapa->id)
            ->assertJsonMissing(['sku' => '60592']);
    }

    public function test_ai_search_typo_not_blocked_by_en_norm_numbers(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        for ($i = 1; $i <= 45; $i++) {
            Product::query()->create([
                'sku' => 'N21420-'.$i,
                'name' => 'Rękawice zgodne z EN ISO 21420 seria '.$i,
                'manufacturer' => 'Inna',
                'description' => 'Karta z opisem normy EN ISO 21420 dla serii '.$i.'.',
                'catalog_price_net' => 10 + $i,
                'purchase_price' => 8,
                'stock' => 1,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }
        Product::query()->create([
            'sku' => '34700018',
            'name' => 'TEMP-ICE 700',
            'manufacturer' => 'MAPA',
            'description' => null,
            'catalog_price_net' => 4.9,
            'purchase_price' => 3,
            'stock' => 8,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturn([
                'needed' => 'rękawice MAPA TEPM-ICE 700 EN ISO 21420',
                'search_phrases' => ['21420', 'rękawice', 'mapa'],
            ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice MAPA TEPM-ICE 700 · EN ISO 21420 (dawniej EN 420)',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', '34700018')
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
