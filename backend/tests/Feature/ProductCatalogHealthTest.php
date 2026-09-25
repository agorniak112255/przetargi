<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\OfferPricing;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertJsonPath('offer_markup_percent', 18)
            ->assertJsonPath('vector.enabled', false)
            ->assertJsonPath('vector.indexed', 0);

        $this->assertSame(9.44, OfferPricing::fromPurchase(8.0));
    }

    public function test_report_counts_missing_attributes_by_payload(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        // Raport czyta enrichment_payload z wierszy bez modeli — wynik ma być taki jak z rzutowania 'array'.
        // Tekst zamiast tablicy trafia do bazy z pominięciem rzutowania (pusty tekst, uszkodzony JSON).
        $cards = [
            'NULL' => [null, false],
            'EMPTY-TEXT' => ['', false],
            'BROKEN-JSON' => ['{"attributes": {"material": "nitryl"', false],
            'NO-ATTR' => [['sources' => ['https://example.com/karta']], false],
            'ATTR-TEXT' => [['attributes' => 'rękawice nitrylowe'], false],
            'EMPTY-NORMS' => [['attributes' => ['material' => null, 'kategoria_bhp' => null, 'normy_en' => []]], false],
            'NORMS' => [['attributes' => ['normy_en' => ['EN 388']]], true],
            'MATERIAL' => [['attributes' => ['material' => 'nitryl', 'kategoria_bhp' => null]], true],
            'CATEGORY' => [['attributes' => ['kategoria_bhp' => 'rekawice']], true],
        ];
        $missing = [];
        foreach ($cards as $sku => [$payload, $useful]) {
            $product = Product::query()->create([
                'sku' => $sku,
                'name' => 'Karta '.$sku,
                'manufacturer' => 'ATG',
                'description' => 'Rękawica ochronna z powłoką nitrylową, EN 388.',
                'catalog_price_net' => 10,
                'purchase_price' => 8,
                'stock' => 1,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enrichment_payload' => is_string($payload) ? null : $payload,
            ]);
            if (is_string($payload)) {
                DB::table('products')->where('id', $product->id)->update(['enrichment_payload' => $payload]);
            }
            if (! $useful) {
                $missing[] = $product->id;
            }
        }
        // pozycja do ręcznego opisu nie jest ani „bez opisu”, ani „nie wzbogacona” — atrybuty liczą się jak u innych
        $manual = Product::query()->create([
            'sku' => 'MANUAL',
            'name' => 'Do ręcznego opisu',
            'manufacturer' => 'CANIS',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_MANUAL,
        ]);
        $missing[] = $manual->id;

        $this->getJson('/api/products/catalog-health')
            ->assertOk()
            ->assertJsonPath('total', 10)
            ->assertJsonPath('missing_attributes', 7)
            ->assertJsonPath('sample_ids.missing_attributes', $missing)
            ->assertJsonPath('missing_description', 0)
            ->assertJsonPath('not_enriched', 0)
            ->assertJsonPath('manual_review', 1)
            ->assertJsonPath('with_description', 9);

        $this->getJson('/api/products/catalog-health?manufacturer=ATG')
            ->assertOk()
            ->assertJsonPath('total', 9)
            ->assertJsonPath('missing_attributes', 6)
            ->assertJsonPath('sample_ids.missing_attributes', array_slice($missing, 0, 6));
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

    public function test_backfill_sizes_from_stored_descriptions(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $buty = Product::query()->create([
            'sku' => 'AROSIO',
            'name' => 'Artra AROSIO Air S1P',
            'manufacturer' => 'Artra',
            'description' => 'Rozmiary unisex od 36 do 48. Tabela producenta.',
            'packaging' => null,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
        ]);
        $rekawice = Product::query()->create([
            'sku' => 'NIT-1',
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'ATG',
            'description' => 'Dostępne rozmiary: 7, 8, 9, 10, 11',
            'packaging' => null,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
        ]);
        $spodnie = Product::query()->create([
            'sku' => 'SP-46',
            'name' => 'Spodnie robocze',
            'manufacturer' => 'Canis',
            'description' => 'Rozmiary spodni: 46-62',
            'packaging' => null,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
        ]);
        $zLista = Product::query()->create([
            'sku' => 'KEEP',
            'name' => 'Już ma listę',
            'manufacturer' => 'ATG',
            'description' => 'Rozmiary unisex od 36 do 48.',
            'packaging' => '7, 8, 9, 10, 11',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
        ]);
        $bezZakresu = Product::query()->create([
            'sku' => 'NONE',
            'name' => 'Bez rozmiaru',
            'manufacturer' => 'ATG',
            'description' => 'EN ISO 20345:2011 S1 P SRC. ESD wg EN IEC 61340-4-3:2018.',
            'packaging' => null,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
        ]);

        $this->getJson('/api/products/catalog-health')
            ->assertOk()
            ->assertJsonPath('empty_packaging', 4);

        $this->postJson('/api/products/catalog-health/backfill-sizes')
            ->assertOk()
            ->assertJsonPath('updated', 4)
            ->assertJsonPath('scanned', 5);

        $this->assertSame('36-48', $buty->refresh()->packaging);
        $this->assertSame('36-48', $buty->enrichment_payload['attributes']['rozmiar'] ?? null);
        $this->assertSame('7-11', $rekawice->refresh()->packaging);
        $this->assertSame('46-62', $spodnie->refresh()->packaging);
        $this->assertSame('7, 8, 9, 10, 11', $zLista->refresh()->packaging);
        $this->assertNull($bezZakresu->refresh()->packaging);
    }

    public function test_vector_progress_endpoint_counts_indexed_products(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'V1',
            'name' => 'Zaindeksowany',
            'manufacturer' => 'ATG',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'embedding_synced_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'V2',
            'name' => 'Bez wektora',
            'manufacturer' => 'ATG',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
        ]);

        $this->getJson('/api/products/catalog-health/vector')
            ->assertOk()
            ->assertExactJson([
                'enabled' => false,
                'indexed' => 1,
                'pending_jobs' => 0,
            ]);
    }

    public function test_vector_progress_endpoint_respects_manufacturer_filter(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'V3',
            'name' => 'Zaindeksowany ATG',
            'manufacturer' => 'ATG',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'embedding_synced_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'V4',
            'name' => 'Zaindeksowany CANIS',
            'manufacturer' => 'CANIS',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 1,
            'embedding_synced_at' => now(),
        ]);

        $this->getJson('/api/products/catalog-health/vector?manufacturer=CANIS')
            ->assertOk()
            ->assertJsonPath('indexed', 1);
    }
}
