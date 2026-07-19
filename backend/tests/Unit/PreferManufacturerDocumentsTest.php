<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use ReflectionMethod;
use Tests\TestCase;

final class PreferManufacturerDocumentsTest extends TestCase
{
    public function test_falls_back_to_shop_pdf_when_no_manufacturer_hit(): void
    {
        $svc = $this->app->make(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => '60549',
            'name' => 'C300 Dry',
            'manufacturer' => 'uvex',
        ]);

        $method = new ReflectionMethod(ProductEnrichmentService::class, 'preferManufacturerDocuments');
        $method->setAccessible(true);

        $out = $method->invoke(
            $svc,
            [
                'https://www.bhp-gabi.com/files/c300-dry-cert.pdf',
                'https://example.com/not-a-doc.html',
            ],
            $product,
            ['uvex-safety.com']
        );

        $this->assertSame(['https://www.bhp-gabi.com/files/c300-dry-cert.pdf'], $out);
    }

    public function test_prefers_sku_in_cdn_url(): void
    {
        $svc = $this->app->make(ProductEnrichmentService::class);
        $product = new Product([
            'sku' => '60549',
            'name' => 'C300 Dry',
            'manufacturer' => 'uvex',
        ]);

        $method = new ReflectionMethod(ProductEnrichmentService::class, 'preferManufacturerDocuments');
        $method->setAccessible(true);

        $out = $method->invoke(
            $svc,
            [
                'https://cdn.example.com/docs/60549_DoC_EN.pdf',
                'https://www.bhp-gabi.com/files/other.pdf',
            ],
            $product,
            ['uvex-safety.com']
        );

        $this->assertSame(['https://cdn.example.com/docs/60549_DoC_EN.pdf'], $out);
    }
}
