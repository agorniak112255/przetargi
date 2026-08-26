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
            'norms' => 'EN 388 EN 511',
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

    public function test_ai_search_finds_catalog_filter_with_no_and_skips_a2b2e2k2(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
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

        $this->postJson('/api/products/ai-search', [
            'query' => 'pochłaniacz wielogazowy a2b2e2k2no (dodatkowa ochrona na tlenki azotu)',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'OXY-203')
            ->assertJsonPath('external_hint', null);
    }

    public function test_empty_catalog_retries_web_until_no_class_appears(): void
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
                'needed' => 'pochłaniacz wielogazowy',
                'search_phrases' => ['pochłaniacz'],
            ],
            ['matches' => []],
        );
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
        ])
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath(
                'external_hint.url',
                'https://oxyline.eu/pl/product/275/filter-203-up3-a2-b2-e2-k2-hg-co-no-p3'
            );
    }

    public function test_empty_catalog_skips_web_hint_without_no_class(): void
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
                'needed' => 'pochłaniacz wielogazowy',
                'search_phrases' => ['pochłaniacz', 'wielogazowy'],
            ],
            ['matches' => []],
        );
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

        $cards = null;
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call, $balaclava): array {
                $call++;
                if ($call === 1) {
                    return [
                        'needed' => 'kominiarka z polaru',
                        'search_phrases' => ['kominiarka', 'czapka', 'polar'],
                    ];
                }
                $cards = (string) $messages[1]['content'];

                return ['matches' => [
                    ['id' => $balaclava->id, 'score' => 91, 'reason' => 'Kominiarka z polaru'],
                ]];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'CZAPKA KOMINIARKA Z POLARU czarna lub granatowa',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'BALTIC');

        $this->assertNotNull($cards);
        $this->assertStringContainsString('KOMINIARKA Z POLARU POLIESTRU', $cards);
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
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call, $cxs): array {
                $call++;
                if ($call === 1) {
                    return [
                        'needed' => 'spodnie robocze o gramaturze 250 g/m²',
                        'search_phrases' => [
                            'spodnie', 'spodnie robocze', 'gramatura 250 g/m²', '250 g/m²',
                        ],
                    ];
                }
                $cards = (string) $messages[1]['content'];

                return ['matches' => [
                    ['id' => $cxs->id, 'score' => 88, 'reason' => 'Gramatura 250 g/m² w opisie'],
                ]];
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
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call): array {
                $call++;
                if ($call === 1) {
                    return [
                        'needed' => 'spodnie robocze',
                        'search_phrases' => ['spodnie', 'spodnie robocze'],
                    ];
                }
                $cards = (string) $messages[1]['content'];

                return ['matches' => []];
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

        $cards = null;
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call, $balaclava): array {
                $call++;
                if ($call === 1) {
                    return [
                        'needed' => 'kominiarka z polaru',
                        'search_phrases' => ['kominiarka', 'polar'],
                    ];
                }
                $cards = (string) $messages[1]['content'];

                return ['matches' => [
                    ['id' => $balaclava->id, 'score' => 90, 'reason' => 'Kominiarka z polaru'],
                ]];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'CZAPKA KOMINIARKA Z POLARU czarna lub granatowa',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonMissing(['sku' => 'GLOVE-OFFTOPIC']);

        $this->assertNotNull($cards);
        $this->assertStringNotContainsString('GLOVE-OFFTOPIC', $cards);
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
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call, $heavy): array {
                $call++;
                if ($call === 1) {
                    // SIWZ pisze „250 gr”, karta „250 g/m²” — dosłownie nic się nie zgadza.
                    return [
                        'needed' => 'spodnie robocze gramatura 250 gr',
                        'search_phrases' => ['spodnie', 'spodnie robocze', '250 gr'],
                    ];
                }
                $cards = (string) $messages[1]['content'];

                return ['matches' => [
                    ['id' => $heavy->id, 'score' => 93, 'reason' => 'Gramatura 250 g/m²'],
                ]];
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
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call, $chemical): array {
                $call++;
                if ($call === 1) {
                    return [
                        'needed' => 'rękawice do pracy z amoniakiem',
                        'search_phrases' => ['rękawice', 'amoniak'],
                    ];
                }
                $cards = (string) $messages[1]['content'];

                return ['matches' => [
                    ['id' => $chemical->id, 'score' => 95, 'reason' => 'Zastosowanie: amoniak'],
                ]];
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
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call, $czech): array {
                $call++;
                if ($call === 1) {
                    return [
                        'needed' => 'rękawice ABRAK',
                        'search_phrases' => ['rękawice', 'abrak'],
                    ];
                }
                $cards = (string) $messages[1]['content'];

                return ['matches' => [
                    ['id' => $czech->id, 'score' => 91, 'reason' => 'Model ABRAK'],
                ]];
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
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call, $pants, $gloves): array {
                $call++;
                if ($call === 1) {
                    // Model czyta „podnie” i zwraca nazwę po korekcie.
                    return [
                        'needed' => 'spodnie robocze',
                        'search_phrases' => ['spodnie', 'spodnie robocze', 'gramatura 250'],
                    ];
                }
                $cards = (string) $messages[1]['content'];

                return ['matches' => [
                    ['id' => $gloves->id, 'score' => 95, 'reason' => 'Gramatura 250'],
                    ['id' => $pants->id, 'score' => 80, 'reason' => 'Spodnie 250 g/m²'],
                ]];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', ['query' => 'podnie gramatura 250 gr'])
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'SPOD-250')
            ->assertJsonMissing(['sku' => 'REK-250']);

        $this->assertNotNull($cards);
        $this->assertStringNotContainsString('REK-250', $cards);
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
        $llm->shouldReceive('chatJson')->andReturn(
            [
                'needed' => 'kamizelka odblaskowa żółta',
                'search_phrases' => ['kamizelka', 'kamizelka odblaskowa', 'siatkowa', 'EN 20471'],
            ],
            [
                'matches' => [
                    ['id' => $shield->id, 'score' => 86, 'reason' => 'siatkowa'],
                    ['id' => $vest->id, 'score' => 80, 'reason' => 'kamizelka'],
                ],
            ],
        );
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
            ->assertJsonPath('external_hints.1.title', 'Bluza KOLPEO');
    }
}
