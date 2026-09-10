<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductNormsColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_can_store_norms_longer_than_varchar_255(): void
    {
        $norms = implode(', ', array_fill(0, 12, 'EN ISO 20345:2022 S3 SRC FO WPA'));

        $this->assertGreaterThan(255, strlen($norms));

        $product = Product::query()->create([
            'sku' => '501/A',
            'name' => 'Buty ochronne',
            'manufacturer' => 'Test',
            'norms' => $norms,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);

        $this->assertSame($norms, $product->fresh()?->norms);
    }
};
