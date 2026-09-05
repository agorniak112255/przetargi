<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\CatalogCascadeRecall;
use App\Support\CatalogManufacturerContext;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogCascadeRecallTest extends TestCase
{
    use RefreshDatabase;

    public function test_layers_drop_absent_brand_and_keep_nitrile_feature(): void
    {
        $layers = $this->app->make(CatalogCascadeRecall::class)->layers(
            'Rękawice nitrylowe RTELA',
            [
                'needed' => 'rękawice nitrylowe',
                'search_phrases' => ['rękawice nitrylowe'],
                'constraints' => [],
                'manufacturer' => null,
                'manufacturer_requested' => 'RTELA',
                'manufacturer_absent_in_catalog' => true,
            ]
        );

        $this->assertSame(PpeAssortment::FAMILY_GLOVES, $layers['family']);
        $this->assertNull($layers['manufacturer']);
        $this->assertNotEmpty(array_filter(
            $layers['features'],
            static fn (string $t): bool => str_contains($t, 'nitryl')
        ));
    }

    public function test_peels_brand_when_no_ansell_nitrile(): void
    {
        Product::query()->create([
            'sku' => 'ANS-LEATHER',
            'name' => 'Rękawice skórzane spawalnicze',
            'manufacturer' => 'Ansell',
            'description' => 'Skóra licowa.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 2,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        $delta = Product::query()->create([
            'sku' => '93-843',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe',
            'manufacturer' => 'Delta Plus',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 10,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice nitrylowe Ansell',
            [
                'needed' => 'rękawice nitrylowe',
                'search_phrases' => ['rękawice nitrylowe'],
                'constraints' => [],
                'manufacturer' => 'Ansell',
                'manufacturer_requested' => 'Ansell',
                'manufacturer_absent_in_catalog' => false,
            ],
            'Rękawice nitrylowe Ansell',
            20
        );

        $this->assertSame(CatalogCascadeRecall::LEVEL_FAMILY_FEATURE, $hit['level']);
        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('93-843', $skus);
        $this->assertNotContains('ANS-LEATHER', $skus);
        $this->assertSame($delta->id, $hit['products']->first()?->id);
    }

    public function test_keeps_ansell_when_nitrile_exists(): void
    {
        Product::query()->create([
            'sku' => '93-843',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe',
            'manufacturer' => 'Ansell',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 20,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'VE727',
            'name' => 'Rękawice dziane, dłoń powlekana nitrylem',
            'manufacturer' => 'Delta Plus',
            'description' => 'Rękawice nitrylowe powlekane.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 10,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice nitrylowe Ansell',
            [
                'needed' => 'rękawice nitrylowe',
                'search_phrases' => ['rękawice nitrylowe'],
                'constraints' => [],
                'manufacturer' => 'Ansell',
                'manufacturer_requested' => 'Ansell',
                'manufacturer_absent_in_catalog' => false,
            ],
            'Rękawice nitrylowe Ansell',
            20
        );

        $this->assertSame(CatalogCascadeRecall::LEVEL_FAMILY_FEATURE_BRAND, $hit['level']);
        $this->assertSame(['93-843'], $hit['products']->pluck('sku')->all());
    }

    public function test_search_steps_and_then_peel_manufacturer(): void
    {
        Product::query()->create([
            'sku' => 'ANS-LEATHER',
            'name' => 'Rękawice skórzane spawalnicze',
            'manufacturer' => 'Ansell',
            'description' => 'Skóra licowa.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 2,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        $delta = Product::query()->create([
            'sku' => '93-843',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe',
            'manufacturer' => 'Delta Plus',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 10,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice nitrylowe Ansell',
            [
                'needed' => 'rękawice nitrylowe',
                'search_phrases' => ['rękawice nitrylowe'],
                'search_steps' => ['rękawice', 'nitrylowe', 'Ansell'],
                'constraints' => [],
                'manufacturer' => 'Ansell',
                'manufacturer_requested' => 'Ansell',
                'manufacturer_absent_in_catalog' => false,
            ],
            'Rękawice nitrylowe Ansell',
            20
        );

        $this->assertSame('steps_2', $hit['level']);
        $this->assertContains('93-843', $hit['products']->pluck('sku')->all());
        $this->assertNotContains('ANS-LEATHER', $hit['products']->pluck('sku')->all());
        $this->assertSame($delta->id, $hit['products']->first()?->id);
    }

    public function test_pcv_query_keeps_pvc_token_and_finds_pvc_glove(): void
    {
        Product::query()->create([
            'sku' => 'COAT-NIT',
            'name' => 'Rękawice dziane, dłoń powlekana nitrylem',
            'manufacturer' => 'Delta Plus',
            'description' => 'Powlekane do oleju.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 10,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
        ]);
        $pvc = Product::query()->create([
            'sku' => 'A835',
            'name' => 'Rękawice PCV długie do łokcia',
            'manufacturer' => 'Portwest',
            'description' => 'Rękawice z PCV, mankiet do łokcia.',
            'catalog_price_net' => 12,
            'purchase_price' => 9.25,
            'stock' => 8,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice PCV długie do łokci',
            [
                'needed' => 'rękawice PVC',
                'search_phrases' => ['rękawice PVC', 'rękawice PCV'],
                'search_steps' => ['rękawice', 'PCV'],
                'constraints' => [],
            ],
            'Rękawice PCV długie do łokci',
            20
        );

        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('A835', $skus);
        $this->assertNotContains('COAT-NIT', $skus);
        $this->assertSame($pvc->id, $hit['products']->first()?->id);
    }

    public function test_nitrile_material_drops_knit_palm_coat_even_when_lekkie_matches(): void
    {
        Product::query()->create([
            'sku' => 'R840',
            'name' => 'Dziane rękawice przeznaczone do prac lekkich z powlekaną nitrylem dłonią',
            'manufacturer' => 'Ansell',
            'description' => 'Rękawice do prac lekkich powlekane nitrylem.',
            'catalog_price_net' => 9.2,
            'purchase_price' => 7.27,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $nitrile = Product::query()->create([
            'sku' => '93-843',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe',
            'manufacturer' => 'Ansell',
            'description' => 'Jednorazowe rękawice nitrylowe.',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 20,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        CatalogManufacturerContext::forgetCache();

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'Rękawice nitrylowe lekkie',
            [
                'needed' => 'rękawice nitrylowe',
                'search_phrases' => ['rękawice nitrylowe'],
                'search_steps' => ['rękawice', 'nitrylowe', 'lekkie'],
                'constraints' => [],
            ],
            'Rękawice nitrylowe lekkie',
            20
        );

        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('93-843', $skus);
        $this->assertNotContains('R840', $skus);
        $this->assertSame($nitrile->id, $hit['products']->first()?->id);
    }

    public function test_last_remaining_word_is_not_truncated(): void
    {
        Product::query()->create([
            'sku' => 'SB085290N',
            'name' => 'Scotch-Brite Scierka z mikrowlokna',
            'manufacturer' => '3M',
            'description' => 'Scierka.',
            'catalog_price_net' => 6,
            'purchase_price' => 3,
            'stock' => 10,
        ]);
        Product::query()->create([
            'sku' => '51548',
            'name' => 'Krazek scierny 3M Hookit Gold 288U',
            'manufacturer' => '3M',
            'description' => 'Krazek scierny.',
            'catalog_price_net' => 2,
            'purchase_price' => 1,
            'stock' => 50,
        ]);

        $hit = $this->app->make(CatalogCascadeRecall::class)->retrieve(
            'ŚCIERKA TETRA 60 x 85 cm',
            [
                'needed' => 'ścierka tetra',
                'search_phrases' => ['ścierka tetra'],
                'search_steps' => ['ścierka', 'tetra'],
                'constraints' => [],
            ],
            'ŚCIERKA TETRA 60 x 85 cm',
            20
        );

        $skus = $hit['products']->pluck('sku')->all();
        $this->assertContains('SB085290N', $skus);
        $this->assertNotContains('51548', $skus);
    }
}
