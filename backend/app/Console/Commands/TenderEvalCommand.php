<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\TenderItem;
use App\Services\Ai\AiServedProviderTally;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
use App\Services\Search\SearchEvalRunner;
use App\Support\SearchEvalMetrics;
use Carbon\CarbonInterface;
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
        {--baseline= : Raport do porównania (ścieżka JSON z poprzedniego przebiegu)}
        {--replay= : Raport z zapisanymi wynikami wyszukiwania — decyzja liczona od nowa bez wywołań modelu}';

    protected $description = 'Mierzy decyzję zapisu przetargu na golden secie: trafna / zakazana / inna / pusta (bez zapisu, wywołuje model)';

    public function handle(SearchEvalRunner $runner, AiSettingsService $settings): int
    {
        $replay = trim((string) $this->option('replay'));
        if ($replay === '' && ! $settings->isReady()) {
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

        $replayed = [];
        if ($replay !== '') {
            $replayed = $this->loadReplay($replay);
            if ($replayed === null) {
                return self::FAILURE;
            }
        }
        $runs = $replay !== ''
            ? max(1, ...array_values(array_map(static fn (array $case): int => count($case['runs'] ?? []), $replayed ?: [['runs' => [1]]])))
            : max(1, min(5, (int) $this->option('runs')));
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
            'replay_of' => $replay !== '' ? $replay : null,
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
        $runTimings = [];
        if ($replay !== '') {
            // Te same odpowiedzi modelu, bieżący kod decyzji: zmiana kolejności wyboru bez szumu modelu i bez kosztu.
            $this->line('Odtworzenie decyzji z zapisanych wyników wyszukiwania (bez modelu): '.$replay);
            for ($run = 0; $run < $runs; $run++) {
                // nowy obiekt jak w przebiegu na żywo — pamięć kandydatów poprzedniego przebiegu nie wpływa na decyzję
                $matcher = app()->make(ProductMatchService::class);
                foreach ($cases as $case) {
                    $recorded = $replayed[$case['id']]['runs'][$run] ?? null;
                    $row = is_array($recorded['search'] ?? null) ? $recorded['search'] : null;
                    if ($row === null) {
                        $results[$case['id']]['runs'][] = ['verdict' => self::VERDICT_EMPTY, 'sku' => null, 'score' => null, 'source' => null, 'top_model' => null, 'model_state' => null, 'reason' => 'raport bez zapisanego wyszukiwania'];

                        continue;
                    }
                    $item = new TenderItem;
                    $item->forceFill(['requirement' => $case['query']]);
                    $results[$case['id']]['runs'][] = $this->classify($case, $matcher->debugPick($item, $row), $row) + ['search' => $row];
                }
            }
        }
        for ($run = 1; $replay === '' && $run <= $runs; $run++) {
            $this->line("Przebieg {$run}/{$runs}…");
            // Raport 20260914_131814: przebieg 1 dał 5 złych kart z oceną 95, przebiegi 2–3 tym samym kodem żadnej,
            // a serwer pobierał wtedy opisy produktów przez tego samego klienta modelu. Zmienione karty i dostawcy
            // modelu z przebiegu muszą być widoczne, bo zmieniają porównanie z innymi raportami.
            $runStartedAt = now()->startOfSecond();
            $tally = app(AiServedProviderTally::class);
            $tally->reset();
            $searchStarted = hrtime(true);
            $search = app()->make(ProductAiSearchService::class);
            $matcher = app()->make(ProductMatchService::class);
            $rows = $search->searchMany(
                array_map(static fn (array $case): string => $case['query'], $cases),
                $settings->catalogSearchLimit(),
                false,
                AiTask::ProductSearch,
                $settings->matchConcurrency(),
            );
            $searchMs = (int) round((hrtime(true) - $searchStarted) / 1e6);
            $stages = is_array($search->lastTrace()['timings_ms'] ?? null) ? $search->lastTrace()['timings_ms'] : [];
            $decisionStarted = hrtime(true);
            foreach ($cases as $i => $case) {
                $row = is_array($rows[$i] ?? null) ? $rows[$i] : [];
                $item = new TenderItem;
                $item->forceFill(['requirement' => $case['query']]);
                try {
                    $results[$case['id']]['runs'][] = $this->classify($case, $matcher->debugPick($item, $row), $row)
                        + ['search' => $this->recordedSearch($row)];
                } catch (Throwable $e) {
                    $results[$case['id']]['runs'][] = ['verdict' => self::VERDICT_EMPTY, 'sku' => null, 'score' => null, 'source' => null, 'top_model' => null, 'model_state' => null, 'reason' => 'błąd: '.$e->getMessage()];
                }
            }
            // Gdzie idzie czas przebiegu (produkcja 13.09: pomiar trwał kilka razy dłużej po zmianach wyszukiwania).
            $decisionMs = (int) round((hrtime(true) - $decisionStarted) / 1e6);
            $understandMs = (int) ($stages['understand'] ?? 0);
            $catalogMs = (int) ($stages['catalog'] ?? 0);
            $this->line(sprintf(
                'Czas przebiegu %d: %.1f s · zrozumienie %.1f s · katalog %.1f s · ranking modelu %.1f s · decyzja %.1f s',
                $run,
                ($searchMs + $decisionMs) / 1000,
                $understandMs / 1000,
                $catalogMs / 1000,
                max(0, $searchMs - $understandMs - $catalogMs) / 1000,
                $decisionMs / 1000,
            ));
            $runTimings[] = ['run' => $run, 'search_ms' => $searchMs, 'decision_ms' => $decisionMs, 'stages_ms' => $stages]
                + $this->runConditions($run, $runStartedAt, $cases, is_array($rows) ? $rows : [], $tally->snapshot());
        }

        $results = array_values($results);
        $summary = $this->summarize($results, $runs);
        $this->renderCases($results, $runs);
        $this->renderBadPicks($results);
        $this->renderSummary($summary, $runs, count($cases));

        $baseline = trim((string) $this->option('baseline'));
        if ($baseline !== '') {
            $this->renderBaseline($baseline, $results);
        }
        if ($this->option('save')) {
            $this->saveReport(['header' => $header, 'summary' => $summary, 'timings' => $runTimings, 'cases' => $results]);
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
        $providers = $this->rowProviders($row);
        $top = null;
        $first = is_array($row['products'][0] ?? null) ? $row['products'][0] : null;
        if ($first !== null) {
            // źródło: płaskie 92 z reguły klasy obuwia (rule) wyglądało jak ocena modelu (poz. 12)
            $top = (string) ($first['sku'] ?? '').'='.(int) ($first['ai_match_percent'] ?? 0)
                .' ('.(string) ($first['ai_match_source'] ?? 'model').')';
        }
        if ($pick === null) {
            return ['verdict' => self::VERDICT_EMPTY, 'sku' => null, 'score' => null, 'source' => null, 'top_model' => $top, 'model_state' => $state, 'reason' => $decision['reason'], 'providers' => $providers];
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
            'providers' => $providers,
        ];
    }

    /**
     * @param  list<array{id: string, runs: list<array<string, mixed>>}>  $results
     * @return array{per_run: list<array<string, int>>, stable: int, unstable: list<string>, model_states: array<string, int>, by_rank_provider: array<string, array<string, int>>}
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

        // Raport 20260914_134633: jedyna zła karta w przebiegu, w którym połowę odpowiedzi dał zastępczy dostawca modelu.
        $byProvider = [];
        foreach ($results as $result) {
            foreach ($result['runs'] as $run) {
                $provider = is_string($run['providers']['rank'] ?? null) ? $run['providers']['rank'] : 'nieznany';
                $byProvider[$provider] ??= array_fill_keys(self::VERDICTS, 0);
                $verdict = (string) ($run['verdict'] ?? self::VERDICT_EMPTY);
                $byProvider[$provider][$verdict] = ($byProvider[$provider][$verdict] ?? 0) + 1;
            }
        }
        if (array_keys($byProvider) === ['nieznany']) {
            $byProvider = [];
        }
        ksort($byProvider);

        return ['per_run' => $perRun, 'stable' => count($results) - count($unstable), 'unstable' => $unstable, 'model_states' => $states, 'by_rank_provider' => $byProvider];
    }

    /**
     * Dostawca modelu, który zrozumiał i ocenił pozycję (pole wyszukiwania `model_providers`; null = brak odpowiedzi
     * albo API bez pola „provider”).
     *
     * @param  array<string, mixed>  $row
     * @return array{understand: ?string, rank: ?string}
     */
    private function rowProviders(array $row): array
    {
        $providers = is_array($row['model_providers'] ?? null) ? $row['model_providers'] : [];

        return [
            'understand' => is_string($providers['understand'] ?? null) ? $providers['understand'] : null,
            'rank' => is_string($providers['rank'] ?? null) ? $providers['rank'] : null,
        ];
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

    /**
     * Złe wybory (zakazana / inna) ze wszystkich przebiegów — tabela przypadków pokazuje tylko przebieg 1,
     * a poz. 15 dała kartę zakazaną wyłącznie w przebiegu 2 i nie było widać, jaką ani skąd.
     *
     * @param  list<array{id: string, runs: list<array<string, mixed>>}>  $results
     */
    private function renderBadPicks(array $results): void
    {
        $rows = [];
        foreach ($results as $result) {
            foreach ($result['runs'] as $i => $run) {
                if (! in_array($run['verdict'] ?? null, [self::VERDICT_FORBIDDEN, self::VERDICT_OTHER], true)) {
                    continue;
                }
                $rows[] = [
                    mb_substr($result['id'], 0, 34),
                    (string) ($i + 1),
                    (string) $run['verdict'],
                    mb_substr((string) ($run['sku'] ?? ''), 0, 34),
                    ($run['score'] ?? null) === null ? '—' : $run['score'].'%',
                    (string) ($run['source'] ?? ''),
                    ($run['providers']['understand'] ?? '—').' / '.($run['providers']['rank'] ?? '—'),
                ];
            }
        }
        if ($rows === []) {
            return;
        }
        $this->line('<options=bold>Złe wybory (wszystkie przebiegi)</>');
        $this->table(['przypadek', 'przebieg', 'werdykt', 'karta', 'zapis', 'źródło', 'dostawca: zrozumienie / ocena'], $rows);
    }

    /** @param array{per_run: list<array<string, int>>, stable: int, unstable: list<string>, model_states: array<string, int>, by_rank_provider: array<string, array<string, int>>} $summary */
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
        if ($summary['by_rank_provider'] !== []) {
            $this->line('<options=bold>Werdykty wg dostawcy modelu, który ocenił pozycję (wszystkie przebiegi)</>');
            $providerRows = [];
            foreach ($summary['by_rank_provider'] as $provider => $counts) {
                $providerRows[] = [
                    (string) $provider,
                    (string) array_sum($counts),
                    (string) $counts[self::VERDICT_HIT],
                    (string) $counts[self::VERDICT_FORBIDDEN],
                    (string) $counts[self::VERDICT_OTHER],
                    (string) $counts[self::VERDICT_EMPTY],
                ];
            }
            $this->table(['dostawca', 'pozycji', 'trafne', 'zakazane', 'inne (złe)', 'puste'], $providerRows);
        }
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

    /**
     * Warunki przebiegu, które zmieniają porównanie z innym raportem: karty katalogu zmienione w trakcie (pobrane opisy,
     * ceny) i dostawcy modelu, którzy odpowiadali, razem z poluzowaniem przypiętego dostawcy po limicie zapytań.
     *
     * @param  list<array{id: string, query: string, expected_skus: list<string>, forbidden_skus: list<string>}>  $cases
     * @param  array<int, mixed>  $rows
     * @param  array{served: array<string, int>, relaxed_pins: int}  $providers
     * @return array{catalog_changed: int, catalog_changed_in_results: list<string>, providers: array<string, int>, relaxed_pins: int}
     */
    private function runConditions(int $run, CarbonInterface $startedAt, array $cases, array $rows, array $providers): array
    {
        $changed = array_values(array_unique(array_map(
            'strval',
            Product::query()->where('updated_at', '>=', $startedAt)->pluck('sku')->all(),
        )));
        $inResults = [];
        if ($changed !== []) {
            $seen = [];
            foreach ($cases as $i => $case) {
                foreach ($case['expected_skus'] as $sku) {
                    $seen[(string) $sku] = true;
                }
                foreach (is_array($rows[$i]['products'] ?? null) ? $rows[$i]['products'] : [] as $product) {
                    if (is_array($product) && is_string($product['sku'] ?? null)) {
                        $seen[$product['sku']] = true;
                    }
                }
            }
            $inResults = array_values(array_filter($changed, static fn (string $sku): bool => isset($seen[$sku])));
            $this->warn(sprintf(
                'Uwaga: w trakcie przebiegu %d zmieniono karty katalogu: %d%s — ten przebieg porównuj z innymi raportami ostrożnie.',
                $run,
                count($changed),
                $inResults !== [] ? ' (w wynikach pomiaru: '.implode(', ', array_slice($inResults, 0, 8)).')' : '',
            ));
        }
        if ($providers['served'] !== [] || $providers['relaxed_pins'] > 0) {
            $parts = [];
            foreach ($providers['served'] as $name => $count) {
                $parts[] = $name.' '.$count;
            }
            $line = sprintf(
                'Dostawcy modelu w przebiegu %d: %s%s',
                $run,
                $parts !== [] ? implode(' · ', $parts) : 'brak danych',
                $providers['relaxed_pins'] > 0 ? ' · poluzowane przypięcie dostawcy: '.$providers['relaxed_pins'] : '',
            );
            if (count($providers['served']) > 1 || $providers['relaxed_pins'] > 0) {
                $this->warn($line);
            } else {
                $this->line($line);
            }
        }

        return [
            'catalog_changed' => count($changed),
            'catalog_changed_in_results' => $inResults,
            'providers' => $providers['served'],
            'relaxed_pins' => $providers['relaxed_pins'],
        ];
    }

    /**
     * Wynik wyszukiwania pozycji w kształcie, który czyta decyzja przetargu (debugPick → mapAiSearchRows):
     * stan modelu i wiersze z oceną i źródłem. Wystarcza do odtworzenia decyzji bieżącym kodem bez modelu.
     *
     * @param  array<string, mixed>  $row
     * @return array{model_state: ?string, model_providers: array{understand: ?string, rank: ?string}, external_hint: null, products: list<array<string, mixed>>}
     */
    private function recordedSearch(array $row): array
    {
        $products = [];
        $all = is_array($row['products'] ?? null) ? array_values($row['products']) : [];
        // Pierwsze 40 wierszy i każdy dalszy wiersz oceniony przez model. Raport 20260914_161701 poz. 1: 40 wierszy listy
        // zapasowej (48) i nie było widać, czy model w ogóle ocenił kartę 11202000.
        $kept = array_slice($all, 0, 40);
        foreach (array_slice($all, 40) as $extra) {
            if (is_array($extra) && ! in_array($extra['ai_match_source'] ?? null, [ProductAiSearchService::MATCH_SOURCE_CATALOG, ProductAiSearchService::MATCH_SOURCE_RULE], true)) {
                $kept[] = $extra;
            }
        }
        foreach ($kept as $product) {
            if (! is_array($product)) {
                continue;
            }
            $products[] = [
                'id' => (int) ($product['id'] ?? 0),
                'sku' => (string) ($product['sku'] ?? ''),
                'name' => (string) ($product['name'] ?? ''),
                'ai_match_percent' => (int) ($product['ai_match_percent'] ?? 0),
                'ai_match_reason' => is_string($product['ai_match_reason'] ?? null) ? $product['ai_match_reason'] : null,
                'ai_match_source' => is_string($product['ai_match_source'] ?? null) ? $product['ai_match_source'] : null,
            ];
        }

        return [
            'model_state' => is_string($row['model_state'] ?? null) ? $row['model_state'] : null,
            'model_providers' => $this->rowProviders($row),
            'external_hint' => null,
            'products' => $products,
        ];
    }

    /**
     * @return array<string, array{runs: list<array<string, mixed>>}>|null
     */
    private function loadReplay(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("Nie ma raportu do odtworzenia: {$path}");

            return null;
        }
        $report = json_decode((string) file_get_contents($path), true);
        $out = [];
        foreach (is_array($report['cases'] ?? null) ? $report['cases'] : [] as $case) {
            if (is_array($case) && is_string($case['id'] ?? null)) {
                $out[$case['id']] = ['runs' => is_array($case['runs'] ?? null) ? array_values($case['runs']) : []];
            }
        }
        $withSearch = 0;
        foreach ($out as $case) {
            foreach ($case['runs'] as $run) {
                $withSearch += is_array($run['search'] ?? null) ? 1 : 0;
            }
        }
        if ($withSearch === 0) {
            $this->error('Raport nie zawiera zapisanych wyników wyszukiwania (powstał przed zapisem „search”) — nie da się go odtworzyć.');

            return null;
        }

        return $out;
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
