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

final class ProductVectorSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_ai_search_falls_back_to_like_when_vector_disabled(): void
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
            'vector_enabled' => false,
        ]);

        $match = Product::query()->create([
            'sku' => 'GLOVE-VEC-OFF',
            'name' => 'Rękawice chemiczne AlphaTec',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'norms' => 'EN 374',
            'description' => 'Rękawice odporne na amoniak.',
            'catalog_price_net' => 12.5,
            'purchase_price' => 8,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn(
                [
                    'needed' => 'rękawice do amoniaku',
                    'search_phrases' => ['rękawice chemiczne', 'amoniak'],
                ],
                [
                    'matches' => [
                        ['id' => $match->id, 'score' => 88, 'reason' => 'LIKE path'],
                    ],
                ],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake();

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.id', $match->id);

        Http::assertNothingSent();
    }

    public function test_ai_search_uses_qdrant_prefilter_when_enabled(): void
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
            'vector_enabled' => true,
            'qdrant_url' => 'http://qdrant.test:6333',
            'qdrant_collection' => 'products',
            'embedding_model' => 'text-embedding-3-small',
        ]);

        $vectorOnly = Product::query()->create([
            'sku' => 'VEC-CHEM-99',
            'name' => 'Rękawice chemiczne nitrylowe',
            'manufacturer' => 'Test',
            'category' => 'Rękawice',
            'norms' => 'EN 374',
            'description' => 'Odporność na amoniak i kwasy, nitryl',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        // mechaniczne — wysoki score wektorowy, ale filtr chemii ma je odrzucić
        $mechanical = Product::query()->create([
            'sku' => 'VEC-MECH-01',
            'name' => 'MaxiFlex Ultimate',
            'manufacturer' => 'ATG',
            'category' => 'Rękawice',
            'norms' => 'EN 388',
            'description' => 'Rękawice robocze precyzyjne',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $fakeVector = array_fill(0, 8, 0.1);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => $fakeVector]],
            ], 200),
            'qdrant.test:6333/collections/products' => Http::response(['result' => ['status' => 'green']], 200),
            'qdrant.test:6333/collections/products/points/search' => Http::response([
                'result' => [
                    ['id' => $mechanical->id, 'score' => 0.99],
                    ['id' => $vectorOnly->id, 'score' => 0.91],
                ],
            ], 200),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn(
                [
                    // frazy celowo nietrafione w LIKE — wektor wchodzi dopiero, gdy SQL nie ma nic
                    'needed' => 'rękawice chemiczne do amoniaku',
                    'search_phrases' => ['zzz-brak-dopasowania-w-like'],
                ],
                [
                    'matches' => [
                        ['id' => $vectorOnly->id, 'score' => 90, 'reason' => 'Wektor'],
                    ],
                ],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.id', $vectorOnly->id)
            ->assertJsonPath('products.0.ai_match_percent', 90);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/embeddings'));
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/points/search'));
    }

    public function test_vector_hit_enters_pool_even_when_like_returns_full_page(): void
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
            'vector_enabled' => true,
            'qdrant_url' => 'http://qdrant.test:6333',
            'qdrant_collection' => 'products',
            'embedding_model' => 'text-embedding-3-small',
        ]);

        for ($i = 1; $i <= 60; $i++) {
            Product::query()->create([
                'sku' => 'MACH-'.$i,
                'name' => 'SPODNIE MACH '.$i.' Z POLIESTRU',
                'manufacturer' => 'Delta Plus',
                'category' => 'Odzież robocza',
                'description' => 'Spodnie robocze Mach '.$i.' z poliestru i bawełny.',
                'catalog_price_net' => 100 + $i,
                'purchase_price' => 70,
                'stock' => 3,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        // Nazwa bez słowa „spodnie”, gramatura zapisana inaczej niż w zapytaniu —
        // dla LIKE niewidoczny, znajduje go dopiero wektor.
        $cxs = Product::query()->create([
            'sku' => 'CXS-STRETCH',
            'name' => 'CXS STRETCH',
            'manufacturer' => 'CANIS SAFETY',
            'category' => 'Odzież robocza',
            'norms' => 'EN 13688',
            'description' => 'Ubranie roboczé męskie CXS STRETCH, gramatura 250 g/m², elastan.',
            'catalog_price_net' => 95,
            'purchase_price' => 60,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 8, 0.1)]],
            ], 200),
            'qdrant.test:6333/collections/products' => Http::response(['result' => ['status' => 'green']], 200),
            'qdrant.test:6333/collections/products/points/search' => Http::response([
                'result' => [['id' => $cxs->id, 'score' => 0.94]],
            ], 200),
        ]);

        $cards = null;
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call, $cxs): array {
                $call++;
                if ($call === 1) {
                    return [
                        'needed' => 'spodnie robocze o gramaturze 250',
                        'search_phrases' => ['spodnie', 'spodnie robocze', '250gr'],
                    ];
                }
                $cards = (string) $messages[1]['content'];

                return ['matches' => [
                    ['id' => $cxs->id, 'score' => 87, 'reason' => 'Gramatura 250 g/m²'],
                ]];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'spodnie o gramatrzurze 250gr',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'CXS-STRETCH');

        $this->assertNotNull($cards);
        $this->assertStringContainsString('CXS STRETCH', $cards);
    }

    public function test_vector_hit_without_description_is_no_longer_dropped(): void
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
            'vector_enabled' => true,
            'qdrant_url' => 'http://qdrant.test:6333',
            'qdrant_collection' => 'products',
            'embedding_model' => 'text-embedding-3-small',
        ]);

        // Karta jest w Qdrancie, więc miała co zaindeksować; brak kolumny `description`
        // nie może jej wykluczać z puli, bo o treści decyduje payload i nazwa.
        $bare = Product::query()->create([
            'sku' => '58-270',
            'name' => 'AlphaTec 58-270',
            'manufacturer' => 'Ansell',
            'description' => null,
            'catalog_price_net' => 30,
            'purchase_price' => 20,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 8, 0.1)]],
            ], 200),
            'qdrant.test:6333/collections/products' => Http::response(['result' => ['status' => 'green']], 200),
            'qdrant.test:6333/collections/products/points/search' => Http::response([
                'result' => [['id' => $bare->id, 'score' => 0.93]],
            ], 200),
        ]);

        $cards = null;
        $call = 0;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards, &$call, $bare): array {
                $call++;
                if ($call === 1) {
                    return [
                        // Fraza celowo nietrafiona w tekst, a zapytanie bez nazwy rodziny —
                        // jedynym źródłem kandydata zostaje wektor.
                        'needed' => 'ochrona dloni przed amoniakiem',
                        'search_phrases' => ['zzz-brak-takiej-frazy'],
                    ];
                }
                $cards = (string) $messages[1]['content'];

                return ['matches' => [
                    ['id' => $bare->id, 'score' => 90, 'reason' => 'Trafienie wektorowe'],
                ]];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'ochrona dloni przed amoniakiem',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', '58-270');

        $this->assertNotNull($cards);
        $this->assertStringContainsString('58-270', $cards);
    }

    public function test_test_vector_endpoint_pings_qdrant_and_embeddings(): void
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
            'vector_enabled' => true,
            'qdrant_url' => 'http://qdrant.test:6333',
            'qdrant_collection' => 'products',
            'embedding_model' => 'text-embedding-3-small',
        ]);

        $fakeVector = array_fill(0, 8, 0.05);

        Http::fake([
            'qdrant.test:6333/collections' => Http::response([
                'result' => ['collections' => [['name' => 'products']]],
            ], 200),
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => $fakeVector]],
            ], 200),
            'qdrant.test:6333/collections/products' => Http::response(['result' => []], 200),
        ]);

        $this->postJson('/api/ai-settings/test-vector')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('embedding_ok', true);
    }
}
