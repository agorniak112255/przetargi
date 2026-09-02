<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportProductToPrestaJob;
use App\Models\PrestaProductMatch;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use App\Services\Presta\PrestaExportGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePrestaExportGateway;
use Tests\TestCase;

final class PrestaExportApiTest extends TestCase
{
    use RefreshDatabase;

    private FakePrestaExportGateway $presta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->presta = new FakePrestaExportGateway;
        $this->presta->sizeAttributes = ['7' => 75, '8' => 72, '9' => 73, '10' => 71, '11' => 74];
        $this->app->instance(PrestaExportGateway::class, $this->presta);
    }

    public function test_admin_exports_product_with_sizes_and_on_order_delivery(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct([
            'sku' => '11-931',
            'name' => 'HyFlex 11-931',
            'manufacturer' => 'Ansell',
            'packaging' => '7, 8, 9, 10, 11',
            'description' => 'Rękawice antyprzecięciowe z powłoką nitrylową. EN 388 4X42B.',
        ]);

        $this->postJson('/api/products/'.$product->id.'/presta-export')
            ->assertOk()
            ->assertJsonPath('action', 'created')
            ->assertJsonPath('sku', '11-931')
            ->assertJsonPath('sizes.0', '7')
            ->assertJsonPath('sizes.4', '11');

        $this->assertCount(1, $this->presta->created);
        $this->assertSame(['Ansell'], $this->presta->createdManufacturers);
        $this->assertSame(100, $this->presta->created[0]['id_manufacturer']);
        $this->assertSame('Na zamówienie', $this->presta->created[0]['delivery_label']);
        $this->assertStringContainsString('antyprzecięciowe', $this->presta->created[0]['description']);
        $this->assertNotEmpty($this->presta->combinations);
        $this->assertCount(5, $this->presta->combinations[0]['items']);
        $this->assertDatabaseHas('presta_product_matches', [
            'product_id' => $product->id,
            'status' => PrestaProductMatch::STATUS_EXPORTED,
        ]);
    }

    public function test_export_reuses_existing_manufacturer(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->presta->manufacturers['ansell'] = 12;
        $product = $this->makeProduct(['manufacturer' => 'Ansell']);

        $this->postJson('/api/products/'.$product->id.'/presta-export')
            ->assertOk()
            ->assertJsonPath('action', 'created');

        $this->assertSame([], $this->presta->createdManufacturers);
        $this->assertSame(12, $this->presta->created[0]['id_manufacturer']);
    }

    public function test_handlowiec_cannot_export(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $product = $this->makeProduct();

        $this->postJson('/api/products/'.$product->id.'/presta-export')
            ->assertForbidden();
    }

    public function test_missing_webservice_key_returns_422(): void
    {
        $this->presta->configured = false;
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct();

        $this->postJson('/api/products/'.$product->id.'/presta-export')
            ->assertStatus(422)
            ->assertJsonPath('message', $this->presta->error);
    }

    public function test_second_export_without_force_is_skipped(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct(['sku' => '11-931']);

        $this->postJson('/api/products/'.$product->id.'/presta-export')->assertOk()->assertJsonPath('action', 'created');
        $this->postJson('/api/products/'.$product->id.'/presta-export')->assertOk()->assertJsonPath('action', 'exists');
        $this->assertCount(1, $this->presta->created);
        $this->assertCount(0, $this->presta->updated);

        $this->postJson('/api/products/'.$product->id.'/presta-export', ['force' => true])
            ->assertOk()
            ->assertJsonPath('action', 'updated');
        $this->assertCount(1, $this->presta->updated);
    }

    public function test_price_list_export_queues_when_over_limit(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            $ids[] = $this->makeProduct(['sku' => 'SKU-'.$i])->id;
        }
        $list = PriceList::query()->create([
            'manufacturer' => 'Ansell',
            'version' => '2026',
            'original_filename' => 'test.xlsx',
            'product_ids' => $ids,
        ]);

        $this->postJson('/api/price-lists/'.$list->id.'/presta-export')
            ->assertOk()
            ->assertJsonPath('queued', 21);

        Queue::assertPushed(ExportProductToPrestaJob::class, 21);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProduct(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => 'SKU-1',
            'name' => 'Produkt',
            'manufacturer' => 'X',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ], $overrides));
    }
}
