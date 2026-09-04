<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
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
            ->andReturn([
                'needed' => 'rękawice ochronne do pracy z amoniakiem',
                'search_phrases' => ['rękawice chemiczne', 'amoniak', 'nitryl'],
                'constraints' => ['amoniak', 'EN 374'],
                'matches' => [
                    ['id' => $match->id, 'score' => 92, 'reason' => 'Odporność na amoniak'],
                ],
            ]);
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
        $llm->shouldNotReceive('chatJson');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'FARTUCH LAB. ELANO-BAWEŁNA prosty, biały, rękawy wykończone zatrzaską. EN ISO 13688',
        ])
            ->assertOk()
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
        $llm->shouldNotReceive('chatJson');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'FARTUCH LAB. ELANO-BAWEŁNA prosty, biały. EN ISO 13688',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'LAB-COAT')
            ->assertJsonMissing(['sku' => 'PROS-106']);
    }

    public function test_ai_search_keeps_named_brand_and_drops_other_makers(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $msa = Product::query()->create([
            'sku' => 'MSA-LOW',
            'name' => 'Ochronniki słuchu na hełm MSA niski tłumienie',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona słuchu',
            'description' => 'Ochronniki słuchu nahełmowe MSA, niski poziom tłumienia SNR 22 dB.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $portwest = Product::query()->create([
            'sku' => 'PW75',
            'name' => 'Ochronniki słuchu na hełm, niski poziom tłumienia SNR 22 dB',
            'manufacturer' => 'Portwest',
            'category' => 'Ochrona słuchu',
            'description' => 'Ochronniki słuchu na hełm, niski poziom tłumienia SNR 22 dB.',
            'catalog_price_net' => 40,
            'purchase_price' => 25,
            'stock' => 6,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'ochronniki słuchu na hełm MSA niski poziom tłumienia',
            'search_phrases' => ['ochronniki słuchu', 'hełm', 'niski poziom tłumienia'],
            'matches' => [
                ['id' => $portwest->id, 'score' => 90, 'reason' => 'Niski poziom tłumienia'],
                ['id' => $msa->id, 'score' => 70, 'reason' => 'MSA'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Ochronniki słuchu na hełm MSA - niski poziom tłumienia',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'MSA-LOW')
            ->assertJsonPath('products.0.id', $msa->id)
            ->assertJsonMissing(['sku' => 'PW75']);
    }

    public function test_ai_search_finds_short_code_p3e_despite_helmet_family(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $adapter = Product::query()->create([
            'sku' => 'P3E',
            'name' => '3M Adapter P3E do mocowania osłony twarzy',
            'manufacturer' => '3M',
            'category' => 'Ochrona twarzy',
            'description' => 'Adapter do mocowania osłony twarzy na hełmie.',
            'catalog_price_net' => 9.04,
            'purchase_price' => 6,
            'stock' => 8,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'FH-934',
            'name' => 'Adapter kaptura ochronnego 3M systemu z wymuszonym przepływem powietrza',
            'manufacturer' => '3M',
            'category' => 'Drogi oddechowe',
            'description' => 'Adapter kaptura ochronnego do systemu z wymuszonym przepływem.',
            'catalog_price_net' => 31.05,
            'purchase_price' => 15.53,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJson');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Adapter P3E do hełmu 3M',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'P3E')
            ->assertJsonPath('products.0.id', $adapter->id)
            ->assertJsonMissing(['sku' => 'FH-934']);
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
            ->andReturn([
                'needed' => 'półmaska 3M 6503',
                'search_phrases' => ['półmaska', '6503'],
                'matches' => [
                    ['id' => $mask->id, 'score' => 94, 'reason' => 'Kod 6503 w SKU'],
                ],
            ]);
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
            'norms' => 'EN 388 EN 511',
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
        $llm->shouldNotReceive('chatJson');
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
            'norms' => 'EN 388 EN 511',
            'description' => null,
            'catalog_price_net' => 4.9,
            'purchase_price' => 3,
            'stock' => 8,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJson');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice MAPA TEPM-ICE 700 · EN ISO 21420 (dawniej EN 420)',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', '34700018')
            ->assertJsonPath('external_hint', null);
    }

    public function test_empty_catalog_does_not_search_web(): void
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
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'ocieplana kurtka ochronna multi-ochronna',
            'search_phrases' => ['kurtka ochronna', 'ocieplana'],
            'matches' => [],
        ]);
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
            ->assertJsonPath('external_hint', null)
            ->assertJsonPath('external_hints', []);

        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'tavily'));
    }

    public function test_ai_search_finds_catalog_filter_with_no_and_skips_a2b2e2k2(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $oxy = Product::query()->create([
            'sku' => 'OXY-203-UP3',
            'name' => 'Filtr 203 UP3 A2-B2-E2-K2-Hg-CO-NO-P3',
            'manufacturer' => 'Oxyline',
            'category' => 'Drogi oddechowe',
            'norms' => 'EN 14387',
            'description' => 'Pochłaniacz wielogazowy z ochroną na tlenki azotu NO.',
            'catalog_price_net' => 45,
            'purchase_price' => 28,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'KWASEK-202',
            'name' => 'Pochłaniacz wielogazowy 202 A2B2E2K2',
            'manufacturer' => 'Kwasek',
            'category' => 'Drogi oddechowe',
            'norms' => 'EN 14387',
            'description' => 'Pochłaniacz wielogazowy A2B2E2K2 bez NO.',
            'catalog_price_net' => 30,
            'purchase_price' => 18,
            'stock' => 6,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->mockCatalogRank(
            'pochłaniacz wielogazowy',
            ['pochłaniacz', 'a2b2e2k2no'],
            [$oxy],
            ['A2B2E2K2NO']
        );

        $this->postJson('/api/products/ai-search', [
            'query' => 'pochłaniacz wielogazowy a2b2e2k2no dodatkowa ochrona na tlenki azoty NO',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'OXY-203-UP3')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('external_hint', null);
    }

    public function test_ai_search_finds_filter_when_no_is_only_in_description(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = Product::query()->create([
            'sku' => 'OXY-203',
            'name' => 'Pochłaniacz 203 UP3 Oxyline',
            'manufacturer' => 'Oxyline',
            'category' => 'Drogi oddechowe',
            'norms' => 'EN 14387',
            'description' => 'Wielogazowy A2B2E2K2 z dodatkową ochroną na tlenki azotu.',
            'catalog_price_net' => 45,
            'purchase_price' => 28,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $product->ppe_family = 'apparel';
        $product->save();

        $this->mockCatalogRank(
            'pochłaniacz wielogazowy',
            ['pochłaniacz', 'a2b2e2k2no'],
            [$product],
            ['NO']
        );

        $this->postJson('/api/products/ai-search', [
            'query' => 'pochłaniacz wielogazowy a2b2e2k2no (dodatkowa ochrona na tlenki azotu)',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'OXY-203')
            ->assertJsonPath('external_hint', null);
    }

    public function test_ai_search_finds_footwear_class_without_description(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $manual = Product::query()->create([
            'sku' => '002-100',
            'name' => 'półbuty S2 na zam.',
            'manufacturer' => 'Reis',
            'category' => 'Obuwie zawodowe',
            'description' => null,
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_MANUAL,
        ]);
        Product::query()->create([
            'sku' => '003-998',
            'name' => 'trzewiki S3 na zam.',
            'manufacturer' => 'Reis',
            'category' => 'Obuwie zawodowe',
            'description' => null,
            'catalog_price_net' => 90,
            'purchase_price' => 55,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_MANUAL,
        ]);
        Product::query()->create([
            'sku' => 'Model 203',
            'name' => 'Półbuty bezpieczne z metalowym podnoskiem białe',
            'manufacturer' => 'PPO',
            'category' => 'Obuwie',
            'description' => 'Półbuty robocze PPO spełniające normę S2.',
            'catalog_price_net' => 70,
            'purchase_price' => 40,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->mockCatalogRank('półbuty S2', ['półbuty', 's2'], [$manual], ['S2']);

        $response = $this->postJson('/api/products/ai-search', [
            'query' => 'półbuty S2',
        ]);
        $response->assertOk();
        $skus = array_column($response->json('products') ?? [], 'sku');
        $this->assertContains('002-100', $skus);
        $this->assertNotContains('003-998', $skus);
        $this->assertSame('002-100', $response->json('products.0.sku'));
        $this->assertSame($manual->id, $response->json('products.0.id'));
    }

    public function test_ai_search_finds_sztyblety_o2_and_filters_reis(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $sztyblet = Product::query()->create([
            'sku' => '000-045',
            'name' => 'sztyblety O2',
            'manufacturer' => 'Reis',
            'category' => 'Obuwie zawodowe',
            'description' => null,
            'catalog_price_net' => 75,
            'purchase_price' => 45,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_MANUAL,
        ]);
        $trzewik = Product::query()->create([
            'sku' => '015-302',
            'name' => 'trzewiki O2',
            'manufacturer' => 'Reis',
            'category' => 'Obuwie zawodowe',
            'description' => null,
            'catalog_price_net' => 85,
            'purchase_price' => 50,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_MANUAL,
        ]);
        Product::query()->create([
            'sku' => 'Model 037',
            'name' => 'Trzewiki bezpieczne z metalowym podnoskiem',
            'manufacturer' => 'PPO',
            'category' => 'Obuwie',
            'description' => 'Trzewiki PPO klasy S1.',
            'catalog_price_net' => 60,
            'purchase_price' => 35,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(function (array $messages) use ($sztyblet, $trzewik): array {
            $content = (string) $messages[1]['content'];
            $requirement = strstr($content, 'Karty katalogu:', true);
            $requirement = mb_strtolower(is_string($requirement) ? $requirement : $content);
            $sztyblety = str_contains($requirement, 'sztyblet');
            $pick = $sztyblety ? $sztyblet : $trzewik;

            return [
                'needed' => $sztyblety ? 'sztyblety O2' : 'trzewiki O2 Reis',
                'search_phrases' => $sztyblety ? ['sztyblety', 'o2'] : ['trzewiki', 'o2', 'reis'],
                'constraints' => ['O2'],
                'matches' => [['id' => $pick->id, 'score' => 90, 'reason' => 'test']],
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $sztyblety = $this->postJson('/api/products/ai-search', [
            'query' => 'sztyblety O2',
        ]);
        $sztyblety->assertOk();
        $this->assertSame(['000-045'], array_column($sztyblety->json('products') ?? [], 'sku'));

        $reis = $this->postJson('/api/products/ai-search', [
            'query' => 'trzewiki O2 Reis',
        ]);
        $reis->assertOk();
        $this->assertSame(['015-302'], array_column($reis->json('products') ?? [], 'sku'));
    }

    public function test_web_search_retries_until_no_class_appears(): void
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
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'pochłaniacz wielogazowy',
            'search_phrases' => ['pochłaniacz'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake([
            'api.tavily.com/search' => Http::sequence()
                ->push([
                    'results' => [[
                        'url' => 'https://kwasek.pl/produkt/pochlaniacz-202-a2b2e2k2',
                        'title' => 'Pochłaniacz wielogazowy 202 A2B2E2K2',
                    ]],
                ], 200)
                ->push([
                    'results' => [[
                        'url' => 'https://oxyline.eu/pl/product/275/filter-203-up3-a2-b2-e2-k2-hg-co-no-p3',
                        'title' => 'Filtr 203 UP3',
                        'content' => 'A2-B2-E2-K2-Hg-CO-NO-P3',
                    ]],
                ], 200),
        ]);

        $this->postJson('/api/products/ai-search', [
            'query' => 'pochłaniacz wielogazowy a2b2e2k2no (dodatkowa ochrona na tlenki azotu)',
            'web' => true,
        ])
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath(
                'external_hint.url',
                'https://oxyline.eu/pl/product/275/filter-203-up3-a2-b2-e2-k2-hg-co-no-p3'
            );
    }

    public function test_web_search_skips_hint_without_no_class(): void
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
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'pochłaniacz wielogazowy',
            'search_phrases' => ['pochłaniacz', 'wielogazowy'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake([
            'api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'url' => 'https://kwasek.pl/produkt/pochlaniacz-202-a2b2e2k2',
                        'title' => 'Pochłaniacz wielogazowy 202 A2B2E2K2 kwasek.pl',
                    ],
                    [
                        'url' => 'https://oxyline.eu/pl/product/275/filter-203-up3-a2-b2-e2-k2-hg-co-no-p3',
                        'title' => 'Filtr 203 UP3 A2 B2 E2 K2 Hg CO NO P3',
                    ],
                ],
            ], 200),
        ]);

        $this->postJson('/api/products/ai-search', [
            'query' => 'pochłaniacz wielogazowy a2b2e2k2no dodatkowa ochrona na tlenki azoty NO',
            'web' => true,
        ])
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath(
                'external_hint.url',
                'https://oxyline.eu/pl/product/275/filter-203-up3-a2-b2-e2-k2-hg-co-no-p3'
            );
    }

    public function test_ai_search_finds_balaclava_without_description_among_described_caps(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        for ($i = 1; $i <= 40; $i++) {
            Product::query()->create([
                'sku' => 'CZAPKA-ZIMOWA-'.$i,
                'name' => 'Czapka zimowa 5'.$i.' polar',
                'manufacturer' => 'Urgent',
                'category' => 'Czapki',
                'description' => 'Czapka zimowa polarowa marki Urgent, model 5'.$i.', ochrona głowy zimą.',
                'catalog_price_net' => 10 + $i,
                'purchase_price' => 8,
                'stock' => 2,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        $balaclava = Product::query()->create([
            'sku' => 'BALTIC',
            'name' => 'KOMINIARKA Z POLARU POLIESTRU, 100 g/m²',
            'manufacturer' => 'Delta Plus',
            'category' => 'EVOLUTION',
            'description' => null,
            'catalog_price_net' => 9.9,
            'purchase_price' => 6,
            'stock' => 12,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        $this->mockCatalogRank(
            'kominiarka polarowa',
            ['kominiarka', 'polar'],
            [$balaclava]
        );

        $this->postJson('/api/products/ai-search', [
            'query' => 'CZAPKA KOMINIARKA Z POLARU czarna lub granatowa',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'BALTIC');
    }

    public function test_ai_search_ranks_card_matching_description_details_over_name_only_hits(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        for ($i = 1; $i <= 40; $i++) {
            Product::query()->create([
                'sku' => 'MACH-'.$i,
                'name' => 'SPODNIE MACH '.$i.' Z POLIESTRU I BAWEŁNY',
                'manufacturer' => 'Delta Plus',
                'category' => 'Odzież robocza',
                'description' => 'Spodnie robocze Mach '.$i.' z poliestru i bawełny, kieszenie boczne.',
                'catalog_price_net' => 100 + $i,
                'purchase_price' => 70,
                'stock' => 3,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        $cxs = Product::query()->create([
            'sku' => 'CXS-STRETCH',
            'name' => 'CXS STRETCH',
            'manufacturer' => 'CANIS SAFETY',
            'category' => 'Odzież robocza',
            'norms' => 'EN 13688',
            'description' => 'Spodnie robocze męskie CXS STRETCH marki CANIS SAFETY. '
                .'Konstrukcja obejmuje podwyższony karczek z tyłu, przy gramaturze 250 g/m².',
            'catalog_price_net' => 95,
            'purchase_price' => 60,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $cards = null;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, $cxs): array {
                $cards = (string) $messages[1]['content'];

                return [
                    'needed' => 'spodnie robocze o gramaturze 250 g/m²',
                    'search_phrases' => [
                        'spodnie', 'spodnie robocze', 'gramatura 250 g/m²', '250 g/m²',
                    ],
                    'matches' => [
                        ['id' => $cxs->id, 'score' => 88, 'reason' => 'Gramatura 250 g/m² w opisie'],
                    ],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'spodnie o Gramaturze 250 g/m²',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'CXS-STRETCH');

        $this->assertNotNull($cards);
        $this->assertStringContainsString('CXS STRETCH', $cards);
    }

    public function test_ai_search_long_cards_include_description(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'CHEM-LONG',
            'name' => 'Rękawice chemiczne testowe',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'norms' => 'EN 374',
            'description' => 'Unikalny znacznik opisu ALPHA-LONG-DESC-99.',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 3,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
            'enrichment_payload' => [
                'specs' => ['odporność na kwasy'],
                'use_cases' => ['laboratorium'],
                'features' => ['mankiet dziany'],
            ],
        ]);

        $cards = $this->captureRankCards('rękawice do kwasów');

        $this->assertStringContainsString('ALPHA-LONG-DESC-99', $cards);
        $this->assertStringContainsString('mankiet dziany', $cards);
        $this->assertStringContainsString('CHEM-LONG', $cards);
    }

    public function test_ai_search_short_cards_omit_description(): void
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
            'product_search_card_detail' => 'short',
        ]);

        Product::query()->create([
            'sku' => 'CHEM-SHORT',
            'name' => 'Rękawice chemiczne testowe',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'norms' => 'EN 374',
            'description' => 'Unikalny znacznik opisu ALPHA-LONG-DESC-99.',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 3,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
            'enrichment_payload' => [
                'specs' => ['odporność na kwasy'],
                'use_cases' => ['laboratorium'],
                'features' => ['mankiet dziany'],
            ],
        ]);

        $cards = $this->captureRankCards('rękawice do kwasów');

        $this->assertStringContainsString('CHEM-SHORT', $cards);
        $this->assertStringContainsString('laboratorium', $cards);
        $this->assertStringNotContainsString('ALPHA-LONG-DESC-99', $cards);
        $this->assertStringNotContainsString('mankiet dziany', $cards);
    }

    public function test_ai_search_sends_whole_assortment_even_without_phrase_hit(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        for ($i = 1; $i <= 5; $i++) {
            Product::query()->create([
                'sku' => 'MACH-'.$i,
                'name' => 'SPODNIE MACH '.$i,
                'manufacturer' => 'Delta Plus',
                'category' => 'Odzież robocza',
                'description' => 'Spodnie robocze Mach '.$i.' z poliestru.',
                'catalog_price_net' => 100 + $i,
                'purchase_price' => 70,
                'stock' => 3,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        // Ani nazwa, ani opis nie zawierają żadnej frazy z intentu — o przynależności
        // decyduje wyłącznie rodzina asortymentu odczytana z kategorii.
        Product::query()->create([
            'sku' => 'URG-A',
            'name' => 'URG-A',
            'manufacturer' => 'Urgent',
            'category' => 'Odzież robocza',
            'description' => 'Model z karczkiem, wstawkami z elastanu i kieszeniami cargo.',
            'catalog_price_net' => 31.4,
            'purchase_price' => 20,
            'stock' => 7,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $cards = null;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards): array {
                $content = (string) $messages[1]['content'];
                if ($cards === null && str_contains($content, 'Karty katalogu:')) {
                    $cards = $content;
                }

                return [
                    'needed' => 'spodnie robocze',
                    'search_phrases' => ['spodnie', 'spodnie robocze'],
                    'matches' => [],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', ['query' => 'spodnie robocze do magazynu'])
            ->assertOk();

        $this->assertNotNull($cards);
        $this->assertStringContainsString('URG-A', $cards);
    }

    public function test_ai_search_never_sends_cards_from_another_ppe_family(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'GLOVE-OFFTOPIC',
            'name' => 'Rękawice robocze z polaru',
            'manufacturer' => 'X',
            'category' => 'Rękawice',
            'description' => 'Rękawice ocieplane polarem, zimowe, do prac na zewnątrz.',
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $balaclava = Product::query()->create([
            'sku' => 'BALTIC',
            'name' => 'KOMINIARKA Z POLARU POLIESTRU',
            'manufacturer' => 'Delta Plus',
            'category' => 'EVOLUTION',
            'description' => null,
            'catalog_price_net' => 9.9,
            'purchase_price' => 6,
            'stock' => 12,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        $this->mockCatalogRank(
            'kominiarka polarowa',
            ['kominiarka', 'polar'],
            [$balaclava]
        );

        $this->postJson('/api/products/ai-search', [
            'query' => 'CZAPKA KOMINIARKA Z POLARU czarna lub granatowa',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'BALTIC')
            ->assertJsonMissing(['sku' => 'GLOVE-OFFTOPIC']);
    }

    public function test_grammage_written_differently_in_siwz_and_in_card_still_ranks_first(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        for ($i = 1; $i <= 40; $i++) {
            Product::query()->create([
                'sku' => 'PLAIN-'.$i,
                'name' => 'SPODNIE ROBOCZE model '.$i,
                'manufacturer' => 'Delta Plus',
                'category' => 'Odzież robocza',
                'description' => 'Spodnie robocze z poliestru i bawełny, kieszenie boczne.',
                'catalog_price_net' => 100 + $i,
                'purchase_price' => 70,
                'stock' => 3,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        $heavy = Product::query()->create([
            'sku' => 'HEAVY-250',
            'name' => 'SPODNIE ROBOCZE Canis',
            'manufacturer' => 'CANIS SAFETY',
            'category' => 'Odzież robocza',
            'description' => 'Spodnie robocze z tkaniny o gramaturze 250 g/m².',
            'catalog_price_net' => 95,
            'purchase_price' => 60,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $cards = null;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, $heavy): array {
                $cards = (string) $messages[1]['content'];

                return [
                    'needed' => 'spodnie robocze gramatura 250 gr',
                    'search_phrases' => ['spodnie', 'spodnie robocze', '250 gr'],
                    'matches' => [
                        ['id' => $heavy->id, 'score' => 93, 'reason' => 'Gramatura 250 g/m²'],
                    ],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'spodnie robocze gramatura 250 gr',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'HEAVY-250');

        $this->assertSame('HEAVY-250', $this->firstCardSku($cards));
    }

    public function test_card_matching_only_in_enrichment_payload_reaches_the_model(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        for ($i = 1; $i <= 30; $i++) {
            Product::query()->create([
                'sku' => 'GEN-'.$i,
                'name' => 'Rękawice robocze '.$i,
                'manufacturer' => 'Inna',
                'category' => 'Rękawice',
                'description' => 'Rękawice robocze ogólnego przeznaczenia, numer '.$i.'.',
                'catalog_price_net' => 5 + $i,
                'purchase_price' => 3,
                'stock' => 2,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        // Ani nazwa, ani opis nie mówią o amoniaku — wie o tym wyłącznie payload.
        $chemical = Product::query()->create([
            'sku' => 'CHEM-58',
            'name' => 'Rękawice AlphaTec',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Rękawice chemiczne wielokrotnego użytku.',
            'enrichment_payload' => [
                'use_cases' => ['praca z amoniakiem'],
                'materials' => ['butyl'],
            ],
            'catalog_price_net' => 40,
            'purchase_price' => 25,
            'stock' => 6,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $cards = null;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, $chemical): array {
                $cards = (string) $messages[1]['content'];

                return [
                    'needed' => 'rękawice do pracy z amoniakiem',
                    'search_phrases' => ['rękawice', 'amoniak'],
                    'matches' => [
                        ['id' => $chemical->id, 'score' => 95, 'reason' => 'Zastosowanie: amoniak'],
                    ],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'CHEM-58');

        $this->assertSame('CHEM-58', $this->firstCardSku($cards));
    }

    public function test_card_without_recognisable_family_still_reaches_the_model(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        for ($i = 1; $i <= 10; $i++) {
            Product::query()->create([
                'sku' => 'PL-GLOVE-'.$i,
                'name' => 'Rękawice robocze '.$i,
                'manufacturer' => 'Inna',
                'category' => 'Rękawice',
                'description' => 'Rękawice robocze ogólnego przeznaczenia '.$i.'.',
                'catalog_price_net' => 5 + $i,
                'purchase_price' => 3,
                'stock' => 2,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        // Czeska nazwa producenta, brak opisu i kategorii — reguły rodziny milczą,
        // a mimo to karta ma trafienie w tekst i musi dojść do modelu.
        $czech = Product::query()->create([
            'sku' => '341006400007',
            'name' => 'Rukavice ABRAK, s blistrem, polyes.úpl.povrstvené nitrilem, vel.7',
            'manufacturer' => 'Canis',
            'description' => null,
            'catalog_price_net' => 4,
            'purchase_price' => 2,
            'stock' => 30,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        $this->assertNull($czech->ppe_family);

        $cards = null;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, $czech): array {
                $cards = (string) $messages[1]['content'];

                return [
                    'needed' => 'rękawice ABRAK',
                    'search_phrases' => ['rękawice', 'abrak'],
                    'matches' => [
                        ['id' => $czech->id, 'score' => 91, 'reason' => 'Model ABRAK'],
                    ],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', ['query' => 'rękawice ABRAK rozmiar 7'])
            ->assertOk()
            ->assertJsonPath('products.0.sku', '341006400007');

        $this->assertNotNull($cards);
        $this->assertStringContainsString('ABRAK', $cards);
    }

    public function test_typo_in_the_product_noun_still_scopes_the_search_to_the_right_family(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $pants = Product::query()->create([
            'sku' => 'SPOD-250',
            'name' => 'SPODNIE ROBOCZE Canis',
            'manufacturer' => 'CANIS SAFETY',
            'category' => 'Odzież robocza',
            'description' => 'Spodnie robocze z tkaniny o gramaturze 250 g/m².',
            'catalog_price_net' => 95,
            'purchase_price' => 60,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        // Ta sama gramatura, inna rodzina. Wchodzi w pulę przez token 250gsm,
        // więc bramka rodziny jest jedyną rzeczą, która ją odsiewa.
        $gloves = Product::query()->create([
            'sku' => 'REK-250',
            'name' => 'Rękawice powlekane',
            'manufacturer' => 'Inna',
            'category' => 'Rękawice',
            'description' => 'Rękawice z dzianiny o gramaturze 250 g/m².',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 40,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $cards = null;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, $pants, $gloves): array {
                $cards = (string) $messages[1]['content'];

                return [
                    'needed' => 'spodnie robocze',
                    'search_phrases' => ['spodnie', 'spodnie robocze', 'gramatura 250'],
                    'matches' => [
                        ['id' => $gloves->id, 'score' => 95, 'reason' => 'Gramatura 250'],
                        ['id' => $pants->id, 'score' => 80, 'reason' => 'Spodnie 250 g/m²'],
                    ],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', ['query' => 'podnie gramatura 250 gr'])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'SPOD-250')
            ->assertJsonMissing(['sku' => 'REK-250']);

        $this->assertNotNull($cards);
        $this->assertStringNotContainsString('REK-250', $cards);
    }

    private function captureRankCards(string $query): string
    {
        $cards = null;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards): array {
                $content = (string) $messages[1]['content'];
                if ($cards === null && str_contains($content, 'Karty katalogu:')) {
                    $cards = $content;
                }

                return [
                    'needed' => 'rękawice',
                    'search_phrases' => ['rękawice'],
                    'matches' => [],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', ['query' => $query])->assertOk();
        $this->assertNotNull($cards);

        return (string) $cards;
    }

    private function firstCardSku(?string $prompt): ?string
    {
        $this->assertNotNull($prompt);
        $this->assertSame(1, preg_match('/Karty katalogu:\s*(\[.*\])\s*$/s', (string) $prompt, $m));

        $cards = json_decode($m[1], true);
        $this->assertIsArray($cards);
        $this->assertNotEmpty($cards);

        return is_string($cards[0]['sku'] ?? null) ? $cards[0]['sku'] : null;
    }

    public function test_ai_search_rejects_face_shield_for_vest_even_if_model_scores_it(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $vest = Product::query()->create([
            'sku' => 'VEST-HV',
            'name' => 'Kamizelka odblaskowa żółta siatkowa',
            'manufacturer' => 'X',
            'category' => 'Odzież',
            'norms' => 'EN ISO 20471',
            'description' => 'Kamizelka ostrzegawcza klasa 1, góra siatkowa, dół materiał.',
            'catalog_price_net' => 22,
            'purchase_price' => 14,
            'stock' => 6,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $shield = Product::query()->create([
            'sku' => '12-0423',
            'name' => 'Osłona twarzy żaroodporna siatkowa',
            'manufacturer' => 'ALWIT POLAND',
            'category' => 'Ochrona twarzy',
            'description' => 'Osłona twarzy siatkowa żaroodporna ALWIT do prac gorących.',
            'catalog_price_net' => 40,
            'purchase_price' => 28,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'kamizelka odblaskowa żółta',
            'search_phrases' => ['kamizelka', 'kamizelka odblaskowa', 'siatkowa', 'EN 20471'],
            'matches' => [
                ['id' => $shield->id, 'score' => 86, 'reason' => 'siatkowa'],
                ['id' => $vest->id, 'score' => 80, 'reason' => 'kamizelka'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'KAMIZELKA ODBLASKOWA żółta SIATKOWA z nadrukiem · EN 20471 kl. 1',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'VEST-HV')
            ->assertJsonMissing(['sku' => '12-0423']);
    }

    public function test_web_search_returns_external_hints_without_catalog(): void
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
            'search_engine' => 'tavily',
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJson');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake([
            'api.tavily.com/search' => Http::response([
                'results' => [
                    [
                        'url' => 'https://sklep.example/produkt/rekawice-cxs',
                        'title' => 'Rękawice CXS ocieplane',
                    ],
                    [
                        'url' => 'https://bhp.example/produkt/bluza-kolpeo',
                        'title' => 'Bluza KOLPEO',
                    ],
                ],
            ], 200),
        ]);

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice ocieplane EN 342',
            'web' => true,
            'limit' => 8,
        ])
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('products', [])
            ->assertJsonPath('external_hint.url', 'https://sklep.example/produkt/rekawice-cxs')
            ->assertJsonPath('external_hints', [
                [
                    'url' => 'https://sklep.example/produkt/rekawice-cxs',
                    'title' => 'Rękawice CXS ocieplane',
                ],
            ]);
    }

    public function test_ai_search_returns_all_gloves_meeting_celsius(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $heat250 = Product::query()->create([
            'sku' => 'HEAT-250',
            'name' => 'Rękawice termoochronne 250',
            'manufacturer' => 'PIP',
            'category' => 'Rękawice',
            'description' => 'Rękawice do pieca, kontakt 250°C, EN 407.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 4,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $heat350 = Product::query()->create([
            'sku' => 'HEAT-350',
            'name' => 'Rękawice hutnicze 350',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Odporność 350 stopni C, praca przy piecu.',
            'catalog_price_net' => 30,
            'purchase_price' => 15,
            'stock' => 2,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'HEAT-100',
            'name' => 'Rękawice kuchenne 100',
            'manufacturer' => 'X',
            'category' => 'Rękawice',
            'description' => 'Kontakt 100°C.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 9,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->mockCatalogRank(
            'rękawice termiczne',
            ['rękawice', '200'],
            [$heat350, $heat250],
            ['200 C']
        );

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy przy 200 C',
        ])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('products.0.sku', 'HEAT-250')
            ->assertJsonPath('products.1.sku', 'HEAT-350');
    }

    public function test_ai_search_ignores_360_degree_coverage_as_celsius(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => '11849',
            'name' => 'Rękawice ochronne HyFlex 11-849',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'description' => 'Gwarantuje ochronę 360 stopni przed zadrapaniami. '
                .'odporność termiczna: do 100°C przez 15s (EN407).',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 5,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $heat250 = Product::query()->create([
            'sku' => 'HEAT-250',
            'name' => 'Rękawice termoochronne 250',
            'manufacturer' => 'PIP',
            'category' => 'Rękawice',
            'description' => 'Kontakt 250°C, EN 407.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 4,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->mockCatalogRank('rękawice termiczne', ['rękawice', '200'], [$heat250], ['200']);

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice 200 stopnia',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'HEAT-250');
    }

    public function test_ai_search_heat_gloves_exclude_arm_sleeves(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'MBCK/40/P',
            'name' => 'Naramiennik MBCK 40 cm',
            'manufacturer' => 'Lebon',
            'category' => 'Zarękawki antyprzecięciowe',
            'description' => 'Zarękawki para-aramid. Kontakt 250°C.',
            'catalog_price_net' => 12,
            'purchase_price' => 9,
            'stock' => 3,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $heat250 = Product::query()->create([
            'sku' => 'HEAT-250',
            'name' => 'Rękawice termoochronne 250',
            'manufacturer' => 'PIP',
            'category' => 'Rękawice',
            'description' => 'Kontakt 250°C, EN 407.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 4,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->mockCatalogRank('rękawice termiczne', ['rękawice', '200'], [$heat250], ['200 C']);

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy przy 200 C',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'HEAT-250');
    }

    public function test_ai_search_heat_ignores_shop_category_temperature(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'GTA/D/M',
            'name' => 'GTA/D/M',
            'manufacturer' => 'Lebon',
            'category' => 'Rękawice termiczne 350°C',
            'norms' => '1, 1 para, 4, Nie, Poliuretan, Szary, Tak',
            'description' => 'Rękawice dziane bezszwowo, uiglenie 13 w 100% z teksturowanego '
                .'poliamidu z dodatkiem włókna węglowego. Końcówki palców powlekane białym poliuretanem.',
            'catalog_price_net' => 14.97,
            'purchase_price' => 11.98,
            'stock' => 5,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $heat250 = Product::query()->create([
            'sku' => 'HEAT-250',
            'name' => 'Rękawice termoochronne 250',
            'manufacturer' => 'PIP',
            'category' => 'Rękawice',
            'description' => 'Kontakt 250°C, EN 407.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 4,
            'ppe_family' => 'gloves',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->mockCatalogRank('rękawice termiczne', ['rękawice', '200'], [$heat250], ['200 C']);

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy przy 200 C',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'HEAT-250');
    }

    public function test_antistatic_balaclava_asks_model_instead_of_dumping_all_hoods(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $xispal = Product::query()->create([
            'sku' => 'XISPAL-RS',
            'name' => 'Xispal RS - Balaclava',
            'manufacturer' => 'Lenard',
            'category' => 'Ochrona głowy',
            'norms' => 'EN 1149-5',
            'description' => 'Kominiarka (balaclava) z włóknem antyelektrostatycznym ESD.',
            'catalog_price_net' => 26,
            'purchase_price' => 18,
            'stock' => 8,
            'ppe_family' => 'head',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'BALTIC',
            'name' => 'KOMINIARKA Z POLARU POLIESTRU, 100 g/m²',
            'manufacturer' => 'Delta Plus',
            'category' => 'Odzież',
            'description' => 'Kominiarka polarowa.',
            'catalog_price_net' => 9.9,
            'purchase_price' => 6,
            'stock' => 12,
            'ppe_family' => 'apparel',
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
        $esd = Product::query()->create([
            'sku' => 'KOM-ESD',
            'name' => 'Kominiarka antyelektrostatyczna',
            'manufacturer' => 'Urgent',
            'category' => 'ochrona_glowy',
            'norms' => 'EN 1149-5 EN ISO 11612 EN ISO 13688',
            'description' => 'Kominiarka z certyfikatem ESD.',
            'catalog_price_net' => 15,
            'purchase_price' => 9,
            'stock' => 5,
            'ppe_family' => 'apparel',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'JKT-ESD',
            'name' => 'Kurtka antyelektrostatyczna STATICGUARD',
            'manufacturer' => 'PW',
            'category' => 'Kurtki',
            'norms' => 'EN 1149-5',
            'description' => 'Kurtka ESD.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 4,
            'ppe_family' => 'apparel',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function () use (&$call, $xispal, $esd): array {
                $call++;

                return [
                    'needed' => 'kominiarka antyelektrostatyczna',
                    'search_phrases' => ['kominiarka', 'balaclava', 'antyelektrostatyczna'],
                    'constraints' => ['antyelektrostatyczna', 'EN 1149-5'],
                    'matches' => [
                        ['id' => $esd->id, 'score' => 96, 'reason' => 'Kominiarka ESD EN 1149-5'],
                        ['id' => $xispal->id, 'score' => 90, 'reason' => 'Balaclava z włóknem ESD'],
                    ],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $skus = $this->postJson('/api/products/ai-search', [
            'query' => 'KOMINIARKA ANTYELEKTROSTATYCZNA',
            'limit' => 40,
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'KOM-ESD')
            ->assertJsonMissing(['sku' => 'JKT-ESD'])
            ->assertJsonMissing(['sku' => 'BALTIC'])
            ->json('products');

        $this->assertEqualsCanonicalizing(
            ['KOM-ESD', 'XISPAL-RS'],
            collect($skus)->pluck('sku')->all()
        );
        $this->assertSame(2, $call);
    }

    public function test_ai_search_head_liner_prefers_catalog_cap_over_esd_jacket(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $cap = Product::query()->create([
            'sku' => 'CAP-ESD',
            'name' => 'Czepek ocieplany pod hełm ESD',
            'manufacturer' => 'JSP',
            'category' => 'Czepki pod hełm',
            'norms' => 'EN 1149-5',
            'description' => 'Wkładka ocieplana pod hełm, antyelektrostatyczna ESD.',
            'catalog_price_net' => 12,
            'purchase_price' => 8,
            'stock' => 20,
            'ppe_family' => 'head',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'JKT-ESD',
            'name' => 'Kurtka antyelektrostatyczna STATICGUARD',
            'manufacturer' => 'PW Krystian',
            'category' => 'Kurtki',
            'norms' => 'EN 1149-5',
            'description' => 'Kurtka ESD EN 1149-5 EN 61340.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 4,
            'ppe_family' => 'apparel',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->mockCatalogRank(
            'czepek pod hełm',
            ['czepek', 'wkładka', 'hełm'],
            [$cap],
            ['EN 1149-5']
        );

        $this->postJson('/api/products/ai-search', [
            'query' => 'Wkładka/czepek ocieplana pod hełm antyelektrostatyczna EN 1149-5 lub EN 61340',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'CAP-ESD')
            ->assertJsonPath('external_hint', null);
    }

    public function test_specific_coverall_query_asks_model_instead_of_dumping_all_suits(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $bee = Product::query()->create([
            'sku' => '303',
            'name' => 'Kombinezon dla pszczelarza',
            'manufacturer' => 'AJ Group',
            'category' => 'Kombinezony',
            'norms' => null,
            'description' => 'Kombinezon pszczelarski z kapeluszem.',
            'catalog_price_net' => 200,
            'purchase_price' => 120,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $chem = Product::query()->create([
            'sku' => 'TYCHEM-C',
            'name' => 'Kombinezon chemoodporny Tychem C',
            'manufacturer' => 'DuPont',
            'category' => 'Kombinezony',
            'norms' => 'EN 13034',
            'description' => 'Kombinezon Typ 3/4 na kwasy, w tym kwas siarkowy.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 6,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'specs' => ['odporność na kwas siarkowy 96%', 'Typ 3/4'],
                'norms' => ['EN 13034'],
            ],
            'enriched_at' => now(),
        ]);

        $cards = null;
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call, $chem): array {
                $call++;
                $user = (string) ($messages[1]['content'] ?? '');
                if (str_contains($user, 'Karty katalogu:')) {
                    $cards = $user;
                }

                return [
                    'needed' => 'kombinezon chemoodporny',
                    'search_phrases' => ['kombinezon', 'kombinezon chemoodporny', 'kwas siarkowy'],
                    'constraints' => ['kwas siarkowy 96%', 'EN 13034', 'Typ 3/4'],
                    'matches' => [
                        ['id' => $chem->id, 'score' => 94, 'reason' => 'EN 13034 i kwas siarkowy w specyfikacji'],
                    ],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Kombinezon chemoodporny na kwas siarkowy 96%',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'TYCHEM-C')
            ->assertJsonMissing(['sku' => '303']);

        $this->assertNotNull($cards);
        $this->assertStringContainsString('kwas siarkowy 96%', $cards);
        $this->assertStringContainsString('EN 13034', $cards);
        $this->assertSame(2, $call);
        unset($bee);
    }

    public function test_specific_query_keeps_constraint_hit_out_of_large_article_dump(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        for ($i = 1; $i <= 30; $i++) {
            Product::query()->create([
                'sku' => 'SUIT-'.$i,
                'name' => 'Kombinezon roboczy model '.$i,
                'manufacturer' => 'Delta Plus',
                'category' => 'Kombinezony',
                'description' => 'Kombinezon ochronny do prac ogólnych.',
                'catalog_price_net' => 40 + $i,
                'purchase_price' => 25,
                'stock' => 3,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        $chem = Product::query()->create([
            'sku' => 'TYCHEM-C',
            'name' => 'Tychem 6000 F',
            'manufacturer' => 'DuPont',
            'category' => 'Ochrona chemiczna',
            'norms' => 'EN 13034',
            'description' => 'Bariera na kwas siarkowy 96%, Typ 3/4.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 6,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'specs' => ['odporność na kwas siarkowy 96%'],
                'norms' => ['EN 13034'],
            ],
            'enriched_at' => now(),
        ]);

        $cards = null;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, $chem): array {
                $cards = (string) $messages[1]['content'];

                return [
                    'needed' => 'kombinezon chemoodporny',
                    'search_phrases' => ['kombinezon', 'kombinezon chemoodporny', 'kwas siarkowy'],
                    'constraints' => ['kwas siarkowy 96%', 'EN 13034'],
                    'matches' => [
                        ['id' => $chem->id, 'score' => 95, 'reason' => 'kwas siarkowy w specyfikacji'],
                    ],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Kombinezon chemoodporny na kwas siarkowy 96%',
            'limit' => 40,
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'TYCHEM-C');

        $this->assertNotNull($cards);
        $this->assertStringContainsString('TYCHEM-C', (string) $cards);
        $this->assertStringContainsString('kwas siarkowy', (string) $cards);
    }

    public function test_search_many_ranks_lines_in_one_parallel_wave(): void
    {
        $gloves = Product::query()->create([
            'sku' => 'G-CHEM',
            'name' => 'Rękawice chemoodporne',
            'manufacturer' => 'X',
            'category' => 'Rękawice',
            'description' => 'Rękawice EN 374 antyelektrostatyczne.',
            'catalog_price_net' => 10,
            'purchase_price' => 6,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $boots = Product::query()->create([
            'sku' => 'K-CHEM',
            'name' => 'Kalosze chemoodporne',
            'manufacturer' => 'X',
            'category' => 'Kalosze',
            'description' => 'Kalosze chemoodporne antyelektrostatyczne.',
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $waves = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')
            ->andReturnUsing(function (array $sets) use (&$waves, $gloves, $boots): array {
                $waves++;
                $system = (string) ($sets[0][0]['content'] ?? '');
                $isUnderstand = str_contains($system, '"manufacturer"');
                if ($isUnderstand) {
                    $out = [];
                    foreach ($sets as $set) {
                        $user = (string) ($set[1]['content'] ?? '');
                        if (str_contains($user, 'EN 374')) {
                            $out[] = [
                                'needed' => 'rękawice chemoodporne',
                                'search_phrases' => ['rękawice chemoodporne', 'EN 374'],
                                'constraints' => ['EN 374'],
                                'manufacturer' => null,
                            ];
                        } else {
                            $out[] = [
                                'needed' => 'kalosze chemoodporne',
                                'search_phrases' => ['kalosze chemoodporne'],
                                'constraints' => ['chemoodporne'],
                                'manufacturer' => null,
                            ];
                        }
                    }

                    return $out;
                }

                $this->assertGreaterThanOrEqual(1, count($sets));

                return array_map(
                    static function (array $set) use ($gloves, $boots): array {
                        $user = (string) ($set[1]['content'] ?? '');
                        if (preg_match('/\bkalosz/i', $user) === 1) {
                            return [
                                'needed' => 'kalosze chemoodporne',
                                'search_phrases' => ['kalosze'],
                                'constraints' => [],
                                'matches' => [['id' => $boots->id, 'score' => 88, 'reason' => 'kalosze']],
                            ];
                        }

                        return [
                            'needed' => 'rękawice chemoodporne',
                            'search_phrases' => ['rękawice'],
                            'constraints' => ['EN 374'],
                            'matches' => [['id' => $gloves->id, 'score' => 91, 'reason' => 'EN 374']],
                        ];
                    },
                    $sets
                );
            });
        $llm->shouldNotReceive('chatJson');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $rows = $this->app->make(ProductAiSearchService::class)->searchMany([
            'Rękawice chemoodporne EN 374',
            'Kalosze chemoodporne',
        ], 3);

        $this->assertCount(2, $rows);
        $this->assertNotEmpty($rows[0]['products'] ?? []);
        $this->assertNotEmpty($rows[1]['products'] ?? []);
        $this->assertGreaterThanOrEqual(1, $waves);
    }

    public function test_specific_query_analyzes_first_then_searches_catalog_synonyms(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $steel = Product::query()->create([
            'sku' => 'STEEL-S3',
            'name' => 'Trzewiki S3 SRC',
            'manufacturer' => 'Reis',
            'category' => 'Obuwie',
            'description' => 'Obuwie ochronne, podnosek stalowy, wkładka antyprzebiciowa.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 10,
            'ppe_family' => 'footwear',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'COMP-S3',
            'name' => 'Trzewiki S3 kompozyt',
            'manufacturer' => 'Reis',
            'category' => 'Obuwie',
            'description' => 'Obuwie z podnoskiem kompozytowym, bez metalu.',
            'catalog_price_net' => 90,
            'purchase_price' => 55,
            'stock' => 10,
            'ppe_family' => 'footwear',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $understood = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(function (array $messages) use (&$understood, $steel): array {
            $user = (string) ($messages[1]['content'] ?? '');
            if (! str_contains($user, 'Karty katalogu:')) {
                $understood++;

                return [
                    'needed' => 'buty robocze',
                    'search_phrases' => ['buty robocze', 'podnosek stalowy', 'steel toe'],
                    'constraints' => ['podnosek metalowy lub stalowy, nie kompozytowy'],
                ];
            }

            return [
                'needed' => 'buty robocze',
                'search_phrases' => ['buty robocze', 'podnosek stalowy'],
                'constraints' => ['podnosek metalowy lub stalowy, nie kompozytowy'],
                'matches' => [
                    ['id' => $steel->id, 'score' => 88, 'reason' => 'podnosek stalowy'],
                ],
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        Http::fake();

        $this->postJson('/api/products/ai-search', [
            'query' => 'Buty robocze z metalowymi noskami',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'STEEL-S3')
            ->assertJsonPath('search_phrases.1', 'podnosek stalowy');

        $this->assertSame(1, $understood);
        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'tavily'));
    }

    public function test_generic_fabric_query_returns_catalog_cap_without_rewrite(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $cap = Product::query()->create([
            'sku' => 'CZ-DASZEK',
            'name' => 'Czapka z daszkiem robocza',
            'manufacturer' => 'Reis',
            'category' => 'Czapki',
            'description' => 'Czapka robocza z daszkiem, bawełna.',
            'catalog_price_net' => 12,
            'purchase_price' => 7,
            'stock' => 10,
            'ppe_family' => 'head',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $rewrites = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(function (array $messages) use (&$rewrites): array {
            $user = (string) ($messages[1]['content'] ?? '');
            if (str_contains($user, 'Karty katalogu:')) {
                return [
                    'needed' => 'czapka drelichowa',
                    'search_phrases' => ['czapka drelichowa'],
                    'constraints' => ['drelichowa'],
                    'matches' => [],
                ];
            }
            $rewrites++;

            return [
                'needed' => 'czapka z daszkiem',
                'search_phrases' => ['czapka z daszkiem', 'czapka robocza'],
                'constraints' => [],
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        Http::fake();

        $this->postJson('/api/products/ai-search', [
            'query' => 'Czapka drelichowa',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'CZ-DASZEK')
            ->assertJsonPath('external_hint', null);

        $this->assertSame(0, $rewrites);
        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'tavily'));
    }

    public function test_empty_rank_rewrites_query_and_retries_catalog(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $cap = Product::query()->create([
            'sku' => 'CZ-DASZEK',
            'name' => 'Czapka z daszkiem robocza',
            'manufacturer' => 'Reis',
            'category' => 'Czapki',
            'description' => 'Czapka robocza z daszkiem, bawełna.',
            'catalog_price_net' => 12,
            'purchase_price' => 7,
            'stock' => 10,
            'ppe_family' => 'head',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $rankCalls = 0;
        $rewrites = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(function (array $messages) use (&$rankCalls, &$rewrites, $cap): array {
            $user = (string) ($messages[1]['content'] ?? '');
            if (str_contains($user, 'Karty katalogu:')) {
                $rankCalls++;
                if ($rankCalls === 1) {
                    return [
                        'needed' => 'czapka drelichowa',
                        'search_phrases' => ['czapka drelichowa', 'czapka'],
                        'constraints' => ['drelichowa'],
                        'matches' => [],
                    ];
                }

                return [
                    'needed' => 'czapka z daszkiem',
                    'search_phrases' => ['czapka z daszkiem', 'czapka robocza'],
                    'matches' => [
                        ['id' => $cap->id, 'score' => 78, 'reason' => 'czapka robocza'],
                    ],
                ];
            }
            $rewrites++;

            return [
                'needed' => 'czapka z daszkiem',
                'search_phrases' => ['czapka z daszkiem', 'czapka robocza'],
                'constraints' => [],
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        Http::fake();

        $this->postJson('/api/products/ai-search', [
            'query' => 'Czapka drelichowa EN 812',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'CZ-DASZEK')
            ->assertJsonPath('external_hint', null);

        $this->assertGreaterThanOrEqual(1, $rankCalls);
        $this->assertTrue(
            $rewrites === 1 || $rankCalls >= 2,
            "rewrite={$rewrites} rank={$rankCalls}"
        );
        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'tavily'));
    }

    /**
     * @param  list<string>  $phrases
     * @param  list<Product>  $picks
     * @param  list<string>  $constraints
     */
    private function mockCatalogRank(string $needed, array $phrases, array $picks, array $constraints = []): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(function () use ($needed, $phrases, $picks, $constraints): array {
            $matches = [];
            foreach (array_values($picks) as $i => $product) {
                $matches[] = [
                    'id' => $product->id,
                    'score' => 92 - $i,
                    'reason' => 'test',
                ];
            }

            return [
                'needed' => $needed,
                'search_phrases' => $phrases,
                'constraints' => $constraints,
                'matches' => $matches,
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
