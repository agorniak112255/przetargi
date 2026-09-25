<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSearchLlm;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * `search:eval` po decyzjach A i B z 25.09.2026: podsumowanie pokazuje naruszenia blokujące, zakazane pokazane
 * pod właściwą kartą i równoważniki; porównanie z raportem sprzed tych pól nie wywraca się, tylko pokazuje „—”.
 */
final class SearchEvalCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $golden;

    private string $baseline;

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
        @mkdir(storage_path('framework/testing'), 0775, true);
        $this->golden = storage_path('framework/testing/search-eval-golden-'.uniqid().'.json');
        $this->baseline = storage_path('framework/testing/search-eval-baseline-'.uniqid().'.json');
    }

    protected function tearDown(): void
    {
        @unlink($this->golden);
        @unlink($this->baseline);
        parent::tearDown();
    }

    public function test_summary_splits_violations_and_baseline_without_new_fields_shows_dash(): void
    {
        Opisowy15Fixture::seed();
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());
        $line = Opisowy15Fixture::line(14);
        $returned = array_map(
            static fn (array $row): string => (string) ($row['sku'] ?? ''),
            app(ProductAiSearchService::class)->search($line['requirement'], ProductAiSearchService::CATALOG_LIMIT)['products'],
        );
        $this->assertNotSame([], $returned, 'pochłaniacz A2 z fixture ma wracać z wyszukiwarki');

        file_put_contents($this->golden, json_encode(['cases' => [[
            'id' => 'test-pochlaniacz',
            'query' => $line['requirement'],
            'expected_skus' => ['S565A202'],
            // Pierwsza karta wyniku jako zakazana: lista zapasowa bez karty wzorcowej ≥ progu nad nią → blokująca.
            'forbidden_skus' => [$returned[0], 'NIE-MA-ZAKAZANEJ'],
            'acceptable_skus' => ['NIE-MA-ROWNOWAZNIKA'],
            'acceptable_note' => 'test',
        ]]], JSON_UNESCAPED_UNICODE));
        // Raport sprzed 25.09.2026: podsumowanie bez violations_blocking, forbidden_shown i acceptable_hits.
        file_put_contents($this->baseline, json_encode(['summary' => [
            'retrieval_recall' => 1.0,
            'recall_at_k' => 1.0,
            'precision_at_k' => 0.1,
            'ndcg_at_k' => 1.0,
            'mrr' => 1.0,
            'cases' => 1,
            'violations' => 0,
            'errors' => 0,
            'avg_ms' => 10,
        ]]));
        $reportDir = storage_path('app/search-eval/reports');
        $before = glob($reportDir.'/*.json') ?: [];

        try {
            $this->artisan('search:eval', [
                '--file' => $this->golden,
                '--baseline' => $this->baseline,
                '--worst' => 0,
                '--save' => true,
            ])
                ->expectsOutputToContain('naruszenia blokujące')
                ->expectsOutputToContain('zakazane pod właściwą kartą')
                ->expectsOutputToContain('równoważniki w top-10')
                ->expectsOutputToContain('test-pochlaniacz: NIE-MA-ZAKAZANEJ (zakazane)')
                ->expectsOutputToContain('test-pochlaniacz: NIE-MA-ROWNOWAZNIKA (równoważnik)')
                ->expectsOutputToContain('Zwrócone SKU z listy zakazanych — blokujące')
                ->doesntExpectOutputToContain('pod właściwą kartą (ostrzeżenie)')
                ->expectsOutputToContain('violations_blocking')
                ->expectsOutputToContain('Raport bazowy powstał bez acceptable_skus')
                ->assertSuccessful();

            $created = array_values(array_diff(glob($reportDir.'/*.json') ?: [], $before));
            $this->assertCount(1, $created, 'zapisany jeden raport');
            $report = json_decode((string) file_get_contents($created[0]), true);
        } finally {
            foreach (array_diff(glob($reportDir.'/*.json') ?: [], $before) as $file) {
                @unlink($file);
            }
        }

        $this->assertSame(65, $report['match_min_score'], 'próg zapisu użyty dla forbidden_shown');
        $this->assertSame(1, $report['summary']['violations']);
        $this->assertSame(1, $report['summary']['violations_blocking']);
        $this->assertSame(0, $report['summary']['forbidden_shown']);
        $this->assertSame(0, $report['summary']['acceptable_hits']);
        $case = $report['cases'][0];
        $this->assertSame('forbidden', $case['returned_top'][0]['tag']);
        $this->assertSame(['NIE-MA-ZAKAZANEJ'], $case['unknown_forbidden_skus']);
        $this->assertSame(['NIE-MA-ROWNOWAZNIKA'], $case['unknown_acceptable_skus']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $report['golden_signature']);
    }

    /**
     * Recenzja 25.09.2026: po K11 każdy raport ma pola z 25.09, więc ostrzeżenie „bez acceptable_skus” milczało przy
     * zmianie list golden. Podpis list w raporcie mówi, że różnica to zmiana miary.
     */
    public function test_baseline_with_other_golden_lists_warns_about_measure_change(): void
    {
        Opisowy15Fixture::seed();
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());
        $line = Opisowy15Fixture::line(14);
        file_put_contents($this->golden, json_encode(['cases' => [[
            'id' => 'test-pochlaniacz',
            'query' => $line['requirement'],
            'expected_skus' => ['S565A202'],
        ]]], JSON_UNESCAPED_UNICODE));
        $summary = ['retrieval_recall' => 1.0, 'recall_at_k' => 1.0, 'precision_at_k' => 0.1, 'ndcg_at_k' => 1.0, 'mrr' => 1.0,
            'cases' => 1, 'violations' => 0, 'violations_blocking' => 0, 'forbidden_shown' => 0, 'acceptable_hits' => 0, 'errors' => 0, 'avg_ms' => 10];

        file_put_contents($this->baseline, json_encode(['golden_signature' => 'ffffffffffffffff', 'summary' => $summary]));
        $this->artisan('search:eval', ['--file' => $this->golden, '--baseline' => $this->baseline, '--worst' => 0])
            ->expectsOutputToContain('Golden set różni się od użytego w raporcie bazowym')
            ->assertSuccessful();
    }
}
