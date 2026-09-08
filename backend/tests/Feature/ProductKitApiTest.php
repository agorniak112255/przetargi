<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAccessory;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class ProductKitApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_index_can_filter_products_with_accessories(): void
    {
        $withKit = $this->product(['sku' => 'MASK-1', 'name' => 'Półmaska Secura 3000', 'manufacturer' => 'SECURA']);
        $plain = $this->product(['sku' => 'PLAIN-1', 'name' => 'Okulary zwykłe', 'manufacturer' => 'Uvex']);
        $filter = $this->product(['sku' => 'FIL-1', 'name' => 'Pochłaniacz Secura 3025', 'manufacturer' => 'SECURA']);
        ProductAccessory::query()->create([
            'product_id' => $withKit->id,
            'related_product_id' => $filter->id,
            'source' => ProductAccessory::SOURCE_MANUAL,
            'link_key' => 'm:'.$filter->id,
            'related_sku' => $filter->sku,
            'related_name' => $filter->name,
            'score' => 100,
            'method' => 'manual',
        ]);

        $this->getJson('/api/products?has_accessories=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'MASK-1');

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('total', 3);

        $this->assertNotNull($plain->id);
    }

    public function test_attach_and_detach_kit_items(): void
    {
        $mask = $this->product(['sku' => 'SEC-3000', 'name' => 'Półmaska Secura 3000', 'manufacturer' => 'SECURA']);
        $filter = $this->product([
            'sku' => '3025',
            'name' => 'Pochłaniacz Secura 3025',
            'manufacturer' => 'SECURA',
            'description' => 'Pochłaniacz A2 do półmasek Secura 3000.',
        ]);
        ProductImage::query()->create([
            'product_id' => $filter->id,
            'path' => 'remote',
            'source_url' => 'https://example.test/filtr.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $glasses = $this->product(['sku' => 'UV-1', 'name' => 'Okulary ochronne', 'manufacturer' => 'Uvex']);

        $this->postJson('/api/products/'.$mask->id.'/kit', [
            'related_product_ids' => [$filter->id, $glasses->id, $mask->id],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'accessories')
            ->assertJsonPath('accessories.0.sku', '3025')
            ->assertJsonPath('accessories.0.image_url', 'https://example.test/filtr.jpg')
            ->assertJsonPath('accessories.0.source', 'manual');

        $this->getJson('/api/products/'.$mask->id)
            ->assertOk()
            ->assertJsonCount(2, 'accessories')
            ->assertJsonPath('accessories.0.short_description', 'SECURA — Pochłaniacz A2 do półmasek Secura 3000.');

        $firstId = (int) $mask->accessories()->orderBy('id')->value('id');
        $this->deleteJson('/api/products/'.$mask->id.'/kit', [
            'accessory_ids' => [$firstId],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'accessories');

        $this->deleteJson('/api/products/'.$mask->id.'/kit', ['all' => true])
            ->assertOk()
            ->assertJsonCount(0, 'accessories');
    }

    public function test_suggest_returns_ai_picks_with_photo_and_description(): void
    {
        $mask = $this->product(['sku' => 'SEC-3000', 'name' => 'Półmaska Secura 3000', 'manufacturer' => 'SECURA']);
        $filter = $this->product([
            'sku' => '3041',
            'name' => 'Filtropochłaniacz Secura 3041',
            'manufacturer' => 'SECURA',
            'description' => 'A2B2E2K2 do półmaski 3000.',
        ]);
        ProductImage::query()->create([
            'product_id' => $filter->id,
            'path' => 'remote',
            'source_url' => 'https://example.test/3041.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $this->product(['sku' => 'JACK-1', 'name' => 'Kurtka ocieplana', 'manufacturer' => 'Reis']);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'picks' => [
                ['id' => $filter->id, 'role' => 'filtr', 'reason' => 'Pochłaniacz do tej półmaski'],
                ['id' => 999999, 'role' => 'śmieć', 'reason' => 'poza pulą'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/'.$mask->id.'/kit-suggestions')
            ->assertOk()
            ->assertJsonPath('family', 'respiratory')
            ->assertJsonPath('suggestions.0.id', $filter->id)
            ->assertJsonPath('suggestions.0.role', 'filtr')
            ->assertJsonPath('suggestions.0.image_url', 'https://example.test/3041.jpg')
            ->assertJsonCount(1, 'suggestions');
    }

    public function test_ai_task_catalog_includes_kit_suggest(): void
    {
        $this->assertContains('kit_suggest', AiTask::keys());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides): Product
    {
        return Product::query()->create(array_merge([
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ], $overrides));
    }
}
