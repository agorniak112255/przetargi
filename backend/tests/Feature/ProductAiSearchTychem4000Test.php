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
 * Golden case tychem-4000s-bialy: linia+numer w nazwie, SKU magazynowy, producent inny.
 */
final class ProductAiSearchTychem4000Test extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Kombinezon chemoodporny Tychem 4000 S biały';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_retrieve_keeps_tychem_4000_among_coveralls_and_generic_4000(): void
    {
        $hit = $this->seedTychemAmongDecoys();
        $search = $this->app->make(ProductAiSearchService::class);
        $retrieve = new \ReflectionMethod($search, 'retrieveCandidates');
        $retrieve->setAccessible(true);
        $normalize = new \ReflectionMethod($search, 'normalizeIntent');
        $normalize->setAccessible(true);
        $intent = $normalize->invoke($search, [
            'needed' => self::QUERY,
            'search_phrases' => ['kombinezon chemoodporny', 'tychem 4000'],
            'search_steps' => ['kombinezon chemoodporny'],
            'constraints' => ['biały'],
            'manufacturer' => 'Tychem',
            'model_name' => 'Tychem 4000 S',
        ]);

        $skus = $retrieve->invoke($search, self::QUERY, $intent, 80)->pluck('sku')->all();

        $this->assertContains('SL CHZ5 T WH 00', $skus);
        $this->assertContains('SL CHZ6 T WH 16', $skus);
        $this->assertContains($hit->id, Product::query()->whereIn('sku', $skus)->pluck('id')->all());
    }

    public function test_named_model_path_returns_white_4000_not_yellow_c(): void
    {
        $this->seedTychemAmongDecoys();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['kombinezon chemoodporny'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $search = $this->app->make(ProductAiSearchService::class);
        $result = $search->search(self::QUERY, 10);
        $skus = array_column($result['products'] ?? [], 'sku');
        $hitId = (int) Product::query()->where('sku', 'SL CHZ5 T WH 00')->value('id');

        $this->assertContains('SL CHZ5 T WH 00', $skus);
        $this->assertContains('SL CHZ6 T WH 16', $skus);
        $this->assertNotContains('TC CHA5 T YL 00', $skus);
        $this->assertContains($hitId, $search->lastTrace()['candidate_ids'] ?? []);
    }

    private function seedTychemAmongDecoys(): Product
    {
        $hit = Product::query()->create([
            'sku' => 'SL CHZ5 T WH 00',
            'name' => 'TYCHEM® 4000 S - white.',
            'manufacturer' => 'DuPont',
            'category' => 'Odzież chemoodporna / Kombinezony',
            'description' => 'Kombinezon chemoodporny Tychem 4000 S.',
            'catalog_price_net' => 200,
            'purchase_price' => 120,
            'stock' => 3,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);
        Product::query()->create([
            'sku' => 'SL CHZ6 T WH 16',
            'name' => 'TYCHEM® 4000 S - white with socks.',
            'manufacturer' => 'DuPont',
            'category' => 'Odzież chemoodporna / Kombinezony',
            'description' => 'Kombinezon chemoodporny Tychem 4000 S ze skarpetami.',
            'catalog_price_net' => 230,
            'purchase_price' => 140,
            'stock' => 2,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subMonths(8),
        ]);
        Product::query()->create([
            'sku' => 'TC CHA5 T YL 00',
            'name' => 'TYCHEM C - yellow.',
            'manufacturer' => 'DuPont',
            'category' => 'Odzież chemoodporna / Kombinezony',
            'description' => 'Kombinezon chemoodporny Tychem C.',
            'catalog_price_net' => 90,
            'purchase_price' => 50,
            'stock' => 6,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subMonths(4),
        ]);
        for ($i = 0; $i < 80; $i++) {
            Product::query()->create([
                'sku' => 'GR40-T-00-181-'.$i,
                'name' => 'Kombinezon chemoodporny GR40 '.$i,
                'manufacturer' => 'Delta Plus',
                'category' => 'Odzież chemoodporna / Kombinezony',
                'description' => 'Kombinezon chemoodporny.',
                'catalog_price_net' => 40,
                'purchase_price' => 20,
                'stock' => 1,
                'ppe_family' => PpeAssortment::FAMILY_APPAREL,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }
        for ($i = 0; $i < 80; $i++) {
            Product::query()->create([
                'sku' => 'FILTR-4000-'.$i,
                'name' => 'Filtr cząstek 4000 P3 '.$i,
                'manufacturer' => '3M',
                'category' => 'Ochrona dróg oddechowych / Filtry',
                'description' => 'Filtr 4000.',
                'catalog_price_net' => 15,
                'purchase_price' => 8,
                'stock' => 10,
                'ppe_family' => PpeAssortment::FAMILY_RESPIRATORY,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }

        return $hit;
    }
}
