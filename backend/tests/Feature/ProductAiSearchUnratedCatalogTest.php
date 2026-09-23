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
use Tests\Support\FakeSearchLlm;
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

    /**
     * Przetarg 1, poz. 3: reguła klasy znalazła sandały ARSO 701 S1 P ESD, ale dawała płaskie 92
     * bez modelu — przetarg takim wierszom nie ufa i zapisał „Brak produktu”. Karty spełniające
     * klasę mają trafić do rankingu modelu, a wynik mieć źródło modelu.
     */
    public function test_footwear_class_cards_are_ranked_by_model(): void
    {
        $base = [
            'category' => 'Obuwie',
            'stock' => 4,
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
            'catalog_price_net' => 200,
            'purchase_price' => 150,
            'manufacturer' => 'ARTRA',
        ];
        $sandal = Product::query()->create($base + [
            'sku' => 'ARSO 701 616560 S1 P ESD',
            'name' => 'ARSO 701 616560 S1 P ESD',
            'description' => 'Sandały bezpieczne ARSO 701 S1 P ESD z zabudowaną piętą i podnoskiem, właściwości antyelektrostatyczne ESD.',
            'norms' => 'EN ISO 20345 S1 P, EN 61340-4-3 ESD',
        ]);
        $sandalId = (int) $sandal->id;
        $rankedIds = [];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $answer = static function (array $messages) use ($sandalId, &$rankedIds): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                return ['matches' => []];
            }
            if (str_contains((string) ($messages[1]['content'] ?? ''), '"id":'.$sandalId)) {
                $rankedIds[] = $sandalId;
            }

            return ['matches' => [['id' => $sandalId, 'score' => 93, 'reason' => 'Sandał S1 P z ESD', 'missing_key' => []]]];
        };
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $sets): array => array_map($answer, $sets)
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $json = $this->postJson('/api/products/ai-search', [
            'query' => 'Sandały ochronne kategorii S1 P ESD z zabudowaną piętą',
            'limit' => 10,
        ])->assertOk()->json();

        $this->assertContains($sandalId, $rankedIds, 'karta spełniająca klasę trafia do rankingu modelu');
        $this->assertSame('ARSO 701 616560 S1 P ESD', $json['products'][0]['sku'] ?? null);
        $this->assertSame(93, (int) ($json['products'][0]['ai_match_percent'] ?? 0));
        $this->assertNotSame(ProductAiSearchService::MATCH_SOURCE_RULE, $json['products'][0]['ai_match_source'] ?? null);
    }

    /** Poz. 8: model nazwał brak węgla aktywnego kluczowym warunkiem, a dał 70 — kod obcina do 50. */
    public function test_missing_key_condition_caps_model_score_at_fifty(): void
    {
        $glove = Product::query()->create([
            'sku' => 'RNITZ-M',
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'description' => 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, do prac montażowych.',
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $gloveId = (int) $glove->id;
        $answer = static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => [['id' => $gloveId, 'score' => 85, 'reason' => 'Rękawice nitrylowe', 'missing_key' => ['EN 407 ciepło kontaktowe']]]]
            : ['matches' => []];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $json = $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice robocze nitrylowe ze ściągaczem do prac przy gorących elementach, EN 407',
            'limit' => 10,
        ])->assertOk()->json();

        $row = collect($json['products'])->firstWhere('sku', 'RNITZ-M');
        $this->assertNotNull($row, 'karta z oceną modelu jest w wynikach');
        $this->assertSame(50, (int) $row['ai_match_percent']);
        $this->assertStringContainsString('EN 407 ciepło kontaktowe', (string) $row['ai_match_reason']);
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

    /**
     * 23.09.2026: „Zestaw plastrów plastikowych CEDERROTH 6036” — model ocenił plaster na 95, ale bramka
     * nazwanego modelu go odrzuciła (marka w producencie, kod w SKU), a lista zapasowa dołożyła rękawicę
     * Ansell „A6036” jako „ten sam rodzaj w katalogu”.
     */
    public function test_brand_and_code_query_finds_card_by_manufacturer_and_sku_without_foreign_brand(): void
    {
        $base = [
            'catalog_price_net' => 20,
            'purchase_price' => 14,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ];
        Product::query()->create($base + [
            'sku' => '6036',
            'name' => 'Plastry plastikowe Cederroth Salvequick, 45 szt.',
            'manufacturer' => 'CEDERROTH',
            'category' => 'Pierwsza pomoc',
            'description' => 'Plastry plastikowe Salvequick w wymiennym wkładzie, 45 sztuk.',
        ]);
        Product::query()->create($base + [
            'sku' => 'A6036',
            'name' => 'Rękawice termoizolacyjne CRUSADER FLEX 42-474',
            'manufacturer' => 'ANSELL HEALTHCARE EUROPE N.V.',
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'description' => 'Rękawice termoizolacyjne do 180°C.',
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $json = $this->postJson('/api/products/ai-search', [
            'query' => 'Zestaw plastrów plastikowych CEDERROTH 6036',
            'limit' => 10,
        ])->assertOk()->json();

        $skus = array_column($json['products'], 'sku');
        $this->assertSame('6036', $skus[0] ?? null);
        $this->assertNotContains('A6036', $skus);
        $this->assertGreaterThanOrEqual(80, (int) $json['products'][0]['ai_match_percent']);
        $this->assertNotSame(ProductAiSearchService::MATCH_SOURCE_CATALOG, $json['products'][0]['ai_match_source'] ?? null);
    }

    /** Lista zapasowa („ten sam rodzaj w katalogu”) nie dokłada kart innej marki niż nazwana w zapytaniu. */
    public function test_catalog_fallback_skips_cards_of_other_brand_than_requested(): void
    {
        $base = [
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'catalog_price_net' => 20,
            'purchase_price' => 14,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ];
        $ansell = Product::query()->create($base + [
            'sku' => 'A6036',
            'name' => 'Rękawice termoizolacyjne CRUSADER FLEX 42-474',
            'manufacturer' => 'ANSELL HEALTHCARE EUROPE N.V.',
            'description' => 'Rękawice termoizolacyjne do 180°C.',
        ]);
        $mapa = Product::query()->create($base + [
            'sku' => 'M-TEMP',
            'name' => 'Rękawice termoizolacyjne MAPA',
            'manufacturer' => 'MAPA',
            'description' => 'Rękawice termoizolacyjne.',
        ]);
        $service = app(ProductAiSearchService::class);

        $rows = (new \ReflectionMethod($service, 'rowsFromGenericCatalog'))->invoke(
            $service,
            'Rękawice termoizolacyjne MAPA',
            collect([$ansell, $mapa]),
            10,
            ['needed' => 'rękawice termoizolacyjne'],
        );

        $this->assertSame(['M-TEMP'], array_column($rows, 'sku'));
    }

    /** Recenzja dopasowania (13.09), błąd B: trafienie modelu bez oceny z polem sku/name dostawało 70 — zmyślona pewność. */
    public function test_model_match_without_score_gets_no_made_up_percent(): void
    {
        $card = Product::query()->create([
            'sku' => 'RNITZ-M',
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'description' => 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, do prac montażowych.',
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $service = app(ProductAiSearchService::class);

        $rows = (new \ReflectionMethod($service, 'rowsFromLlmMatches'))->invoke(
            $service,
            'Rękawice nitrylowe ze ściągaczem',
            collect([$card]),
            ['matches' => [['id' => (int) $card->id, 'sku' => (string) $card->sku, 'name' => (string) $card->name]]],
            10,
            'rękawice nitrylowe',
            null,
        );

        $this->assertSame([], $rows, 'bez oceny modelu nie ma procentu modelu');
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
