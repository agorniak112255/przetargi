<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\CatalogManufacturerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogManufacturerContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CatalogManufacturerContext::forgetCache();
    }

    public function test_match_manufacturer_uses_catalog_name(): void
    {
        Product::query()->create([
            'sku' => 'U-1',
            'name' => 'Test',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
        ]);

        $ctx = new CatalogManufacturerContext;

        $this->assertSame('Ansell', $ctx->matchManufacturer('ANSELL'));
        $this->assertTrue($ctx->hasProductsForManufacturer('Ansell'));
        $this->assertNull($ctx->matchManufacturer('CERVA'));
        $this->assertFalse($ctx->hasProductsForManufacturer('CERVA'));
    }
}
