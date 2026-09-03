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

/**
 * /tenders/7 — „Ochronniki słuchu na hełm MSA - niski poziom tłumienia”.
 * Szukaj (GET /products) i Szukaj AI mają zwracać ochronniki Gallet, nie komplet higieniczny.
 */
final class ProductAiSearchHearingMsaTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Ochronniki słuchu na hełm MSA - niski poziom tłumienia';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_catalog_search_finds_gallet_earmuffs_and_hygiene_kit(): void
    {
        $this->seedScreenshotCatalog();

        $this->getJson('/api/products?q='.rawurlencode(self::QUERY).'&per_page=20')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonFragment(['sku' => 'GA010002D3X'])
            ->assertJsonFragment(['sku' => 'GA010002E3X'])
            ->assertJsonFragment(['sku' => '10092878']);
    }

    public function test_empty_llm_rank_falls_back_to_gallet_and_drops_hygiene_kit(): void
    {
        $this->seedScreenshotCatalog();
        $cards = null;
        $this->mockEmptyLlmAndCaptureCards($cards);

        $response = $this->postJson('/api/products/ai-search', [
            'query' => self::QUERY,
            'limit' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonMissing(['sku' => '10092878'])
            ->assertJsonPath('ai_note', null);
        $skus = array_column($response->json('products'), 'sku');
        $this->assertEqualsCanonicalizing(['GA010002D3X', 'GA010002E3X'], $skus);

        $this->assertNotNull($cards);
        $this->assertStringContainsString('GA010002D3X', (string) $cards);
        $this->assertStringContainsString('GA010002E3X', (string) $cards);
        $this->assertStringNotContainsString('10092878', (string) $cards);
    }

    public function test_llm_match_on_gallet_is_kept_and_hygiene_kit_stays_out(): void
    {
        $products = $this->seedScreenshotCatalog();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'ochronniki słuchu na hełm MSA',
            'search_phrases' => ['ochronniki słuchu', 'nauszniki na hełm'],
            'constraints' => ['niski poziom tłumienia'],
            'matches' => [
                ['id' => $products['d3x']->id, 'score' => 88, 'reason' => 'MSA Gallet nahełmowe'],
                ['id' => $products['e3x']->id, 'score' => 86, 'reason' => 'MSA Gallet nahełmowe'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => self::QUERY,
            'limit' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('products.0.sku', 'GA010002D3X')
            ->assertJsonMissing(['sku' => '10092878']);
    }

    public function test_gallet_still_reaches_rank_among_many_unclassified_msa_codes(): void
    {
        $this->seedScreenshotCatalog();
        for ($i = 1; $i <= 80; $i++) {
            Product::query()->create([
                'sku' => 'VGARD-'.$i,
                'name' => 'V-Gard '.$i,
                'manufacturer' => 'MSA',
                'category' => 'Ochrona głowy',
                'description' => null,
                'catalog_price_net' => 40,
                'purchase_price' => 25,
                'stock' => 1,
                'enrichment_status' => Product::ENRICHMENT_NONE,
            ]);
        }

        $cards = null;
        $this->mockEmptyLlmAndCaptureCards($cards);
        $this->postJson('/api/products/ai-search', [
            'query' => self::QUERY,
            'limit' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonMissing(['sku' => '10092878'])
            ->assertJsonPath('ai_note', null);

        $this->assertNotNull($cards);
        $this->assertStringContainsString('GA010002D3X', (string) $cards);
        $this->assertStringNotContainsString('VGARD-1', (string) $cards);
    }

    public function test_rank_exception_on_specific_query_does_not_pretend_model_found_nothing(): void
    {
        Product::query()->create([
            'sku' => 'TYCHEM-C',
            'name' => 'Kombinezon chemoodporny Tychem C',
            'manufacturer' => 'DuPont',
            'category' => 'Kombinezony',
            'description' => 'Kombinezon Typ 3/4 na kwasy.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andThrow(new \RuntimeException('timeout'));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Kombinezon chemoodporny na kwas siarkowy 96%',
        ])
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath(
                'ai_note',
                'Nie udało się ocenić kart przez model. Spróbuj ponownie albo użyj zwykłego wyszukiwania.'
            );
    }

    public function test_rank_exception_falls_back_to_gallet_instead_of_empty_model_note(): void
    {
        $this->seedScreenshotCatalog();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andThrow(new \RuntimeException('timeout'));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => self::QUERY,
            'limit' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonMissing(['sku' => '10092878'])
            ->assertJsonPath('ai_note', null);
    }

    /**
     * @return array{d3x: Product, e3x: Product, kit: Product}
     */
    private function seedScreenshotCatalog(): array
    {
        $d3x = Product::query()->create([
            'sku' => 'GA010002D3X',
            'name' => 'Aktywne ochronniki słuchu do GALLET F1XF, kable podhełmowe, przyciski na czaszy',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona słuchu',
            'description' => 'Aktywne ochronniki słuchu do hełmu GALLET F1XF.',
            'catalog_price_net' => 980,
            'purchase_price' => 700,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $e3x = Product::query()->create([
            'sku' => 'GA010002E3X',
            'name' => 'Aktywne ochronniki słuchu do GALLET F1XF, kable podhełmowe, pokrętło na czaszy',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona słuchu',
            'description' => 'Aktywne ochronniki słuchu do hełmu GALLET F1XF.',
            'catalog_price_net' => 990,
            'purchase_price' => 710,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $kit = Product::query()->create([
            'sku' => '10092878',
            'name' => 'Komplet higieniczny do left/RIGHT, niski st. tłumienia',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona słuchu',
            'description' => 'Wkładki higieniczne do nauszników left/RIGHT, niski stopień tłumienia.',
            'catalog_price_net' => 35,
            'purchase_price' => 20,
            'stock' => 8,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        return ['d3x' => $d3x, 'e3x' => $e3x, 'kit' => $kit];
    }

    private function mockEmptyLlmAndCaptureCards(?string &$cards): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturnUsing(function (array $messages) use (&$cards): array {
                $user = (string) ($messages[1]['content'] ?? '');
                if (str_contains($user, 'Karty katalogu:') && $cards === null) {
                    $cards = $user;
                }

                return [
                    'needed' => 'ochronniki słuchu na hełm MSA niski poziom tłumienia',
                    'search_phrases' => ['ochronniki słuchu', 'hełm', 'niski poziom tłumienia'],
                    'constraints' => ['niski poziom tłumienia'],
                    'matches' => [],
                ];
            });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
