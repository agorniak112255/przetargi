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

final class ProductAiSearchStructuredIntentTest extends TestCase
{
    use RefreshDatabase;

    private const CERVA_BOOTS = 'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO OB. SRA prod.CERVA · EN ISO 20347';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_absent_manufacturer_returns_alternatives_not_random_product(): void
    {
        Product::query()->create([
            'sku' => '28-0001.00/858.0_3400',
            'name' => 'Getry żaroodp. metalizowane 858.0 wys. 34 cm, rozm. 41-42',
            'manufacturer' => 'ALWIT POLAND',
            'catalog_price_net' => 100,
            'purchase_price' => 50,
            'stock' => 1,
        ]);
        $substitute = Product::query()->create([
            'sku' => 'ESD-KAL-CERVA-SUB',
            'name' => 'Kalosze damskie gumowe ESD',
            'manufacturer' => 'VM Footwear',
            'description' => 'Antyelektrostatyczne, EN ISO 20347, podeszwa gumowa.',
            'catalog_price_net' => 120,
            'purchase_price' => 80,
            'stock' => 4,
            'ppe_family' => 'footwear',
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function (array $messages) use ($substitute): array {
            $system = (string) ($messages[0]['content'] ?? '');
            if (str_contains($system, '"manufacturer"')) {
                return [
                    'needed' => 'buty gumowe damskie antyelektrostatyczne',
                    'manufacturer' => 'CERVA',
                    'model_name' => 'TRONCHETTO OB SRA',
                    'size_note' => '35-41',
                    'search_phrases' => ['buty gumowe', 'antyelektrostatyczne', 'EN ISO 20347'],
                    'constraints' => ['EN ISO 20347'],
                ];
            }

            return [
                'matches' => [
                    ['id' => $substitute->id, 'score' => 75, 'reason' => 'ESD obuwie damskie'],
                ],
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $response = $this->postJson('/api/products/ai-search', [
            'query' => self::CERVA_BOOTS,
            'limit' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('parsed_intent.manufacturer_absent_in_catalog', true)
            ->assertJsonPath('parsed_intent.manufacturer_requested', 'CERVA')
            ->assertJsonMissing(['sku' => '28-0001.00/858.0_3400']);

        $this->assertGreaterThanOrEqual(1, $response->json('total'));
        $this->assertStringContainsString('CERVA', (string) $response->json('ai_note'));
        $this->assertStringContainsString('VM Footwear', (string) $response->json('ai_note'));
        $skus = array_column($response->json('products'), 'sku');
        $this->assertContains('ESD-KAL-CERVA-SUB', $skus);
    }
}
