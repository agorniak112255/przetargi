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

    /**
     * 25.09.2026 (golden peltor-x2-naglowne): zestaw części HYX2 był 1. z 99%, X2P3 nahełmowe 3., a karta 3M z linią
     * i kodem zapisanymi osobno („PELTOR™ …, nagłowne, X2A”) nie była nazwanym modelem. Nazwy kart jak na produkcji.
     */
    public function test_named_model_keeps_headband_x2a_and_drops_kits_and_helmet_versions(): void
    {
        $base = [
            'manufacturer' => '3M',
            'category' => 'Ochrona słuchu / Nauszniki przeciwhałasowe',
            'catalog_price_net' => 100,
            'purchase_price' => 60,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subMonth(),
        ];
        $wanted = [
            '7000103989' => '3M™ PELTOR™ Nauszniki przeciwhałasowe, żółte, nagłowne, X2A',
            '7100141454' => 'Nauszniki Ochronne 3M™ PELTOR™ X2A',
        ];
        $forbidden = [
            '229070' => 'Zestaw części zamiennych 3M PELTOR HYX2 (dawnej HY52) do nauszników PELTOR X2-A, H31, H520 Optime II',
            '7100383166' => 'Zestaw do higienicznej wymiany nauszników 3M™ PELTOR™, Optime II, HYX2, X2',
            '7000103990' => '3M™ PELTOR™ Nauszniki przeciwhałasowe, żółte, nahełmowe, X2P3',
            '7100326939' => 'Nauszniki przeciwhałasowe mocowane do hełmu 3M™ PELTOR™, pomarańczowe, X2P3E',
            '7100095525' => 'Nauszniki 3M™ PELTOR™, 30 dB, żółte, mocowane na kasku, X2P5E',
            '7000103987' => '3M™ PELTOR™ Nauszniki przeciwhałasowe, żółte, nagłowne, X1A',
        ];
        foreach ($wanted + $forbidden as $sku => $name) {
            Product::query()->create($base + [
                'sku' => (string) $sku,
                'name' => $name,
                'description' => $name,
                'ppe_family' => PpeAssortment::FAMILY_HEARING,
            ]);
        }
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['nauszniki przeciwhałasowe'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $search = $this->app->make(ProductAiSearchService::class);
        $skus = array_map('strval', array_column($search->search(self::QUERY, 10)['products'] ?? [], 'sku'));

        foreach (array_keys($wanted) as $sku) {
            $this->assertContains((string) $sku, $skus);
        }
        foreach ($forbidden as $sku => $name) {
            $this->assertNotContains((string) $sku, $skus, $name);
        }
        // Nazwany model rozstrzyga bez rankingu modelu.
        $this->assertSame([], $search->lastTrace()['rank_card_ids'] ?? []);
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
