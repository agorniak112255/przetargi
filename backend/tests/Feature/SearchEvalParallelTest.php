<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Search\SearchEvalParallel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * `search:eval --parallel` (25.09.2026): przypadki rozdzielone między procesy `search:eval-worker`, każdy dalej idzie
 * drogą pojedynczego wyszukiwania; wiersze wracają w kolejności golden setu, a przypadek procesu, który padł, jest
 * błędem, a nie zerem trafień.
 */
final class SearchEvalParallelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cases_are_dealt_round_robin(): void
    {
        $cases = array_map(static fn (int $i): array => self::caseRow('c'.$i), range(0, 4));
        $shards = app(SearchEvalParallel::class)->shards($cases, 2);

        $this->assertSame([['c0', 'c2', 'c4'], ['c1', 'c3']], array_map(static fn (array $s): array => array_column($s, 'id'), $shards));
        $this->assertCount(5, app(SearchEvalParallel::class)->shards($cases, 9), 'nie więcej procesów niż przypadków');
    }

    public function test_rows_come_back_in_golden_order_with_progress_and_fallbacks(): void
    {
        $cases = array_map(static fn (int $i): array => self::caseRow('c'.$i), range(0, 4));
        Process::fake(function (PendingProcess $process) {
            $ids = self::idsOf($process);
            $lines = array_map(static fn (string $id): string => SearchEvalParallel::DONE.$id, $ids);
            $rows = array_map(static fn (string $id): array => ['id' => $id, 'error' => null, 'mrr' => 1.0], $ids);
            $lines[] = SearchEvalParallel::RESULT.base64_encode((string) json_encode(['rows' => $rows, 'profile_fallbacks' => 1]));

            return Process::result(output: implode("\n", $lines)."\n");
        });
        $done = [];

        $outcome = app(SearchEvalParallel::class)->run($cases, 'golden.json', 10, 40, false, 2, function (string $id) use (&$done): void {
            $done[] = $id;
        });

        $this->assertSame(['c0', 'c1', 'c2', 'c3', 'c4'], array_column($outcome['rows'], 'id'));
        $this->assertSame([], $outcome['failures']);
        $this->assertSame(2, $outcome['profile_fallbacks'], 'zejścia z profilu sumowane ze wszystkich procesów');
        sort($done);
        $this->assertSame(['c0', 'c1', 'c2', 'c3', 'c4'], $done, 'każdy skończony przypadek przesuwa pasek postępu');
        Process::assertRanTimes(fn (PendingProcess $process): bool => in_array('search:eval-worker', (array) $process->command, true), 2);
    }

    public function test_cases_of_a_crashed_worker_are_errors_not_zero_hits(): void
    {
        $cases = array_map(static fn (int $i): array => self::caseRow('c'.$i), range(0, 3));
        Process::fake(function (PendingProcess $process) {
            $ids = self::idsOf($process);
            if (in_array('c1', $ids, true)) {
                return Process::result(errorOutput: 'Allowed memory size exhausted', exitCode: 255);
            }
            $rows = array_map(static fn (string $id): array => ['id' => $id, 'error' => null], $ids);

            return Process::result(output: SearchEvalParallel::RESULT.base64_encode((string) json_encode(['rows' => $rows]))."\n");
        });

        $outcome = app(SearchEvalParallel::class)->run($cases, 'golden.json', 10, 40, false, 2, static function (): void {});

        $this->assertSame(['c0', 'c1', 'c2', 'c3'], array_column($outcome['rows'], 'id'));
        $this->assertNull($outcome['rows'][0]['error']);
        $this->assertStringContainsString('Allowed memory size', (string) $outcome['rows'][1]['error']);
        $this->assertStringContainsString('Allowed memory size', (string) $outcome['rows'][3]['error']);
        $this->assertCount(1, $outcome['failures']);
    }

    public function test_worker_evaluates_only_its_cases_and_reports_them(): void
    {
        $golden = storage_path('framework/testing/search-eval-worker-'.uniqid().'.json');
        $ids = storage_path('framework/testing/search-eval-worker-ids-'.uniqid().'.json');
        @mkdir(dirname($golden), 0775, true);
        file_put_contents($golden, json_encode(['cases' => [
            ['id' => 'a', 'query' => 'Rękawice nitrylowe', 'expected_skus' => ['X1'], 'forbidden_skus' => []],
            ['id' => 'b', 'query' => 'Kask ochronny', 'expected_skus' => ['X2'], 'forbidden_skus' => []],
        ]]));
        file_put_contents($ids, json_encode(['b']));
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
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());

        try {
            $this->artisan('search:eval-worker', ['--file' => $golden, '--ids-file' => $ids, '--limit' => 40])
                ->expectsOutputToContain(SearchEvalParallel::DONE.'b')
                ->doesntExpectOutputToContain(SearchEvalParallel::DONE.'a')
                ->expectsOutputToContain(SearchEvalParallel::RESULT)
                ->assertSuccessful();
        } finally {
            @unlink($golden);
            @unlink($ids);
        }
    }

    /** @return list<string> */
    private static function idsOf(PendingProcess $process): array
    {
        foreach ((array) $process->command as $part) {
            if (str_starts_with((string) $part, '--ids-file=')) {
                return json_decode((string) file_get_contents(substr((string) $part, strlen('--ids-file='))), true);
            }
        }

        return [];
    }

    /** @return array{id: string, query: string, expected_skus: list<string>, forbidden_skus: list<string>} */
    private static function caseRow(string $id): array
    {
        return ['id' => $id, 'query' => 'Zapytanie '.$id, 'expected_skus' => ['SKU-'.$id], 'forbidden_skus' => []];
    }
}
