<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Golden case urgent-ogrodniczki-urg-a: karta bez „spodnie” w nazwie musi wejść do puli.
 */
final class ProductAiSearchUrgATest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Spodnie ogrodniczki robocze URGENT URG-A';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_retrieve_keeps_urg_a_among_other_bibs(): void
    {
        $hit = $this->seedUrgAAmongDecoys();
        $search = $this->app->make(ProductAiSearchService::class);
        $retrieve = new \ReflectionMethod($search, 'retrieveCandidates');
        $retrieve->setAccessible(true);
        $normalize = new \ReflectionMethod($search, 'normalizeIntent');
        $normalize->setAccessible(true);
        $intent = $normalize->invoke($search, [
            'needed' => self::QUERY,
            'search_phrases' => ['spodnie ogrodniczki', 'urgent urg-a'],
            'search_steps' => ['spodnie ogrodniczki'],
            'constraints' => [],
            'manufacturer' => 'URGENT',
            'model_name' => 'URG-A',
        ]);

        $skus = $retrieve->invoke($search, self::QUERY, $intent, 80)->pluck('sku')->all();

        $this->assertContains('PROS-URG-A-OGROD', $skus);
        $this->assertContains($hit->id, Product::query()->whereIn('sku', $skus)->pluck('id')->all());
    }

    public function test_named_model_path_returns_urg_a_without_llm_pick(): void
    {
        $this->seedUrgAAmongDecoys();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['spodnie ogrodniczki'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $search = $this->app->make(ProductAiSearchService::class);
        $result = $search->search(self::QUERY, 10);
        $skus = array_column($result['products'] ?? [], 'sku');
        $hitId = (int) Product::query()->where('sku', 'PROS-URG-A-OGROD')->value('id');

        $this->assertContains('PROS-URG-A-OGROD', $skus);
        $this->assertSame('PROS-URG-A-OGROD', $skus[0] ?? null);
        $this->assertContains($hitId, $search->lastTrace()['candidate_ids'] ?? []);
    }

    private function seedUrgAAmongDecoys(): Product
    {
        $hit = Product::query()->create([
            'sku' => 'PROS-URG-A-OGROD',
            'name' => 'URG-A (ogrodniczki)',
            'manufacturer' => 'URGENT',
            'category' => 'Odzież robocza',
            'description' => 'Ogrodniczki robocze URG-A.',
            'catalog_price_net' => 80,
            'purchase_price' => 40,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
        Product::query()->create([
            'sku' => 'PROS-URG-A-SPODNIE',
            'name' => 'URG-A (spodnie)',
            'manufacturer' => 'URGENT',
            'category' => 'Odzież robocza',
            'description' => 'Spodnie robocze URG-A.',
            'catalog_price_net' => 50,
            'purchase_price' => 10,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subMonths(3),
        ]);
        Product::query()->create([
            'sku' => 'PROS-URG-B-OGROD',
            'name' => 'URG-B (ogrodniczki)',
            'manufacturer' => 'URGENT',
            'category' => 'Odzież robocza',
            'description' => 'Ogrodniczki robocze URG-B.',
            'catalog_price_net' => 80,
            'purchase_price' => 40,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subMonths(6),
        ]);
        for ($i = 0; $i < 40; $i++) {
            Product::query()->create([
                'sku' => 'MACH-OGROD-'.$i,
                'name' => 'Spodnie ogrodniczki MACH '.$i,
                'manufacturer' => 'Delta Plus',
                'category' => 'Odzież robocza',
                'description' => 'Spodnie ogrodniczki robocze.',
                'catalog_price_net' => 50,
                'purchase_price' => 25,
                'stock' => 2,
                'ppe_family' => PpeAssortment::FAMILY_APPAREL,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        return $hit;
    }
}
