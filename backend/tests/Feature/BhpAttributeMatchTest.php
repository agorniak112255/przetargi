<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BhpAttributeMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribute_norms_and_class_boost_score(): void
    {
        $withAttrs = Product::query()->create([
            'sku' => 'BOOT-S3-A',
            'name' => 'Trzewiki ochronne',
            'manufacturer' => 'BrandA',
            'category' => 'Obuwie',
            'description' => 'Obuwie BHP',
            'norms' => 'EN ISO 20345',
            'catalog_price_net' => 100,
            'purchase_price' => 60,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['skóra'],
                'norms' => ['EN ISO 20345'],
                'attributes' => [
                    'kategoria_bhp' => 'obuwie',
                    'material' => 'skóra',
                    'normy_en' => ['EN ISO 20345'],
                    'klasa_ochrony' => 'S3',
                    'kod_producenta' => 'BOOT-S3-A',
                    'materialy' => ['skóra'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $weak = Product::query()->create([
            'sku' => 'BOOT-X',
            'name' => 'Trzewiki inne',
            'manufacturer' => 'BrandB',
            'category' => 'Obuwie',
            'description' => 'Obuwie bez norm',
            'catalog_price_net' => 90,
            'purchase_price' => 50,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => []],
            'enriched_at' => now(),
        ]);

        $matcher = app(ProductMatchService::class);
        $req = 'Trzewiki ochronne S3 EN ISO 20345 skóra';
        $explainedGood = $matcher->explainMatch($req, $withAttrs);
        $explainedWeak = $matcher->explainMatch($req, $weak);

        $this->assertGreaterThan($explainedWeak['score'], $explainedGood['score']);
        $codes = collect($explainedGood['reasons'])->pluck('code')->all();
        $this->assertTrue(
            in_array('attr_norma', $codes, true) || in_array('attr_klasa', $codes, true),
            'Oczekiwano powodów atrybutów w explainMatch'
        );
    }
}
