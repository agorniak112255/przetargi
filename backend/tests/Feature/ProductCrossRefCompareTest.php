<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
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
            ->assertJsonPath('seed.has_description', true)
            ->assertJsonFragment(['sku' => 'NITRIL-OTHER', 'product_id' => $other->id]);
    }

    public function test_cross_ref_seed_retries_sku_without_leftover_letter(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $seed = Product::query()->create([
            'sku' => 'BLACKTACTIL',
            'name' => 'T6 GLOVES, CUT RESISTANCE LEVEL F, PU, BLACK',
            'manufacturer' => 'Rostaing',
            'category' => 'Rękawice',
            'catalog_price_net' => 11.11,
            'purchase_price' => 5.02,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'attributes' => [
                    'kategoria_bhp' => 'rekawice',
                    'material' => 'pu',
                    'kod_producenta' => 'BLACKTACTIL',
                ],
            ],
            'enriched_at' => now(),
        ]);

        $this->getJson('/api/products/cross-ref?code=BLACKTACTILT')
            ->assertOk()
            ->assertJsonPath('seed.sku', 'BLACKTACTIL')
            ->assertJsonPath('seed.product_id', $seed->id);
    }

    public function test_cross_ref_returns_thumbnail_and_description_flag(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $seed = Product::query()->create([
            'sku' => 'THUMB-SEED',
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe THUMB-SEED do prac chemicznych.',
            'catalog_price_net' => 3.5,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['nitryl'],
                'attributes' => [
                    'kategoria_bhp' => 'rekawice',
                    'material' => 'nitryl',
                    'materialy' => ['nitryl'],
                    'normy_en' => ['EN 388'],
                    'klasa_ochrony' => 'kat. II',
                    'poziomy_en388' => '4544',
                ],
            ],
        ]);
        $other = Product::query()->create([
            'sku' => 'THUMB-OTHER',
            'name' => 'Rękawice nitrylowe ochronne',
            'manufacturer' => 'OTHERBRAND',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe ze ściągaczem EN 388.',
            'catalog_price_net' => 2.9,
            'purchase_price' => 1.8,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['nitryl'],
                'attributes' => [
                    'kategoria_bhp' => 'rekawice',
                    'material' => 'nitryl',
                    'materialy' => ['nitryl'],
                    'normy_en' => ['EN 388'],
                    'klasa_ochrony' => 'kat. II',
                    'poziomy_en388' => '4544',
                ],
            ],
        ]);
        ProductImage::query()->create([
            'product_id' => $other->id,
            'path' => 'products/thumb-other.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'checksum' => 'thumb-other',
        ]);

        $this->getJson('/api/products/cross-ref?code=THUMB-SEED')
            ->assertOk()
            ->assertJsonPath('matches.0.sku', 'THUMB-OTHER')
            ->assertJsonPath('matches.0.has_description', true)
            ->assertJsonPath('matches.0.image_url', $other->fresh()->images->first()?->url());
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

    public function test_cross_ref_matches_ffp_half_masks_and_rejects_reusable(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => '9914',
            'name' => 'Półmaska filtrująca 9914',
            'manufacturer' => '3M',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Półmaska filtrująca FFP1 z węglem i zaworem, EN 149.',
            'catalog_price_net' => 4.99,
            'purchase_price' => 4.29,
            'stock' => 20,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['włóknina'],
                'norms' => ['EN 149'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'włóknina',
                    'normy_en' => ['EN 149'],
                    'klasa_ochrony' => 'FFP1',
                    'kod_producenta' => '9914',
                    'materialy' => ['włóknina'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $ffp = Product::query()->create([
            'sku' => '8822',
            'name' => 'Półmaska filtrująca FFP2',
            'manufacturer' => '3M',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Jednorazowa maska FFP2 EN 149, włóknina.',
            'catalog_price_net' => 3.5,
            'purchase_price' => 2.5,
            'stock' => 30,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['polipropylen'],
                'norms' => ['EN 149'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'polipropylen',
                    'normy_en' => ['EN 149'],
                    'klasa_ochrony' => 'FFP2',
                    'kod_producenta' => '8822',
                    'materialy' => ['polipropylen'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => '6500',
            'name' => 'Półmaska wielorazowa 6500 silikon',
            'manufacturer' => '3M',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Półmaska wielorazowa, część twarzowa z silikonu, filtry osobno.',
            'catalog_price_net' => 40,
            'purchase_price' => 25,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['silikon'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'silikon',
                    'normy_en' => ['EN 140'],
                    'klasa_ochrony' => null,
                    'kod_producenta' => '6500',
                    'materialy' => ['silikon'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $res = $this->getJson('/api/products/cross-ref?code=9914')->assertOk();
        $skus = collect($res->json('matches'))->pluck('sku')->all();
        $this->assertContains('8822', $skus);
        $this->assertNotContains('6500', $skus);
        $this->assertSame($ffp->id, collect($res->json('matches'))->firstWhere('sku', '8822')['product_id']);
    }

    public function test_cross_ref_rejects_goggles_as_glasses_substitute(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'OKU-1',
            'name' => 'Okulary ochronne przezroczyste',
            'manufacturer' => 'uvex',
            'category' => 'Ochrona oczu',
            'description' => 'Okulary EN 166.',
            'catalog_price_net' => 10,
            'purchase_price' => 6,
            'stock' => 8,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'norms' => ['EN 166'],
                'attributes' => [
                    'kategoria_bhp' => 'ochrona_oczu',
                    'normy_en' => ['EN 166'],
                    'kod_producenta' => 'OKU-1',
                    'materialy' => [],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                    'klasa_ochrony' => null,
                    'material' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => 'GOG-1',
            'name' => 'Gogle chemiczne',
            'manufacturer' => '3M',
            'category' => 'Ochrona oczu',
            'description' => 'Gogle EN 166.',
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'norms' => ['EN 166'],
                'attributes' => [
                    'kategoria_bhp' => 'ochrona_oczu',
                    'normy_en' => ['EN 166'],
                    'kod_producenta' => 'GOG-1',
                    'materialy' => [],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                    'klasa_ochrony' => null,
                    'material' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $skus = collect($this->getJson('/api/products/cross-ref?code=OKU-1')->assertOk()->json('matches'))
            ->pluck('sku')->all();
        $this->assertNotContains('GOG-1', $skus);
    }

    public function test_cross_ref_rejects_filter_cartridge_for_fullface_mask(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => '6800',
            'name' => 'Maska pełnotwarzowa 3M 6800',
            'manufacturer' => '3M',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Maska pełnotwarzowa wielorazowa, złącze bagnetowe, filtry serii 2000 i 500, EN 136.',
            'catalog_price_net' => 400,
            'purchase_price' => 250,
            'stock' => 2,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['silikon'],
                'norms' => ['EN 136', 'EN 166'],
                'use_cases' => ['gazy', 'pary', 'pyły'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'silikon',
                    'normy_en' => ['EN 136', 'EN 166'],
                    'klasa_ochrony' => null,
                    'kod_producenta' => '6800',
                    'materialy' => ['silikon'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => '3031',
            'name' => 'Pochłaniacz 3031 A2',
            'manufacturer' => 'SECURA',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Pochłaniacz do półmasek SECURA 2000/3000 i masek pełnotwarzowych, EN 14387, klasa A2.',
            'catalog_price_net' => 33,
            'purchase_price' => 20,
            'stock' => 8,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['tworzywo sztuczne'],
                'norms' => ['EN 14387'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'tworzywo sztuczne',
                    'normy_en' => ['EN 14387'],
                    'klasa_ochrony' => 'A2',
                    'kod_producenta' => '3031',
                    'materialy' => ['tworzywo sztuczne'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $alt = Product::query()->create([
            'sku' => 'ADV-3000',
            'name' => 'Maska pełnotwarzowa Advantage 3000',
            'manufacturer' => 'MSA',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Maska pełnotwarzowa EN 136, wielorazowa.',
            'catalog_price_net' => 393,
            'purchase_price' => 250,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['silikon'],
                'norms' => ['EN 136'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'silikon',
                    'normy_en' => ['EN 136'],
                    'klasa_ochrony' => null,
                    'kod_producenta' => 'ADV-3000',
                    'materialy' => ['silikon'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $matches = collect($this->getJson('/api/products/cross-ref?code=6800')->assertOk()->json('matches'));
        $this->assertNotContains('3031', $matches->pluck('sku')->all());
        $this->assertContains('ADV-3000', $matches->pluck('sku')->all());
        $this->assertSame($alt->id, $matches->firstWhere('sku', 'ADV-3000')['product_id']);
    }

    public function test_cross_ref_scans_whole_catalog_not_a_family_slice(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'MASK-A',
            'name' => 'Półmaska filtrująca FFP2 A',
            'manufacturer' => 'Secura',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Jednorazowa FFP2 EN 149 włóknina.',
            'catalog_price_net' => 2,
            'purchase_price' => 1,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['włóknina'],
                'norms' => ['EN 149'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'włóknina',
                    'normy_en' => ['EN 149'],
                    'klasa_ochrony' => 'FFP2',
                    'kod_producenta' => 'MASK-A',
                    'materialy' => ['włóknina'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        for ($i = 1; $i <= 12; $i++) {
            Product::query()->create([
                'sku' => 'SHOE-'.$i,
                'name' => 'Trzewiki S3 model '.$i,
                'manufacturer' => 'DEMAR',
                'category' => 'Obuwie',
                'description' => 'Trzewiki skórzane S3.',
                'catalog_price_net' => 80,
                'purchase_price' => 40,
                'stock' => 1,
            ]);
        }

        $other = Product::query()->create([
            'sku' => 'MASK-B',
            'name' => 'Półmaska filtrująca FFP2 B',
            'manufacturer' => '3M',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Jednorazowa FFP2 EN 149 polipropylen.',
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['polipropylen'],
                'norms' => ['EN 149'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'polipropylen',
                    'normy_en' => ['EN 149'],
                    'klasa_ochrony' => 'FFP2',
                    'kod_producenta' => 'MASK-B',
                    'materialy' => ['polipropylen'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $this->getJson('/api/products/cross-ref?code=MASK-A')
            ->assertOk()
            ->assertJsonFragment(['sku' => 'MASK-B', 'product_id' => $other->id]);
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

    public function test_cross_ref_options_expose_hard_facts_not_marketing(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => '9322P',
            'name' => 'Półmaska filtrująca Aura 9322+',
            'manufacturer' => '3M',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Półmaska filtrująca FFP2 NR D z zaworem, składana, EN 149.',
            'catalog_price_net' => 8,
            'purchase_price' => 5,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'specs' => ['SKU: 9322+', 'Producent: 3M', 'Klasa ochrony: FFP2 NR D'],
                'features' => ['Zawór Cool Flow', 'Konstrukcja składana, wygodna do kieszeni'],
                'materials' => ['włóknina'],
                'norms' => ['EN 149:2001+A1:2009'],
                'certificates' => ['CE'],
                'use_cases' => ['praca w pyle', 'szlifowanie'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'włóknina',
                    'normy_en' => ['EN 149'],
                    'klasa_ochrony' => 'FFP2 NR D',
                    'kod_producenta' => '9322+',
                    'materialy' => ['włóknina'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $res = $this->getJson('/api/products/cross-ref/options?code=9322P')->assertOk();
        $items = collect($res->json('groups'))->pluck('items')->flatten(1);
        $ids = $items->pluck('id');

        $this->assertContains('spec:typ:ffp', $ids);
        $this->assertContains('spec:klasa:FFP2', $ids);
        $this->assertContains('spec:zawor:1', $ids);
        $this->assertContains('norm:en:149', $ids);
        $this->assertContains('use:v:praca-w-pyle', $ids);
        $this->assertFalse($ids->contains(fn (string $id): bool => str_contains($id, '9322')));
        $this->assertTrue($items->firstWhere('id', 'spec:typ:ffp')['default']);
        $this->assertTrue($items->firstWhere('id', 'norm:en:149')['default']);
        $this->assertFalse($items->firstWhere('id', 'spec:zawor:1')['default']);
    }

    public function test_cross_ref_must_filters_keep_only_matching_substitutes(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'FFP-SEED',
            'name' => 'Półmaska filtrująca FFP2 z zaworem',
            'manufacturer' => '3M',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Jednorazowa FFP2 NR D z zaworem, EN 149, praca w pyle.',
            'catalog_price_net' => 8,
            'purchase_price' => 5,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['włóknina'],
                'norms' => ['EN 149'],
                'use_cases' => ['praca w pyle'],
                'features' => ['zawór wydechowy'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'włóknina',
                    'normy_en' => ['EN 149'],
                    'klasa_ochrony' => 'FFP2',
                    'kod_producenta' => 'FFP-SEED',
                    'materialy' => ['włóknina'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => 'FFP-PLAIN',
            'name' => 'Półmaska filtrująca FFP2 bez zaworu',
            'manufacturer' => 'Honeywell',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Jednorazowa FFP2 EN 149, bez zaworu, włóknina.',
            'catalog_price_net' => 4,
            'purchase_price' => 2,
            'stock' => 20,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['włóknina'],
                'norms' => ['EN 149'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'włóknina',
                    'normy_en' => ['EN 149'],
                    'klasa_ochrony' => 'FFP2',
                    'kod_producenta' => 'FFP-PLAIN',
                    'materialy' => ['włóknina'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => 'FFP-VALVE',
            'name' => 'Półmaska filtrująca FFP2 z zaworem Honeywell',
            'manufacturer' => 'Honeywell',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Jednorazowa FFP2 z zaworem, EN 149, włóknina.',
            'catalog_price_net' => 6,
            'purchase_price' => 3,
            'stock' => 15,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['włóknina'],
                'norms' => ['EN 149'],
                'features' => ['zawór'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'włóknina',
                    'normy_en' => ['EN 149'],
                    'klasa_ochrony' => 'FFP2',
                    'kod_producenta' => 'FFP-VALVE',
                    'materialy' => ['włóknina'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        Product::query()->create([
            'sku' => 'FFP-EN140',
            'name' => 'Półmaska filtrująca FFP2 EN 140',
            'manufacturer' => 'Secura',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Jednorazowa FFP2 z zaworem, EN 140, włóknina.',
            'catalog_price_net' => 5,
            'purchase_price' => 3,
            'stock' => 8,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['włóknina'],
                'norms' => ['EN 140'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'włóknina',
                    'normy_en' => ['EN 140'],
                    'klasa_ochrony' => 'FFP2',
                    'kod_producenta' => 'FFP-EN140',
                    'materialy' => ['włóknina'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $dust = Product::query()->create([
            'sku' => 'FFP-DUST',
            'name' => 'Półmaska filtrująca FFP3 do pyłu',
            'manufacturer' => 'uvex',
            'category' => 'Ochrona dróg oddechowych',
            'description' => 'Jednorazowa FFP3 z zaworem, EN 149, praca w pyle.',
            'catalog_price_net' => 9,
            'purchase_price' => 5,
            'stock' => 6,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => [
                'materials' => ['włóknina'],
                'norms' => ['EN 149'],
                'use_cases' => ['praca w pyle'],
                'features' => ['zawór'],
                'attributes' => [
                    'kategoria_bhp' => 'drogi_oddechowe',
                    'material' => 'włóknina',
                    'normy_en' => ['EN 149'],
                    'klasa_ochrony' => 'FFP3',
                    'kod_producenta' => 'FFP-DUST',
                    'materialy' => ['włóknina'],
                    'rozmiar' => null,
                    'poziomy_en388' => null,
                ],
            ],
            'enriched_at' => now(),
        ]);

        $normMust = $this->getJson(
            '/api/products/cross-ref?'.http_build_query([
                'code' => 'FFP-SEED',
                'must' => ['spec:klasa:FFP2', 'norm:en:149'],
            ])
        )->assertOk();
        $normSkus = collect($normMust->json('matches'))->pluck('sku')->all();
        $this->assertContains('FFP-PLAIN', $normSkus);
        $this->assertContains('FFP-VALVE', $normSkus);
        $this->assertContains('FFP-DUST', $normSkus);
        $this->assertNotContains('FFP-EN140', $normSkus);
        $this->assertSame('Klasa: FFP2', collect($normMust->json('applied_filters'))->firstWhere('id', 'spec:klasa:FFP2')['label'] ?? null);
        $this->assertSame($dust->id, collect($normMust->json('matches'))->firstWhere('sku', 'FFP-DUST')['product_id']);

        $valveSkus = collect(
            $this->getJson(
                '/api/products/cross-ref?'.http_build_query([
                    'code' => 'FFP-SEED',
                    'must' => ['spec:zawor:1'],
                ])
            )->assertOk()->json('matches')
        )->pluck('sku')->all();
        $this->assertContains('FFP-VALVE', $valveSkus);
        $this->assertContains('FFP-DUST', $valveSkus);
        $this->assertNotContains('FFP-PLAIN', $valveSkus);

        $useSkus = collect(
            $this->getJson(
                '/api/products/cross-ref?'.http_build_query([
                    'code' => 'FFP-SEED',
                    'must' => ['use:v:praca-w-pyle'],
                ])
            )->assertOk()->json('matches')
        )->pluck('sku')->all();
        $this->assertContains('FFP-DUST', $useSkus);
        $this->assertNotContains('FFP-PLAIN', $useSkus);
        $this->assertNotContains('FFP-VALVE', $useSkus);
    }
}
