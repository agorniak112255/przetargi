<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class ProductVectorCandidateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_understands_requirement_without_type_enum(): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'ocieplana kurtka ochronna z kapturem',
            'search_phrases' => ['kurtka ochronna', 'bluza ocieplana', 'hi-vis'],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $intent = $this->app->make(ProductAiSearchService::class)->understandRequirement(
            'Kurtka ochronna ocieplana z odpinanym kapturem, 1 klasa widzialności'
        );

        $this->assertSame('ocieplana kurtka ochronna z kapturem', $intent['needed']);
        $this->assertContains('kurtka ochronna', $intent['search_phrases']);
    }

    public function test_model_picks_jacket_without_assortment_filter(): void
    {
        $jacket = Product::query()->create([
            'sku' => 'JKT-1',
            'name' => 'Kurtka ochronna ocieplana',
            'manufacturer' => 'X',
            'category' => 'Odzież',
            'description' => 'Kurtka ocieplana, kaptur, antyelektrostatyczna, trudnopalna.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'BOOT-1',
            'name' => 'Trzewiki S3',
            'manufacturer' => 'Y',
            'category' => 'Obuwie',
            'description' => 'Obuwie ochronne zimowe S3.',
            'catalog_price_net' => 40,
            'purchase_price' => 20,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(
            [
                'needed' => 'ocieplana kurtka ochronna',
                'search_phrases' => ['kurtka ochronna', 'ocieplana'],
            ],
            [
                'matches' => [
                    ['id' => $jacket->id, 'score' => 88, 'reason' => 'Kurtka ocieplana z kapturem'],
                ],
            ],
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'Kurtka ochronna ocieplana z odpinanym kapturem. Klasa 1 widzialności.',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.sku', 'JKT-1');
    }
}
