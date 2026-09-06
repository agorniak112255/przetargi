<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Golden case nauszniki-snr-30: próg SNR z SIWZ, bez marki i modelu.
 */
final class ProductAiSearchSnrTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Ochronniki słuchu nagłowne o tłumieniu SNR minimum 30 dB';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_retrieve_keeps_snr_31_when_cascade_locks_on_naglowne(): void
    {
        $hit = $this->seedSnrCatalog();
        $search = $this->app->make(ProductAiSearchService::class);
        $retrieve = new \ReflectionMethod($search, 'retrieveCandidates');
        $retrieve->setAccessible(true);
        $normalize = new \ReflectionMethod($search, 'normalizeIntent');
        $normalize->setAccessible(true);
        $intent = $normalize->invoke($search, [
            'needed' => self::QUERY,
            'search_phrases' => ['ochronniki słuchu nagłowne', 'SNR 30'],
            'search_steps' => ['ochronniki słuchu nagłowne'],
            'constraints' => ['SNR minimum 30 dB'],
        ]);

        $skus = $retrieve->invoke($search, self::QUERY, $intent, 80)->pluck('sku')->all();

        $this->assertContains('X2A-EU', $skus);
        $this->assertNotContains('X1A-EU', $skus);
        $this->assertContains($hit->id, Product::query()->whereIn('sku', $skus)->pluck('id')->all());
    }

    private function seedSnrCatalog(): Product
    {
        $hit = Product::query()->create([
            'sku' => 'X2A-EU',
            'name' => '3M™ Nauszniki przeciwhałasowe PELTOR™ X2 - wersja nagłowna (SNR 31 dB)',
            'manufacturer' => '3M',
            'category' => 'Ochrona słuchu / Nauszniki przeciwhałasowe',
            'description' => 'Nauszniki nagłowne PELTOR X2.',
            'catalog_price_net' => 120,
            'purchase_price' => 70,
            'stock' => 4,
            'ppe_family' => PpeAssortment::FAMILY_HEARING,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
        Product::query()->create([
            'sku' => 'X1A-EU',
            'name' => '3M™ Nauszniki przeciwhałasowe PELTOR™ X1 - wersja nagłowna (SNR 27 dB)',
            'manufacturer' => '3M',
            'category' => 'Ochrona słuchu / Nauszniki przeciwhałasowe',
            'description' => 'Nauszniki nagłowne PELTOR X1.',
            'catalog_price_net' => 90,
            'purchase_price' => 50,
            'stock' => 4,
            'ppe_family' => PpeAssortment::FAMILY_HEARING,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subMonths(6),
        ]);
        for ($i = 0; $i < 90; $i++) {
            Product::query()->create([
                'sku' => 'CONIC-'.$i,
                'name' => 'Zatyczki przeciwhałasowe CONIC '.$i.' (SNR 40 dB)',
                'manufacturer' => 'Honeywell',
                'category' => 'Ochrona słuchu / Wkładki przeciwhałasowe',
                'description' => 'Wkładki douszne SNR 40 dB.',
                'catalog_price_net' => 8,
                'purchase_price' => 2,
                'stock' => 20,
                'ppe_family' => PpeAssortment::FAMILY_HEARING,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }
        for ($i = 0; $i < 40; $i++) {
            Product::query()->create([
                'sku' => 'NAGL-'.$i,
                'name' => 'Ochronniki słuchu nagłowne Optime '.$i,
                'manufacturer' => 'Honeywell',
                'category' => 'Ochrona słuchu / Nauszniki przeciwhałasowe',
                'description' => 'Ochronniki słuchu nagłowne.',
                'catalog_price_net' => 40,
                'purchase_price' => 20,
                'stock' => 1,
                'ppe_family' => PpeAssortment::FAMILY_HEARING,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        return $hit;
    }
}
