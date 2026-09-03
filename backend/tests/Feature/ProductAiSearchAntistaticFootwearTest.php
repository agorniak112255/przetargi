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

final class ProductAiSearchAntistaticFootwearTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_rubber_esd_query_excludes_non_esd_gum_boots_and_lists_esd_footwear(): void
    {
        $fireman = Product::query()->create([
            'sku' => 'V262-0-02',
            'name' => 'FIREMAN (02 NAVY)',
            'manufacturer' => 'Cofra',
            'description' => 'Buty gumowe FIREMAN bez ESD.',
            'catalog_price_net' => 300,
            'purchase_price' => 200,
            'stock' => 2,
            'ppe_family' => 'footwear',
        ]);
        $esd1 = Product::query()->create([
            'sku' => 'ESD-KAL-1',
            'name' => 'Kalosze damskie gumowe ESD',
            'manufacturer' => 'VM',
            'description' => 'Kalosze antyelektrostatyczne EN 1149.',
            'catalog_price_net' => 120,
            'purchase_price' => 80,
            'stock' => 3,
            'ppe_family' => 'footwear',
        ]);
        $esd2 = Product::query()->create([
            'sku' => '8145-S1PL ESD',
            'name' => 'KENTUCKY ESD',
            'manufacturer' => 'VM Footwear',
            'description' => 'Półbuty robocze ESD.',
            'catalog_price_net' => 340,
            'purchase_price' => 220,
            'stock' => 2,
            'ppe_family' => 'footwear',
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function (array $messages) use ($fireman, $esd1): array {
            $system = (string) ($messages[0]['content'] ?? '');
            if (str_contains($system, '"manufacturer"')) {
                return [
                    'needed' => 'buty gumowe damskie antyelektrostatyczne',
                    'manufacturer' => null,
                    'search_phrases' => ['buty gumowe', 'esd', 'kalosze'],
                    'constraints' => ['antyelektrostatyczne'],
                ];
            }

            return [
                'matches' => [
                    ['id' => $fireman->id, 'score' => 70, 'reason' => 'buty gumowe'],
                    ['id' => $esd1->id, 'score' => 40, 'reason' => 'esd'],
                ],
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $response = $this->postJson('/api/products/ai-search', [
            'query' => 'BUTY gumowe DAMSKIE antyelektrostatyczne',
            'limit' => 10,
        ])->assertOk();

        $skus = array_column($response->json('products'), 'sku');
        $this->assertNotContains('V262-0-02', $skus);
        $this->assertContains('ESD-KAL-1', $skus);
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
    }
}
