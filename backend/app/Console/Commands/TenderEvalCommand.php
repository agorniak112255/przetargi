<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TenderItem;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
use App\Services\Search\SearchEvalRunner;
use App\Support\SearchEvalMetrics;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pomiar decyzji zapisu przetargu na golden secie (AUDYT_4 §8, pkt 1): wymagania idą razem przez
 * searchMany (jak „Dopasuj wszystkie”), a decyzja przez ProductMatchService::debugPick — ta sama
 * ścieżka co zapis, bez zapisu do przetargu. Werdykt przypadku: trafna / zakazana / inna / pusta.
 * `search:eval` mierzy tylko wyszukiwarkę — poz. 3 przetargu 1 (95% od modelu, brak zapisu) by
 * przeoczył. Model nie jest deterministyczny: uruchamiaj z --runs=2 przed i po zmianie.
 */
final class TenderEvalCommand extends Command
{
    public const VERDICT_HIT = 'trafna';

    public const VERDICT_FORBIDDEN = 'zakazana';

    public const VERDICT_OTHER = 'inna';

    public const VERDICT_EMPTY = 'pusta';

    private const VERDICTS = [self::VERDICT_HIT, self::VERDICT_FORBIDDEN, self::VERDICT_OTHER, self::VERDICT_EMPTY];

    protected $signature = 'tenders:eval
        {--file= : Plik golden setu (domyślnie resources/search-eval/golden.json)}
        {--filter=opisowy15 : Tylko przypadki, których id lub zapytanie zawiera ten tekst (pusty = wszystkie)}
        {--runs=1 : Ile przebiegów (1–5); model nie jest deterministyczny}
        {--save : Zapisz raport JSON w storage/app/tender-eval/reports}
        {--baseline= : Raport do porównania (ścieżka JSON z poprzedniego przebiegu)}';

    protected $description = 'Mierzy decyzję zapisu przetargu na golden secie: trafna / zakazana / inna / pusta (bez zapisu, wywołuje model)';

    public function handle(SearchEvalRunner $runner, AiSettingsService $settings): int
    {
        if (! $settings->isReady()) {
            $this->error('AI nie jest skonfigurowane — pomiar wymaga modelu.');

            return self::FAILURE;
        }
        $file = (string) ($this->option('file') ?: base_path('resources/search-eval/golden.json'));
        try {
            $cases = $runner->loadCases($file);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $filter = trim((string) $this->option('filter'));
        if ($filter !== '') {
            $cases = array_values(array_filter($cases, static fn (array $case): bool => mb_stripos($case['id'], $filter) !== false
                || mb_stripos($case['query'], $filter) !== false));
        }
        if ($cases === []) {
            $this->warn('Żaden przypadek nie pasuje do filtra.');

            return self::SUCCESS;
        }

        $runs = max(1, min(5, (int) $this->option('runs')));
        $profile = $settings->profileForTask(AiTask::ProductSearch);
        $header = [
            'generated_at' => now()->toIso8601String(),
            'file' => $file,
            'filter' => $filter,
            'cases' => count($cases),
            'runs' => $runs,
            'prompt_version' => ProductAiSearchService::RANK_PROMPT_VERSION,
            'model' => (string) ($profile['model'] ?? ''),
            'profile' => (string) ($profile['label'] ?? ''),
            'card_detail' => $settings->productSearchUsesShortCards() ? 'short' : 'long',
            'match_concurrency' => $settings->matchConcurrency(),
            'catalog_search_limit' => $settings->catalogSearchLimit(),
        ];
        $this->info(sprintf(
            'Golden set: %s · przypadków: %d · przebiegów: %d · prompt=%s · model=%s (%s) · karty=%s · równolegle=%d',
            $file,
            $header['cases'],
            $runs,
            $header['prompt_version'],
            $header['model'],
            $header['profile'],
            $header['card_detail'],
            $header['match_concurrency'],
        ));

        $results = [];
        foreach ($cases as $case) {
            $results[$case['id']] = ['id' => $case['id'], 'expected_skus' => $case['expected_skus'], 'forbidden_skus' => $case['forbidden_skus'], 'runs' => []];
        }
        for ($run = 1; $run <= $runs; $run++) {
            $this->line("Przebieg {$run}/{$runs}…");
            $search = app()->make(ProductAiSearchService::class);
            $matcher = app()->make(ProductMatchService::class);
            $rows = $search->searchMany(
                array_map(static fn (array $case): string => $case['query'], $cases),
                $settings->catalogSearchLimit(),
                false,
                AiTask::ProductSearch,
                $settings->matchConcurrency(),
            );
            foreach ($cases as $i => $case) {
                $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
                $item = new TenderItem;
                $item->forceFill(['requirement' => $case['query']]);
                try {
                    $results[$case['id']]['runs'][] = $this->classify($case, $matcher->debugPick($item, $row), $row);
                } catch (Throwable $e) {
                    $results[$case['id']]['runs'][] = ['verdict' => self::VERDICT_EMPTY, 'sku' => null, 'score' => null, 'source' => null, 'top_model' => null, 'model_state' => null, 'reason' => 'błąd: '.$e->getMessage()];
                }
            }
        }

        $results = array_values($results);
        $summary = $this->summarize($results, $runs);
        $this->renderCases($results, $runs);
        $this->renderSummary($summary, $runs, count($cases));

        $baseline = trim((string) $this->option('baseline'));
        if ($baseline !== '') {
            $this->renderBaseline($baseline, $results);
        }
        if ($this->option('save')) {
            $this->saveReport(['header' => $header, 'summary' => $summary, 'cases' => $results]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{id: string, query: string, expected_skus: list<string>, forbidden_skus: list<string>}  $case
     * @param  array{candidates: list<array<string, mixed>>, pick: array{sku: string, score: int, source: string, heuristic_only: bool}|null, reason: string|null}  $decision
     * @param  array<string, mixed>  $row
     * @return array{verdict: string, sku: string|null, score: int|null, source: string|null, top_model: string|null, model_state: string|null, reason: string|null}
     */
    private function classify(array $case, array $decision, array $row): array
    {
        $pick = $decision['pick'];
        // Stan rankingu dla tej pozycji w paczce: ranked / empty / unavailable / skipped. Poz. 12 pojedynczo
        // dostaje 95–99 od modelu, a w paczce 15 pozycji tylko zapasowe 92 z reguły — bez stanu nie widać,
        // czy model odpowiedział pusto, czy zapytanie padło.
        $state = is_string($row['model_state'] ?? null) ? $row['model_state'] : null;
        $top = null;
        $first = is_array($row['products'][0] ?? null) ? $row['products'][0] : null;
        if ($first !== null) {
            // źródło: płaskie 92 z reguły klasy obuwia (rule) wyglądało jak ocena modelu (poz. 12)
            $top = (string) ($first['sku'] ?? '').'='.(int) ($first['ai_match_percent'] ?? 0)
                .' ('.(string) ($first['ai_match_source'] ?? 'model').')';
        }
        if ($pick === null) {
            return ['verdict' => self::VERDICT_EMPTY, 'sku' => null, 'score' => null, 'source' => null, 'top_model' => $top, 'model_state' => $state, 'reason' => $decision['reason']];
        }

        $sku = SearchEvalMetrics::normalizeAll([$pick['sku']])[0] ?? $pick['sku'];
        $verdict = match (true) {
            in_array($sku, SearchEvalMetrics::normalizeAll($case['expected_skus']), true) => self::VERDICT_HIT,
            in_array($sku, SearchEvalMetrics::normalizeAll($case['forbidden_skus']), true) => self::VERDICT_FORBIDDEN,
            default => self::VERDICT_OTHER,
        };

        return [
            'verdict' => $verdict,
            'sku' => $pick['sku'],
            'score' => $pick['score'],
            'source' => $pick['source'].($pick['heuristic_only'] ? ' (po słowach)' : ''),
            'top_model' => $top,
            'model_state' => $state,
            'reason' => null,
        ];
    }

    /**
     * @param  list<array{id: string, runs: list<array<string, mixed>>}>  $results
     * @return array{per_run: list<array<string, int>>, stable: int, unstable: list<string>, model_states: array<string, int>}
     */
    private function summarize(array $results, int $runs): array
    {
        $perRun = [];
        for ($r = 0; $r < $runs; $r++) {
            $counts = array_fill_keys(self::VERDICTS, 0);
            foreach ($results as $result) {
                $counts[(string) ($result['runs'][$r]['verdict'] ?? self::VERDICT_EMPTY)]++;
            }
            $perRun[] = $counts;
        }
        $unstable = [];
        foreach ($results as $result) {
            $verdicts = array_unique(array_map(static fn (array $run): string => (string) $run['verdict'], $result['runs']));
            if (count($verdicts) > 1) {
                $unstable[] = $result['id'];
            }
        }

        $states = [];
        foreach ($results as $result) {
            foreach ($result['runs'] as $run) {
                $state = (string) ($run['model_state'] ?? 'brak');
                $states[$state] = ($states[$state] ?? 0) + 1;
            }
        }
        ksort($states);

        return ['per_run' => $perRun, 'stable' => count($results) - count($unstable), 'unstable' => $unstable, 'model_states' => $states];
    }

    /** @param list<array{id: string, expected_skus: list<string>, runs: list<array<string, mixed>>}> $results */
    private function renderCases(array $results, int $runs): void
    {
        $this->table(
            ['przypadek', 'oczekiwane', 'wybór przetargu (przebieg 1)', 'werdykty', 'stan modelu', 'najwyżej w modelu', 'powód braku'],
            array_map(static function (array $result): array {
                $first = $result['runs'][0] ?? [];
                $expected = (string) ($result['expected_skus'][0] ?? '');
                if (count($result['expected_skus']) > 1) {
                    $expected .= ' +'.(count($result['expected_skus']) - 1);
                }

                return [
                    mb_substr($result['id'], 0, 34),
                    mb_substr($expected, 0, 30),
                    ($first['sku'] ?? null) === null ? '—' : mb_substr((string) $first['sku'], 0, 30).' ('.$first['score'].'%, '.$first['source'].')',
                    implode(' / ', array_map(static fn (array $run): string => (string) $run['verdict'], $result['runs'])),
                    implode(' / ', array_map(static fn (array $run): string => (string) ($run['model_state'] ?? '—'), $result['runs'])),
                    (string) ($first['top_model'] ?? '—'),
                    mb_substr((string) ($first['reason'] ?? ''), 0, 60),
                ];
            }, $results),
        );
    }

    /** @param array{per_run: list<array<string, int>>, stable: int, unstable: list<string>, model_states: array<string, int>} $summary */
    private function renderSummary(array $summary, int $runs, int $cases): void
    {
        $this->line('<options=bold>Podsumowanie</>');
        $rows = [];
        foreach ($summary['per_run'] as $i => $counts) {
            $rows[] = [
                'przebieg '.($i + 1),
                $counts[self::VERDICT_HIT].'/'.$cases,
                (string) $counts[self::VERDICT_FORBIDDEN],
                (string) $counts[self::VERDICT_OTHER],
                (string) $counts[self::VERDICT_EMPTY],
            ];
        }
        if (count($summary['per_run']) > 1) {
            $total = array_fill_keys(self::VERDICTS, 0);
            foreach ($summary['per_run'] as $counts) {
                foreach (self::VERDICTS as $verdict) {
                    $total[$verdict] += $counts[$verdict];
                }
            }
            $rows[] = [
                'razem ('.count($summary['per_run']).' przebiegi)',
                $total[self::VERDICT_HIT].'/'.($cases * count($summary['per_run'])),
                (string) $total[self::VERDICT_FORBIDDEN],
                (string) $total[self::VERDICT_OTHER],
                (string) $total[self::VERDICT_EMPTY],
            ];
        }
        $this->table(['', 'trafne', 'zakazane', 'inne (złe)', 'puste'], $rows);
        $this->line(sprintf('Stabilne między przebiegami: %d/%d%s', $summary['stable'], $cases, $summary['unstable'] === []
            ? ''
            : ' · niestabilne: '.implode(', ', $summary['unstable'])));
        $this->line('Stan modelu (wszystkie przebiegi): '.implode(' · ', array_map(
            static fn (string $state, int $count): string => "{$state} {$count}",
            array_keys($summary['model_states']),
            $summary['model_states'],
        )).' — unavailable = zapytanie padło (limit/timeout), empty = model nic nie wskazał, skipped = bez rankingu');
        $this->line('Próg akceptacji zmiany (AUDYT_4): zakazane = 0; złe karty (zakazane + inne) nie więcej niż w bazie; dziś trafne dalej trafne; trafnych ≥ 10/15.');
    }

    /**
     * Porównanie z bazą po udziale werdyktów ze wszystkich przebiegów. Przebieg 1 osobno mylił
     * niestabilność modelu ze skutkiem zmiany (poz. 8: trafna 1/2 przed i po, a raport pisał „regres”).
     *
     * @param  list<array{id: string, runs: list<array<string, mixed>>}>  $results
     */
    private function renderBaseline(string $path, array $results): void
    {
        if (! is_file($path)) {
            $this->warn("Nie ma raportu bazowego: {$path}");

            return;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        $baseCases = is_array($raw['cases'] ?? null) ? $raw['cases'] : null;
        if ($baseCases === null) {
            $this->warn("Raport bazowy nie ma sekcji `cases`: {$path}");

            return;
        }
        $base = [];
        foreach ($baseCases as $case) {
            if (is_array($case) && is_string($case['id'] ?? null) && is_array($case['runs'] ?? null)) {
                $base[$case['id']] = $this->verdictShares($case['runs']);
            }
        }

        $this->line('<options=bold>Zmiana względem '.basename($path).' (wszystkie przebiegi)</>');
        $changes = [];
        $better = 0;
        $worse = 0;
        $totals = ['before_hit' => 0.0, 'after_hit' => 0.0, 'before_bad' => 0.0, 'after_bad' => 0.0];
        foreach ($results as $result) {
            if (! isset($base[$result['id']])) {
                continue;
            }
            $before = $base[$result['id']];
            $after = $this->verdictShares($result['runs']);
            $totals['before_hit'] += $before['hit'];
            $totals['after_hit'] += $after['hit'];
            $totals['before_bad'] += $before['bad'];
            $totals['after_bad'] += $after['bad'];
            // lepiej: więcej trafnych albo mniej złych kart (zła karta gorsza niż pusta)
            $delta = ($after['hit'] - $before['hit']) - ($after['bad'] - $before['bad']);
            if (abs($delta) < 0.001) {
                continue;
            }
            $label = $delta > 0 ? 'poprawa' : 'regres';
            $label === 'poprawa' ? $better++ : $worse++;
            $changes[] = [mb_substr($result['id'], 0, 34), $before['label'], $after['label'], $label];
        }
        if ($changes === []) {
            $this->line('Bez zmian udziału trafnych i złych kart.');
        } else {
            $this->table(['przypadek', 'przed (trafne/złe)', 'po (trafne/złe)', 'ocena'], $changes);
        }
        $this->line(sprintf(
            'Poprawy: %d · regresy: %d · średnio trafnych na przebieg: %.1f → %.1f · złych kart (zakazane + inne): %.1f → %.1f',
            $better,
            $worse,
            $totals['before_hit'],
            $totals['after_hit'],
            $totals['before_bad'],
            $totals['after_bad'],
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array{hit: float, bad: float, label: string}
     */
    private function verdictShares(array $runs): array
    {
        $count = max(1, count($runs));
        $hit = 0;
        $bad = 0;
        foreach ($runs as $run) {
            $verdict = (string) ($run['verdict'] ?? self::VERDICT_EMPTY);
            $hit += $verdict === self::VERDICT_HIT ? 1 : 0;
            $bad += in_array($verdict, [self::VERDICT_FORBIDDEN, self::VERDICT_OTHER], true) ? 1 : 0;
        }

        return ['hit' => $hit / $count, 'bad' => $bad / $count, 'label' => "{$hit}/{$count} · {$bad}/{$count}"];
    }

    /** @param array<string, mixed> $report */
    private function saveReport(array $report): void
    {
        $dir = storage_path('app/tender-eval/reports');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error('Nie udało się utworzyć katalogu raportów: '.$dir);

            return;
        }
        $path = $dir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'.json';
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info('Raport: '.$path);
    }
}
