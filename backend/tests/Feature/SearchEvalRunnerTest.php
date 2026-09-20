<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\Search\SearchEvalRunner;
use App\Support\SearchEvalMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\FakeSearchLlm;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * SearchEvalRunner: wczytanie golden setu i metryki jednego przypadku na fixture „opisowy15”
 * ze stubem modelu. Retrieval w SQLite jest LIKE‑owy, więc test pilnuje kontraktu runnera
 * (co liczy i z czego), nie wartości recall z produkcji.
 */
final class SearchEvalRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const GOLDEN = 'resources/search-eval/golden.json';

    private const K = 10;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
    }

    public function test_golden_set_carries_the_fifteen_descriptive_cases_next_to_the_old_ones(): void
    {
        $cases = app(SearchEvalRunner::class)->loadCases(base_path(self::GOLDEN));
        $ids = array_column($cases, 'id');

        $this->assertSame($ids, array_values(array_unique($ids)), 'zdublowane id w golden.json');
        // Liczba przypadków nie jest miarą jakości pomiaru: 19 przypadków mierzyło SKU, których nie ma
        // w katalogu (marki spoza asortymentu), więc z definicji dawały zero i zaniżały każdą metrykę.
        // W ich miejsce weszły prawdziwe zapytania klientów z poczty. Zamiast progu liczbowego
        // pilnujemy tego, co naprawdę ma znaczenie: każdy przypadek musi cokolwiek mierzyć.
        $this->assertGreaterThanOrEqual(35, count($cases), 'golden.json ma nieść przypadki zakotwiczone, opisowe i z poczty');
        $this->assertContains('peltor-x2-naglowne', $ids, 'dotychczasowe przypadki mają zostać');
        foreach ($cases as $case) {
            $this->assertNotEmpty(
                [...($case['expected_skus'] ?? []), ...($case['forbidden_skus'] ?? [])],
                'przypadek '.$case['id'].' nie ma czego mierzyć — ani oczekiwanych, ani zakazanych SKU',
            );
        }

        $byLine = [];
        foreach ($cases as $case) {
            if (preg_match('/^opisowy15-(\d{2})-[a-z0-9-]+$/', $case['id'], $m) === 1) {
                $byLine[(int) $m[1]] = $case;
            }
        }
        $this->assertSame(range(1, 15), array_keys($byLine));

        foreach (Opisowy15Fixture::items() as $line) {
            $case = $byLine[(int) $line['line_no']];
            $this->assertSame($line['requirement'], $case['query']);
            // eval_extra_*: karty tylko dla pomiaru (AUDYT_4, poz. 3: ARSO to też sandały S1 P ESD,
            // AROSIO/ARDESIO to zakryte buty) — bez dopisywania ich do list bramki asortymentu.
            $this->assertSame(
                [$line['expected_sku'], ...array_values($line['eval_extra_expected_skus'] ?? [])],
                $case['expected_skus'],
            );
            $this->assertSame(
                [...array_values($line['forbidden_skus']), ...array_values($line['eval_extra_forbidden_skus'] ?? [])],
                $case['forbidden_skus'],
            );
            $this->assertSame(trim((string) $line['source_facts']), $case['note']);
            $this->assertSame(($line['eval_expect_empty'] ?? false) === true, $case['expect_empty'], "poz. {$line['line_no']}: tender_expect_empty w golden.json");
        }
    }

    public function test_load_cases_rejects_missing_file_and_case_without_expected_skus(): void
    {
        $runner = app(SearchEvalRunner::class);

        try {
            $runner->loadCases(base_path('resources/search-eval/nie-ma-takiego.json'));
            $this->fail('brak pliku ma być błędem');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Nie ma pliku', $e->getMessage());
        }

        $path = tempnam(sys_get_temp_dir(), 'golden');
        file_put_contents($path, json_encode(['cases' => [['query' => 'Rękawice', 'expected_skus' => []]]]));
        try {
            $runner->loadCases($path);
            $this->fail('przypadek bez expected_skus ma być błędem');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('expected_skus', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_evaluate_reports_retrieval_recall_unknown_skus_and_violations_without_model(): void
    {
        Opisowy15Fixture::seed();
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());
        $runner = app(SearchEvalRunner::class);
        $cases = [];
        foreach ($runner->loadCases(base_path(self::GOLDEN)) as $case) {
            $cases[$case['id']] = $case;
        }

        // Karta oczekiwana jest w bazie i wchodzi do puli kandydatów — etap 1 zaliczony.
        $apteczka = $runner->evaluate($cases['opisowy15-10-apteczka-scienna-mini'], self::K, ProductAiSearchService::CATALOG_LIMIT);
        $this->assertNull($apteczka['error']);
        $this->assertSame([], $apteczka['unknown_skus']);
        $this->assertGreaterThan(0, $apteczka['candidates']);
        $this->assertSame(1.0, $apteczka['retrieval_recall']);
        $this->assertSame([], $apteczka['violations'], 'brak forbidden_skus → brak naruszeń');
        $this->assertContains($apteczka['recall_at_k'], [0.0, 1.0], 'jedno oczekiwane SKU → recall@k jest 0 albo 1');
        if ($apteczka['missing_skus'] === []) {
            $this->assertSame(1.0, $apteczka['recall_at_k']);
            $this->assertGreaterThan(0.0, $apteczka['mrr']);
        } else {
            $this->assertSame(0.0, $apteczka['recall_at_k']);
            $this->assertSame(SearchEvalMetrics::normalizeAll(['191400']), $apteczka['missing_skus']);
        }

        // SKU spoza katalogu = zły wpis w golden secie, nie zła wyszukiwarka.
        $unknown = $runner->evaluate([
            'id' => 'test-unknown-sku',
            'query' => $cases['opisowy15-10-apteczka-scienna-mini']['query'],
            'expected_skus' => ['NIE-MA-TAKIEJ-KARTY'],
            'forbidden_skus' => [],
            'note' => '',
        ], self::K, ProductAiSearchService::CATALOG_LIMIT);
        $this->assertNull($unknown['error']);
        $this->assertSame(SearchEvalMetrics::normalizeAll(['NIE-MA-TAKIEJ-KARTY']), $unknown['unknown_skus']);
        $this->assertSame(0.0, $unknown['retrieval_recall']);
        $this->assertSame(0.0, $unknown['recall_at_k']);
        $this->assertSame(SearchEvalMetrics::normalizeAll(['NIE-MA-TAKIEJ-KARTY']), $unknown['missing_skus']);

        // Naruszenie: SKU z forbidden_skus w top‑k wyniku. Zakazujemy karty, którą wyszukiwarka
        // faktycznie zwraca dla tego zapytania, więc test sprawdza mechanikę, a nie konkretny błąd.
        $query = $cases['opisowy15-14-pochlaniacz-a2']['query'];
        $returned = array_map(
            static fn (array $row): string => (string) ($row['sku'] ?? ''),
            app(ProductAiSearchService::class)->search($query, ProductAiSearchService::CATALOG_LIMIT)['products'],
        );
        $this->assertNotSame([], $returned, 'pochłaniacz A2 z fixture ma wracać z wyszukiwarki');
        $violation = $runner->evaluate([
            'id' => 'test-violation',
            'query' => $query,
            'expected_skus' => ['S565A202'],
            'forbidden_skus' => [$returned[0]],
            'note' => '',
        ], self::K, ProductAiSearchService::CATALOG_LIMIT);
        $this->assertSame(SearchEvalMetrics::normalizeAll([$returned[0]]), $violation['violations']);
        $this->assertSame(count($returned), $violation['returned']);

        $summary = $runner->summarize([$apteczka, $unknown, $violation]);
        $this->assertSame(3, $summary['cases']);
        $this->assertSame(1, $summary['violations']);
        $this->assertSame(0, $summary['errors']);
        $this->assertEqualsWithDelta(($apteczka['retrieval_recall'] + $unknown['retrieval_recall'] + $violation['retrieval_recall']) / 3, $summary['retrieval_recall'], 0.0001);
    }
}
