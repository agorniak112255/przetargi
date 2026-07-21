<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BhpAttributeNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_llm_attributes_and_derives_missing(): void
    {
        $n = new BhpAttributeNormalizer;
        $attrs = $n->normalize(
            [
                'kategoria_bhp' => 'rękawice',
                'material' => 'nitryl',
                'normy_en' => ['EN 388:2016'],
                'poziomy_en388' => '4X42C',
            ],
            [
                'materials' => ['poliamid'],
                'specs' => ['Rozmiar: 9', 'Klasa: S3'],
                'sku' => 'RNITZ-9',
                'name' => 'Rękawice nitrylowe',
                'category' => 'Rękawice',
            ]
        );

        $this->assertSame('rekawice', $attrs['kategoria_bhp']);
        $this->assertSame('nitryl', $attrs['material']);
        $this->assertContains('nitryl', $attrs['materialy']);
        $this->assertContains('poliamid', $attrs['materialy']);
        $this->assertSame('RNITZ-9', $attrs['kod_producenta']);
        $this->assertSame('4X42C', $attrs['poziomy_en388']);
        $this->assertSame('9', $attrs['rozmiar']);
    }

    public function test_for_product_derives_from_enrichment_lists(): void
    {
        $product = Product::query()->create([
            'sku' => 'C300',
            'name' => 'Rękawice ochronne cut',
            'manufacturer' => 'uvex',
            'category' => 'Rękawice',
            'norms' => 'EN 388:2016 4X42C',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['Dyneema', 'poliamid'],
                'norms' => ['EN 388:2016 4X42C'],
                'specs' => [],
            ],
        ]);

        $attrs = (new BhpAttributeNormalizer)->forProduct($product);

        $this->assertSame('rekawice', $attrs['kategoria_bhp']);
        $this->assertSame('Dyneema', $attrs['material']);
        $this->assertNotEmpty($attrs['normy_en']);
        $this->assertSame('4X42C', $attrs['poziomy_en388']);
    }
}
