<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductCrossRefCompareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cross_ref_finds_other_brand_equivalents(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $seed = Product::query()->create([
            'sku' => 'RNITZ-SEED',
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe RNITZ',
            'catalog_price_net' => 3.5,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['nitryl'],
                'attributes' => [
                    'kategoria_bhp' => 'rekawice',
                    'material' => 'nitryl',
                    'kod_producenta' => 'RNITZ-SEED',
                    'materialy' => ['nitryl'],
                    'normy_en' => ['EN 388'],
                    'klasa_ochrony' => 'kat. II',
                    'rozmiar' => null,
                    'poziomy_en388' => '4544',
                ],
            ],
            'enriched_at' => now(),
        ]);

        $other = Product::query()->create([
            'sku' => 'NITRIL-OTHER',
            'name' => 'Rękawice nitrylowe ochronne',
            'manufacturer' => 'OTHERBRAND',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe ze ściągaczem EN 388',
            'catalog_price_net' => 2.9,
            'purchase_price' => 1.8,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['nitryl'],
                'attributes' => [
                    'kategoria_bhp' => 'rekawice',
                    'material' => 'nitryl',
                    'kod_producenta' => 'NITRIL-OTHER',
                    'materialy' => ['nitryl'],
                    'normy_en' => ['EN 388'],
                    'klasa_ochrony' => 'kat. II',
                    'rozmiar' => null,
                    'poziomy_en388' => '4544',
                ],
            ],
            'enriched_at' => now(),
        ]);

        $this->getJson('/api/products/cross-ref?code=RNITZ-SEED')
            ->assertOk()
            ->assertJsonPath('seed.sku', 'RNITZ-SEED')
            ->assertJsonPath('seed.product_id', $seed->id)
            ->assertJsonFragment(['sku' => 'NITRIL-OTHER', 'product_id' => $other->id]);
    }

    public function test_cross_ref_rejects_footwear_vs_gloves_sibling_skus(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'PROS-1000',
            'name' => '1000',
            'manufacturer' => 'PROS',
            'category' => 'Obuwie',
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'attributes' => [
                    'kategoria_bhp' => 'obuwie',
                    'material' => 'RUBBER',
                    'normy_en' => ['EN 20347'],
                    'klasa_ochrony' => 'OB',
                    'kod_producenta' => 'PROS-1000',
                    'materialy' => ['RUBBER'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => 'PROS-1001',
            'name' => '1001',
            'manufacturer' => 'PROS',
            'category' => 'Rękawice',
            'catalog_price_net' => 40,
            'purchase_price' => 20,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'attributes' => [
                    'kategoria_bhp' => 'rekawice',
                    'material' => 'Plavitex',
                    'normy_en' => ['EN 343'],
                    'klasa_ochrony' => null,
                    'kod_producenta' => 'PROS-1001',
                    'materialy' => ['Plavitex'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $res = $this->getJson('/api/products/cross-ref?code=PROS-1000')->assertOk();
        $skus = collect($res->json('matches'))->pluck('sku')->all();
        $this->assertNotContains('PROS-1001', $skus);
    }

    public function test_cross_ref_rejects_purofort_s5_vs_welding_s3(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => '462933',
            'name' => 'Obuwie ochronne DUNLOP 462933 PUROFORT',
            'manufacturer' => 'DUNLOP',
            'category' => 'Obuwie',
            'description' => 'Kalosz z materiału Purofort S5.CI.SRA, rolnictwo, izolacja -20°C.',
            'catalog_price_net' => 200,
            'purchase_price' => 120,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['Purofort'],
                'use_cases' => ['rolnictwo', 'trudny teren'],
                'attributes' => [
                    'kategoria_bhp' => 'obuwie',
                    'material' => 'Purofort',
                    'normy_en' => ['EN ISO 20345:2011'],
                    'klasa_ochrony' => 'S5.CI.SRA',
                    'kod_producenta' => '462933',
                    'materialy' => ['Purofort'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => '9-075',
            'name' => 'Trzewiki spawalnicze DEMAR 9-075 S3 HRO SRC',
            'manufacturer' => 'DEMAR',
            'category' => 'Obuwie',
            'description' => 'Trzewiki spawalnicze, skóra wodoodporna, klapka na sznurówki, HRO SRC.',
            'catalog_price_net' => 115,
            'purchase_price' => 70,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['skóra', 'PU'],
                'use_cases' => ['spawanie', 'przemysł'],
                'attributes' => [
                    'kategoria_bhp' => 'obuwie',
                    'material' => 'PU',
                    'normy_en' => ['EN ISO 20345'],
                    'klasa_ochrony' => 'S3',
                    'kod_producenta' => '9-075',
                    'materialy' => ['skóra', 'PU'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $res = $this->getJson('/api/products/cross-ref?code=462933')->assertOk();
        $this->assertNotContains('9-075', collect($res->json('matches'))->pluck('sku')->all());
    }

    public function test_cross_ref_matches_other_brand_s5_wellington(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'PURO-SEED',
            'name' => 'Kalosz Purofort S5 CI',
            'manufacturer' => 'DUNLOP',
            'category' => 'Obuwie',
            'description' => 'Kalosz Purofort S5.CI.SRA do rolnictwa.',
            'catalog_price_net' => 200,
            'purchase_price' => 120,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['Purofort'],
                'use_cases' => ['rolnictwo'],
                'attributes' => [
                    'kategoria_bhp' => 'obuwie',
                    'material' => 'Purofort',
                    'normy_en' => ['EN ISO 20345'],
                    'klasa_ochrony' => 'S5',
                    'kod_producenta' => 'PURO-SEED',
                    'materialy' => ['Purofort'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $other = Product::query()->create([
            'sku' => 'PURO-ALT',
            'name' => 'Kalosz gumowy S5 CI rolniczy',
            'manufacturer' => 'BEKINA',
            'category' => 'Obuwie',
            'description' => 'Kalosz Purofort-like S5.CI.SRA, rolnictwo, -20°C.',
            'catalog_price_net' => 180,
            'purchase_price' => 100,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['guma'],
                'use_cases' => ['rolnictwo'],
                'attributes' => [
                    'kategoria_bhp' => 'obuwie',
                    'material' => 'guma',
                    'normy_en' => ['EN ISO 20345'],
                    'klasa_ochrony' => 'S5',
                    'kod_producenta' => 'PURO-ALT',
                    'materialy' => ['guma'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $this->getJson('/api/products/cross-ref?code=PURO-SEED')
            ->assertOk()
            ->assertJsonFragment(['sku' => 'PURO-ALT', 'product_id' => $other->id]);
    }

    public function test_compare_highlights_attribute_diffs_and_siwz_scores(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $a = Product::query()->create([
            'sku' => 'CMP-A',
            'name' => 'Rękawice nitrylowe A',
            'manufacturer' => 'BrandA',
            'category' => 'Rękawice',
            'description' => 'Nitryl EN 388',
            'catalog_price_net' => 4,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'attributes' => [
                    'kategoria_bhp' => 'rekawice',
                    'material' => 'nitryl',
                    'normy_en' => ['EN 388'],
                    'klasa_ochrony' => 'kat. II',
                    'poziomy_en388' => '4544',
                    'kod_producenta' => 'CMP-A',
                    'materialy' => ['nitryl'],
                    'rozmiar' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $b = Product::query()->create([
            'sku' => 'CMP-B',
            'name' => 'Rękawice lateksowe B',
            'manufacturer' => 'BrandB',
            'category' => 'Rękawice',
            'description' => 'Lateks',
            'catalog_price_net' => 3,
            'purchase_price' => 1.5,
            'stock' => 8,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'attributes' => [
                    'kategoria_bhp' => 'rekawice',
                    'material' => 'lateks',
                    'normy_en' => [],
                    'klasa_ochrony' => null,
                    'poziomy_en388' => null,
                    'kod_producenta' => 'CMP-B',
                    'materialy' => ['lateks'],
                    'rozmiar' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $this->getJson('/api/products/compare?a='.$a->id.'&b='.$b->id.'&requirement='.urlencode('Rękawice nitrylowe EN 388 4544'))
            ->assertOk()
            ->assertJsonPath('a.sku', 'CMP-A')
            ->assertJsonPath('b.sku', 'CMP-B')
            ->assertJsonPath('summary.winner', 'a')
            ->assertJsonStructure(['rows', 'summary' => ['diffs', 'a_score', 'b_score']]);
    }

    public function test_compare_accepts_between_two_and_five_distinct_products(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $products = collect(range(1, 6))->map(static fn (int $index): Product => Product::query()->create([
            'sku' => 'MULTI-'.$index,
            'name' => 'Produkt porównania '.$index,
            'manufacturer' => 'Marka '.$index,
            'category' => 'Rękawice',
            'catalog_price_net' => 10 + $index,
            'purchase_price' => 5 + $index,
            'stock' => $index,
        ]));

        $threeIds = $products->take(3)->pluck('id')->all();
        $this->getJson('/api/products/compare?'.http_build_query(['ids' => $threeIds]))
            ->assertOk()
            ->assertJsonCount(3, 'products')
            ->assertJsonPath('products.0.sku', 'MULTI-1')
            ->assertJsonCount(3, 'rows.0.values')
            ->assertJsonStructure(['summary' => ['winner_product_id', 'tie', 'diffs']]);

        $sixIds = $products->pluck('id')->all();
        $this->getJson('/api/products/compare?'.http_build_query(['ids' => $sixIds]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
    }
}
