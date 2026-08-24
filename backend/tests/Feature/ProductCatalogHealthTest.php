<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\OfferPricing;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductCatalogHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_catalog_health_report(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'H1',
            'name' => 'Bez opisu',
            'manufacturer' => 'ATG',
            'description' => null,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
        Product::query()->create([
            'sku' => 'H2',
            'name' => 'Z opisem',
            'manufacturer' => 'ATG',
            'description' => 'Rękawica ochronna z nitrylem EN 388',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'attributes' => [
                    'kategoria_bhp' => 'rekawice',
                    'material' => 'nitryl',
                ],
            ],
        ]);

        $this->getJson('/api/products/catalog-health')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('missing_description', 1)
            ->assertJsonPath('not_enriched', 1)
            ->assertJsonPath('offer_markup_percent', 18);

        $this->assertSame(9.44, OfferPricing::fromPurchase(8.0));
    }

    public function test_backfill_attributes_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $p = Product::query()->create([
            'sku' => 'H3',
            'name' => 'KW Palm Coated',
            'manufacturer' => 'ATG',
            'description' => 'Rękawica ochronna z powłoką nitrylową. EN 388:2016 4131A',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
            'enrichment_payload' => null,
        ]);

        $bezOpisu = Product::query()->create([
            'sku' => 'H4',
            'name' => 'XG27B-450',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
            'enrichment_payload' => null,
        ]);

        $this->postJson('/api/products/catalog-health/backfill-attributes')
            ->assertOk()
            ->assertJsonPath('updated', 2)
            ->assertJsonPath('filled', 1)
            ->assertJsonPath('pending', 1);

        $p->refresh();
        $attrs = $p->enrichment_payload['attributes'] ?? [];
        $this->assertSame('rekawice', $attrs['kategoria_bhp'] ?? null);
        $this->assertSame('nitryl', $attrs['material'] ?? null);

        $bezOpisu->refresh();
        $puste = $bezOpisu->enrichment_payload['attributes'] ?? [];
        $this->assertNull($puste['material'] ?? null);
        $this->assertNull($puste['kategoria_bhp'] ?? null);
    }
}
