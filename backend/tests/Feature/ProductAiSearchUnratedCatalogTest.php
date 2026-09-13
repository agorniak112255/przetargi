<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Wiersze, których nikt nie ocenił: lista zapasowa z katalogu (PLAN D4) i skróty
 * deterministyczne (PLAN D7) muszą być oznaczone tak, żeby dopasowanie przetargu
 * nie brało ich za werdykt modelu.
 */
final class ProductAiSearchUnratedCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_catalog_fallback_rows_stay_at_or_below_fifty_percent_with_unrated_reason(): void
    {
        Opisowy15Fixture::seed(['9310+', 'S56T0SM0']);
        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $json = $this->postJson('/api/products/ai-search', [
            'query' => Opisowy15Fixture::requirement(13),
            'limit' => 10,
        ])->assertOk()->json();

        $this->assertGreaterThanOrEqual(1, $json['total'], 'lista zapasowa powinna coś pokazać');
        foreach ($json['products'] as $row) {
            $label = (string) $row['sku'];
            // Model nic nie wskazał — procent to zgodność rodzaju, poniżej progu zapisu przetargu (65).
            $this->assertLessThanOrEqual(50, (int) $row['ai_match_percent'], $label);
            $this->assertGreaterThanOrEqual(40, (int) $row['ai_match_percent'], $label);
            $this->assertSame(ProductAiSearchService::MATCH_SOURCE_CATALOG, $row['ai_match_source'] ?? null, $label);
            $this->assertStringStartsWith(ProductAiSearchService::UNRATED_CATALOG_REASON, (string) $row['ai_match_reason'], $label);
        }
    }

    public function test_footwear_class_shortcut_rows_are_marked_as_rule_not_model(): void
    {
        $base = [
            'category' => 'Obuwie ochronne / Trzewiki',
            'stock' => 4,
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ];
        Product::query()->create($base + [
            'sku' => 'MANHATTAN S3 SRC',
            'name' => 'TRZEWIKI MANHATTAN S3 SRC z podnoskiem',
            'manufacturer' => 'Cerva',
            'description' => 'Trzewiki S3 SRC.',
            'catalog_price_net' => 400,
            'purchase_price' => 320,
        ]);
        Product::query()->create($base + [
            'sku' => 'VIRAGE S1P SRC',
            'name' => 'TRZEWIKI VIRAGE S1P SRC',
            'manufacturer' => 'Cerva',
            'description' => 'Trzewiki S1P SRC.',
            'catalog_price_net' => 80,
            'purchase_price' => 40,
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $json = $this->postJson('/api/products/ai-search', [
            'query' => 'Trzewiki robocze w klasie ochrony S3 SRC z podnoskiem',
            'limit' => 10,
        ])->assertOk()->json();

        $skus = array_column($json['products'], 'sku');
        $this->assertContains('MANHATTAN S3 SRC', $skus);
        $this->assertNotContains('VIRAGE S1P SRC', $skus);
        foreach ($json['products'] as $row) {
            // Płaskie 92 z klasy na karcie to kolejność z puli, nie ocena — przetarg traktuje je jak `catalog`.
            $this->assertSame(ProductAiSearchService::MATCH_SOURCE_RULE, $row['ai_match_source'] ?? null, (string) $row['sku']);
        }
    }

    public function test_named_model_rows_keep_model_source(): void
    {
        // D7: nazwany model z SIWZ (PERSPECTA 010) to prawdziwe trafienie, nie skrót — bez `rule`.
        Product::query()->create([
            'sku' => '10045643',
            'name' => 'OKULARY OCHRONNE MSA PERSPECTA 010',
            'manufacturer' => 'MSA',
            'description' => 'Okulary ochronne Perspecta 010, bezbarwne.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_EYES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $json = $this->postJson('/api/products/ai-search', [
            'query' => 'OKULARY OCHRONNE MSA PERSPECTA 010',
            'limit' => 10,
        ])->assertOk()->json();

        $this->assertContains('10045643', array_column($json['products'], 'sku'));
        foreach ($json['products'] as $row) {
            $this->assertNotSame(ProductAiSearchService::MATCH_SOURCE_RULE, $row['ai_match_source'] ?? null, (string) $row['sku']);
            $this->assertNotSame(ProductAiSearchService::MATCH_SOURCE_CATALOG, $row['ai_match_source'] ?? null, (string) $row['sku']);
        }
    }

    private function emptyRankLlm(): OpenAiCompatibleClient
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(['matches' => []]);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $messages): array {
            return array_fill(0, count($messages), ['matches' => []]);
        });

        return $llm;
    }
}
