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
use Tests\Support\FakeSearchLlm;
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

    public function test_snr_path_returns_x2_in_top_10_without_llm_pick(): void
    {
        $this->seedSnrCatalog();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['ochronniki słuchu nagłowne'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $search = $this->app->make(ProductAiSearchService::class);
        $result = $search->search(self::QUERY, 10);
        $skus = array_column($result['products'] ?? [], 'sku');
        $top = array_slice($skus, 0, 10);

        $this->assertContains('X2A-EU', $top);
        $this->assertNotContains('X1A-EU', $skus);
        $this->assertContains(
            (int) Product::query()->where('sku', 'X2A-EU')->value('id'),
            $search->lastTrace()['candidate_ids'] ?? []
        );
    }

    /** Próg SNR na karcie to warunek konieczny: karty idą do rankingu modelu, ocena jest modelu, nie reguły 80–99. */
    public function test_snr_cards_are_ranked_by_model(): void
    {
        $this->seedSnrCatalog();
        $cardId = (int) Product::query()->where('sku', 'X2A-EU')->value('id');
        $ranked = false;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $answer = static function (array $messages) use ($cardId, &$ranked): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                return ['needed' => self::QUERY, 'search_phrases' => ['ochronniki słuchu nagłowne'], 'matches' => []];
            }
            $ranked = $ranked || str_contains((string) ($messages[1]['content'] ?? ''), '"id":'.$cardId);

            return ['matches' => [['id' => $cardId, 'score' => 88, 'reason' => 'Nauszniki nagłowne SNR 31 dB', 'missing_key' => []]]];
        };
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $sets): array => array_map($answer, $sets)
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $result = $this->app->make(ProductAiSearchService::class)->search(self::QUERY, 10);
        $row = collect($result['products'] ?? [])->firstWhere('sku', 'X2A-EU');

        $this->assertTrue($ranked, 'karta z progiem SNR trafia do rankingu modelu');
        $this->assertSame(88, (int) ($row['ai_match_percent'] ?? 0));
        $this->assertNotSame(ProductAiSearchService::MATCH_SOURCE_RULE, $row['ai_match_source'] ?? null);
    }

    /** Model nic nie ocenił — wiersze reguły SNR zostają zapasem ze źródłem `rule`, przetarg nie bierze ich za ocenę modelu. */
    public function test_snr_rule_rows_are_marked_as_rule_when_model_rates_nothing(): void
    {
        $this->seedSnrCatalog();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['ochronniki słuchu nagłowne'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $products = $this->app->make(ProductAiSearchService::class)->search(self::QUERY, 10)['products'] ?? [];

        $this->assertContains('X2A-EU', array_column($products, 'sku'));
        foreach ($products as $row) {
            $this->assertSame(ProductAiSearchService::MATCH_SOURCE_RULE, $row['ai_match_source'] ?? null, (string) $row['sku']);
        }
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
