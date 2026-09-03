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

final class ProductAiSearchPerspectaTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'OKULARY OCHRONNE MSA PERSPECTA 010';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_ai_search_returns_perspecta_not_etui_when_llm_picks_wrong_card(): void
    {
        $products = $this->seedCatalog();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['perspecta 010', 'okulary ochronne msa'],
            'constraints' => [],
            'matches' => [
                ['id' => $products['etui']->id, 'score' => 85, 'reason' => 'MSA okulary'],
                ['id' => $products['glasses']->id, 'score' => 90, 'reason' => 'Perspecta 010'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => self::QUERY,
            'limit' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', '10045641')
            ->assertJsonMissing(['sku' => '10081939'])
            ->assertJsonMissing(['sku' => '10045516']);
    }

    /**
     * @return array{glasses: Product, etui: Product}
     */
    private function seedCatalog(): array
    {
        $glasses = Product::query()->create([
            'sku' => '10045641',
            'name' => 'Okulary PERSPECTA 010 (12szt), bezbarwne',
            'manufacturer' => 'MSA',
            'search_blob' => 'perspecta 010 okulary ochronne',
            'category' => 'Sklep - kategorie / Ochrona wzroku i twarzy / Akcesoria do okularów i gogli',
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 2,
        ]);
        Product::query()->create([
            'sku' => '10045516',
            'name' => 'Okulary PERSPECTA 9000 (12szt), bezbarwne',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona oczu',
            'catalog_price_net' => 40,
            'purchase_price' => 25,
            'stock' => 2,
        ]);
        $etui = Product::query()->create([
            'sku' => '10081939',
            'name' => 'Sztywne etui na okulary Perspecta (6szt)',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona oczu',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 4,
        ]);

        return ['glasses' => $glasses, 'etui' => $etui];
    }
}
