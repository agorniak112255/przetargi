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
 * Golden case peltor-x2-naglowne: marka+model musi wejść do puli retrievalu.
 */
final class ProductAiSearchPeltorX2Test extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_retrieve_keeps_x2a_eu_when_cascade_fills_nauszniki(): void
    {
        $hit = $this->seedPeltorX2AmongDecoys();
        $search = $this->app->make(ProductAiSearchService::class);
        $retrieve = new \ReflectionMethod($search, 'retrieveCandidates');
        $retrieve->setAccessible(true);
        $normalize = new \ReflectionMethod($search, 'normalizeIntent');
        $normalize->setAccessible(true);
        $intent = $normalize->invoke($search, [
            'needed' => self::QUERY,
            'search_phrases' => ['nauszniki przeciwhałasowe', 'peltor x2'],
            'constraints' => ['nagłowna'],
            'manufacturer' => '3M',
            'model_name' => 'Peltor X2',
        ]);

        $candidates = $retrieve->invoke($search, self::QUERY, $intent, 80);
        $skus = $candidates->pluck('sku')->all();

        $this->assertContains('X2A-EU', $skus);
        $this->assertContains($hit->id, $candidates->pluck('id')->all());
    }

    public function test_named_model_path_returns_x2a_eu_without_llm_pick(): void
    {
        $this->seedPeltorX2AmongDecoys();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['nauszniki przeciwhałasowe'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $search = $this->app->make(ProductAiSearchService::class);
        $result = $search->search(self::QUERY, 10);
        $skus = array_column($result['products'] ?? [], 'sku');
        $hitId = (int) Product::query()->where('sku', 'X2A-EU')->value('id');

        $this->assertContains('X2A-EU', $skus);
        $this->assertContains($hitId, $search->lastTrace()['candidate_ids'] ?? []);
    }

    private function seedPeltorX2AmongDecoys(): Product
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
        for ($i = 0; $i < 80; $i++) {
            Product::query()->create([
                'sku' => 'NAUSZ-'.$i,
                'name' => '3M Nauszniki przeciwhałasowe Optime '.$i,
                'manufacturer' => '3M',
                'category' => 'Ochrona słuchu / Nauszniki przeciwhałasowe',
                'description' => 'Nauszniki przeciwhałasowe.',
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
