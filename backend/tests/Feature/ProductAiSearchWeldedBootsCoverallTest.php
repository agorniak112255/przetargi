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
 * Golden kombinezon-wodoochronny-wgrzane-kalosze: wgrzane na nazwie, nie tańszy kombinezon bez kaloszy.
 */
final class ProductAiSearchWeldedBootsCoverallTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Kombinezon wodoochronny z wgrzanymi kaloszami';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_search_ranks_welded_boots_above_plain_coverall_without_llm(): void
    {
        $this->seedCoverallCatalog();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['kombinezon wodoochronny'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $skus = array_column(
            $this->app->make(ProductAiSearchService::class)->search(self::QUERY, 10)['products'] ?? [],
            'sku'
        );

        $this->assertSame(['104/K', '304/K', '0404'], $skus);
        $this->assertNotContains('0403', $skus);
        $this->assertNotContains('SB01', $skus);
        $this->assertNotContains('103', $skus);
    }

    /** Kalosze na nazwie karty to warunek konieczny: karty idą do rankingu modelu, a wiersze reguły są zapasem `rule`. */
    public function test_welded_boots_cards_are_ranked_by_model(): void
    {
        $this->seedCoverallCatalog();
        $cardId = (int) Product::query()->where('sku', '304/K')->value('id');
        $ranked = false;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $answer = static function (array $messages) use ($cardId, &$ranked): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                return ['needed' => self::QUERY, 'search_phrases' => ['kombinezon wodoochronny'], 'matches' => []];
            }
            $ranked = $ranked || str_contains((string) ($messages[1]['content'] ?? ''), '"id":'.$cardId);

            return ['matches' => [['id' => $cardId, 'score' => 87, 'reason' => 'Kombinezon z wgrzanymi kaloszami', 'missing_key' => []]]];
        };
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $sets): array => array_map($answer, $sets)
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $products = $this->app->make(ProductAiSearchService::class)->search(self::QUERY, 10)['products'] ?? [];
        $row = collect($products)->firstWhere('sku', '304/K');

        $this->assertTrue($ranked, 'karta z kaloszami trafia do rankingu modelu');
        $this->assertSame(87, (int) ($row['ai_match_percent'] ?? 0));
        $this->assertNotSame(ProductAiSearchService::MATCH_SOURCE_RULE, $row['ai_match_source'] ?? null);
    }

    public function test_welded_boots_rule_rows_are_marked_as_rule_when_model_rates_nothing(): void
    {
        $this->seedCoverallCatalog();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['kombinezon wodoochronny'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $products = $this->app->make(ProductAiSearchService::class)->search(self::QUERY, 10)['products'] ?? [];

        $this->assertSame(['104/K', '304/K', '0404'], array_column($products, 'sku'));
        foreach ($products as $row) {
            $this->assertSame(ProductAiSearchService::MATCH_SOURCE_RULE, $row['ai_match_source'] ?? null, (string) $row['sku']);
        }
    }

    /** Recenzja dopasowania (13.09), błąd E: model nic nie ocenił, a wynik z samych wierszy reguły miał stan „ranked”. */
    public function test_rule_rows_alone_do_not_report_model_as_ranked(): void
    {
        $this->seedCoverallCatalog();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $sets): array => array_fill(0, count($sets), ['matches' => []])
        );
        $llm->shouldReceive('chatJson')->andReturn(['matches' => []]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $result = $this->app->make(ProductAiSearchService::class)->searchMany([self::QUERY], 10)[0] ?? [];

        $this->assertNotSame([], $result['products'] ?? [], 'zapas reguły zostaje w wyniku');
        $this->assertSame('empty', $result['model_state'] ?? null, 'wiersze reguły to nie ocena modelu');
    }

    private function seedCoverallCatalog(): void
    {
        $base = [
            'category' => 'Odzież wodoochronna',
            'stock' => 4,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
            'currency' => 'PLN',
        ];
        Product::query()->create($base + [
            'sku' => '104/K',
            'name' => 'Kombinezon wodoochronny z wgrzanymi kaloszami',
            'manufacturer' => 'AJ Group',
            'description' => 'Kombinezon zintegrowany z kaloszami.',
            'catalog_price_net' => 400,
            'purchase_price' => 319.20,
        ]);
        Product::query()->create($base + [
            'sku' => '304/K',
            'name' => 'Kombinezon wodoochronny z wgrzanymi kaloszami typ S5',
            'manufacturer' => 'AJ Group',
            'description' => 'Kombinezon z kaloszami S5.',
            'catalog_price_net' => 500,
            'purchase_price' => 407.20,
        ]);
        Product::query()->create($base + [
            'sku' => '0404',
            'name' => 'Kombinezon Wodoochronny z Kaloszami',
            'manufacturer' => 'MAT',
            'description' => 'Kombinezon z kaloszami.',
            'catalog_price_net' => 300,
            'purchase_price' => 251.10,
        ]);
        Product::query()->create($base + [
            'sku' => '0403',
            'name' => 'Kombinezon Wodoochronny',
            'manufacturer' => 'MAT',
            'description' => 'Kombinezon wodoochronny bez kaloszy.',
            'catalog_price_net' => 220,
            'purchase_price' => 178.20,
        ]);
        Product::query()->create($base + [
            'sku' => 'SB01',
            'name' => 'Spodniobuty Standard',
            'manufacturer' => 'PROS',
            'description' => 'Spodniobuty z wgrzanymi kaloszami.',
            'catalog_price_net' => 180,
            'purchase_price' => 151.20,
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
        ]);
        Product::query()->create($base + [
            'sku' => '103',
            'name' => '103 - Kurtka wodoochronna zapinana na zamek',
            'manufacturer' => 'PROS',
            'description' => 'Kurtka wodoochronna.',
            'catalog_price_net' => 150,
            'purchase_price' => 90,
        ]);
    }
}
