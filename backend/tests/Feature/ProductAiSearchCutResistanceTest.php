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
 * Golden rekawice-antyprzeciecowe-nitryl: włókno cięte na nazwie, nie przymiotnik SIWZ.
 */
final class ProductAiSearchCutResistanceTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Rękawice antyprzecięciowe powlekane nitrylem do prac montażowych';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_retrieve_keeps_xtremcut_when_name_lacks_antyprzecieciowe(): void
    {
        $this->seedCutCatalog();
        $search = $this->app->make(ProductAiSearchService::class);
        $retrieve = new \ReflectionMethod($search, 'retrieveCandidates');
        $retrieve->setAccessible(true);
        $normalize = new \ReflectionMethod($search, 'normalizeIntent');
        $normalize->setAccessible(true);
        $intent = $normalize->invoke($search, [
            'needed' => self::QUERY,
            'search_phrases' => ['rękawice antyprzecięciowe', 'powlekane nitrylem'],
            'search_steps' => ['rękawice antyprzecięciowe powlekane nitrylem'],
        ]);

        $skus = $retrieve->invoke($search, self::QUERY, $intent, 80)->pluck('sku')->all();

        $this->assertContains('VENICUTF02 XTREM CUT TOUCH - VECUTF02GR', $skus);
        $this->assertContains('EOS NOCUT VV910', $skus);
        $this->assertNotContains('NITRYL-MONT-0', $skus);
    }

    private function seedCutCatalog(): void
    {
        $base = [
            'category' => 'Rękawice ochronne',
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ];
        Product::query()->create($base + [
            'sku' => 'VENICUTF02 XTREM CUT TOUCH - VECUTF02GR',
            'name' => 'RĘKAWICE DZIANE Z WŁÓKNA XTREMCUT, DŁON POWLEKANA PIANKĄ NITRYLOWĄ',
            'manufacturer' => 'Venitex',
            'description' => 'Karta z błędnym opisem kriogenicznym.',
            'catalog_price_net' => 40,
            'purchase_price' => 20,
        ]);
        Product::query()->create($base + [
            'sku' => 'EOS NOCUT VV910',
            'name' => 'Rękawice antyprzecięciowe EOS NOCUT powlekane nitrylem',
            'manufacturer' => 'EOS',
            'description' => 'Rękawice antyprzecięciowe nitryl.',
            'catalog_price_net' => 35,
            'purchase_price' => 18,
        ]);
        for ($i = 0; $i < 40; $i++) {
            Product::query()->create($base + [
                'sku' => 'NITRYL-MONT-'.$i,
                'name' => 'Rękawice dziane, dłoń powlekana pianką nitrylową '.$i,
                'manufacturer' => 'Generic',
                'description' => 'Rękawice montażowe nitrylowe.',
                'catalog_price_net' => 12,
                'purchase_price' => 6,
                'enriched_at' => now(),
            ]);
        }
    }
}
