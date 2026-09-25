<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AiServedProviderTally;
use App\Services\Ai\AiSettingsService;
use App\Services\ProductAiSearchService;
use App\Services\Search\SearchEvalParallel;
use App\Services\Search\SearchEvalRunner;
use App\Services\Vector\ProductVectorSearch;
use App\Support\SearchEvalMetrics;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pomiar jakości wyszukiwania AI na golden secie. Uruchamiany przed i po każdej
 * zmianie retrievalu, wag RRF albo promptu rankingu — inaczej „poprawiliśmy
 * wyszukiwarkę” jest zdaniem bez pokrycia.
 *
 * Uwaga: każdy przypadek to prawdziwe wywołanie modelu (koszt + czas), więc do
 * szybkiej iteracji używaj --filter.
 */
class SearchEvalCommand extends Command
{
    protected $signature = 'search:eval
        {--file= : Plik golden setu (domyślnie resources/search-eval/golden.json)}
        {--k=10 : Ile pozycji wyniku liczy się do metryk rankingu}
        {--limit= : Limit wyników wyszukiwarki (domyślnie limit katalogowy z Ustawień AI)}
        {--filter= : Uruchom tylko przypadki, których id lub zapytanie zawiera ten tekst}
        {--worst=10 : Ile najgorszych przypadków wypisać}
        {--save : Zapisz raport JSON w storage/app/search-eval/reports}
        {--baseline= : Raport do porównania (ścieżka JSON z poprzedniego przebiegu)}
        {--no-vector : Ablacja — wyłącz Qdrant tylko w tym procesie, bez zapisu Ustawień AI}
        {--parallel= : Ile przypadków liczyć naraz w osobnych procesach (domyślnie równoległość z Ustawień AI, 1 = po kolei)}';

    protected $description = 'Mierzy jakość wyszukiwania AI: recall retrievalu i ranking (recall@k, nDCG@k, MRR)';

    public function handle(
        SearchEvalRunner $runner,
        ProductAiSearchService $search,
        ProductVectorSearch $vectorSearch,
        AiSettingsService $settings,
        SearchEvalParallel $parallelRunner,
    ): int {
        $file = (string) ($this->option('file') ?: base_path('resources/search-eval/golden.json'));
        $k = max(1, (int) $this->option('k'));
        $limit = (int) ($this->option('limit') ?: ProductAiSearchService::CATALOG_LIMIT);
        $noVector = (bool) $this->option('no-vector');
        if ($noVector) {
            config(['ai.vector_eval_disabled' => true]);
        }

        try {
            $cases = $runner->loadCases($file);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $filter = trim((string) $this->option('filter'));
        if ($filter !== '') {
            $cases = array_values(array_filter($cases, static function (array $case) use ($filter): bool {
                return mb_stripos($case['id'], $filter) !== false
                    || mb_stripos($case['query'], $filter) !== false;
            }));
            if ($cases === []) {
                $this->warn("Żaden przypadek nie pasuje do --filter={$filter}.");

                return self::SUCCESS;
            }
        }

        // Równolegle tylko osobnymi procesami (każdy przypadek dalej idzie drogą pojedynczego wyszukiwania); id przypadków
        // muszą być jednoznaczne, bo po nich wiersze wracają na swoje miejsce.
        $option = $this->option('parallel');
        $parallel = $option === null || $option === '' ? $settings->matchConcurrency() : (int) $option;
        $parallel = max(1, min($parallel, count($cases), SearchEvalParallel::MAX_WORKERS));
        if (count(array_unique(array_column($cases, 'id'))) !== count($cases)) {
            $parallel = 1;
        }

        $this->info(sprintf(
            'Golden set: %s · przypadków: %d · równolegle: %d · k=%d · limit=%d · prompt=%s · wektor=%s',
            $file,
            count($cases),
            $parallel,
            $k,
            $limit,
            ProductAiSearchService::RANK_PROMPT_VERSION,
            // Sama flaga mówiła tylko o ablacji: raport pisał „włączony” także wtedy, gdy wektor
            // jest wyłączony w Ustawieniach AI — czyli pomiar deklarował konfigurację, której nie miał.
            $noVector
                ? 'wyłączony (ablacja)'
                : ($vectorSearch->enabled() ? 'włączony' : 'wyłączony w Ustawieniach AI'),
        ));

        $rows = [];
        // Przebieg musi wiedzieć, czy naprawdę szedł na tym modelu, którym się deklaruje. 22.09.2026 padł
        // jeden z dwóch węzłów vLLM, aplikacja po cichu zeszła na konfigurację główną w chmurze i raport
        // porównywał podmianę modelu, a nie zmianę w wyszukiwarce.
        $tally = app(AiServedProviderTally::class);
        $tally->reset();

        $bar = $this->output->createProgressBar(count($cases));
        $bar->start();
        $failures = [];
        if ($parallel > 1) {
            $outcome = $parallelRunner->run($cases, $file, $k, $limit, $noVector, $parallel, static function () use ($bar): void {
                $bar->advance();
            });
            $rows = $outcome['rows'];
            $fallbacks = $outcome['profile_fallbacks'];
            $failures = $outcome['failures'];
        } else {
            foreach ($cases as $case) {
                $rows[] = $runner->evaluate($case, $k, $limit);
                $bar->advance();
            }
            $fallbacks = $tally->profileFallbacks();
        }
        $bar->finish();
        $this->newLine(2);
        foreach ($failures as $failure) {
            $this->error('Proces równoległy padł — '.$failure);
        }

        $this->renderCases($rows, $k);
        $summary = $runner->summarize($rows);
        $this->renderSummary($summary, $k, $runner->matchMinScore());
        $this->renderProfileFallbacks($fallbacks);
        $this->renderProblems($rows);
        $this->renderWorst($rows, max(0, (int) $this->option('worst')));

        $report = [
            'generated_at' => now()->toIso8601String(),
            'file' => $file,
            'k' => $k,
            'limit' => $limit,
            'prompt_version' => ProductAiSearchService::RANK_PROMPT_VERSION,
            'vector_disabled' => $noVector,
            // Przy kilku procesach naraz czas przypadku (duration_ms) obejmuje czekanie na model — nie porównuj go 1:1
            // z przebiegiem po kolei.
            'parallel' => $parallel,
            // Próg zapisu, względem którego forbidden_shown oddziela ostrzeżenia od naruszeń blokujących.
            'match_min_score' => $runner->matchMinScore(),
            // Podpis list golden setu: inny podpis w raporcie bazowym = zmiana miary (wzorcowe, zakazane, równoważniki),
            // nie wyszukiwarki — porównanie ostrzega o tym także wtedy, gdy obie strony mają pola z 25.09.2026.
            'golden_signature' => $this->goldenSignature($cases),
            'profile_fallbacks' => $fallbacks,
            'summary' => $summary,
            'cases' => $rows,
        ];

        $baseline = trim((string) $this->option('baseline'));
        if ($baseline !== '') {
            $withAcceptable = array_filter($cases, static fn (array $case): bool => $case['acceptable_skus'] !== []) !== [];
            $this->renderBaseline($baseline, $summary, $withAcceptable, $report['golden_signature']);
        }

        if ($this->option('save')) {
            $this->saveReport($report);
        }

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Ile zapytań obsłużyła konfiguracja główna zamiast profilu przypisanego do wyszukiwarki.
     * Każde takie zapytanie to inny model w tym samym przebiegu, więc porównanie z raportem bazowym
     * przestaje mierzyć wyszukiwarkę.
     */
    private function renderProfileFallbacks(int $fallbacks): void
    {
        if ($fallbacks === 0) {
            return;
        }

        $this->warn('UWAGA: '.$fallbacks.' zapytań zeszło z profilu na konfigurację główną (profil nie odpowiadał).');
        $this->line('Przebieg mieszał modele — nie porównuj go z innym raportem. Napraw dostawcę i powtórz pomiar.');
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderCases(array $rows, int $k): void
    {
        $this->table(
            ['przypadek', 'recall retr.', "recall@{$k}", "nDCG@{$k}", 'MRR', 'zwr.', 'pula', 'ms'],
            array_map(static function (array $row): array {
                return [
                    mb_substr((string) $row['id'], 0, 34),
                    $row['error'] !== null ? '—' : number_format((float) $row['retrieval_recall'], 2),
                    $row['error'] !== null ? '—' : number_format((float) $row['recall_at_k'], 2),
                    $row['error'] !== null ? '—' : number_format((float) $row['ndcg_at_k'], 2),
                    $row['error'] !== null ? '—' : number_format((float) $row['mrr'], 2),
                    (string) $row['returned'],
                    (string) $row['candidates'],
                    (string) $row['duration_ms'],
                ];
            }, $rows),
        );
    }

    /** @param array<string, float|int> $summary */
    private function renderSummary(array $summary, int $k, int $minScore): void
    {
        $this->line('<options=bold>Podsumowanie</>');
        $this->table(['metryka', 'wartość', 'co mówi'], [
            ['recall retrievalu', number_format((float) $summary['retrieval_recall'], 3), 'sufit pipeline\'u — ile dobrych kart w ogóle weszło do puli'],
            ["recall@{$k}", number_format((float) $summary['recall_at_k'], 3), 'ile z nich przetrwało ranking i bramki'],
            ["precision@{$k}", number_format((float) $summary['precision_at_k'], 3), 'dzielone przez k — tylko do porównań między przebiegami'],
            ["nDCG@{$k}", number_format((float) $summary['ndcg_at_k'], 3), 'jakość kolejności'],
            ['MRR', number_format((float) $summary['mrr'], 3), 'jak wysoko pierwsze trafienie'],
            ['naruszenia', (string) $summary['violations'], 'zwrócone SKU z listy zakazanych (ścisłe, z propozycjami pod właściwą kartą)'],
            ['naruszenia blokujące', (string) $summary['violations_blocking'], 'zakazane SKU, które automat przetargu mógłby wybrać — bramka porównań'],
            ['zakazane pod właściwą kartą', (string) $summary['forbidden_shown'], "propozycja poniżej {$minScore}% pod kartą wzorcową/równoważną ≥ {$minScore}% — ostrzeżenie (np. inny kolor)"],
            ["równoważniki w top-{$k}", (string) $summary['acceptable_hits'], 'karty z acceptable_skus (inny producent/model) — liczą się w precision, nDCG i MRR, nie w recall'],
            ['błędy', (string) $summary['errors'], 'przypadki, które się wywróciły'],
            ['średni czas', $summary['avg_ms'].' ms', ''],
        ]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderProblems(array $rows): void
    {
        $unknown = [];
        $blocking = [];
        $shown = [];
        $errors = [];
        foreach ($rows as $row) {
            foreach ($row['unknown_skus'] as $sku) {
                $unknown[] = $row['id'].': '.$sku;
            }
            // Zakazane spoza katalogu (np. po łączeniu kart) po cichu przestają cokolwiek pilnować.
            foreach ($row['unknown_forbidden_skus'] ?? [] as $sku) {
                $unknown[] = $row['id'].': '.$sku.' (zakazane)';
            }
            foreach ($row['unknown_acceptable_skus'] ?? [] as $sku) {
                $unknown[] = $row['id'].': '.$sku.' (równoważnik)';
            }
            foreach ($row['violations_blocking'] ?? $row['violations'] as $sku) {
                $blocking[] = $row['id'].': '.$sku;
            }
            foreach ($row['forbidden_shown'] ?? [] as $sku) {
                $shown[] = $row['id'].': '.$sku.$this->percentLabel($row, (string) $sku);
            }
            if ($row['error'] !== null) {
                $errors[] = $row['id'].': '.$row['error'];
            }
        }

        if ($unknown !== []) {
            $this->warn('SKU z golden setu, których nie ma w katalogu (popraw golden set, nie wyszukiwarkę):');
            foreach ($unknown as $line) {
                $this->line('  - '.$line);
            }
        }
        if ($blocking !== []) {
            $this->warn('Zwrócone SKU z listy zakazanych — blokujące (automat przetargu mógłby je wybrać):');
            foreach ($blocking as $line) {
                $this->line('  - '.$line);
            }
        }
        if ($shown !== []) {
            $this->line('Zwrócone SKU z listy zakazanych — propozycje poniżej progu zapisu pod właściwą kartą (ostrzeżenie):');
            foreach ($shown as $line) {
                $this->line('  - '.$line);
            }
        }
        if ($errors !== []) {
            $this->error('Przypadki zakończone błędem:');
            foreach ($errors as $line) {
                $this->line('  - '.$line);
            }
        }
    }

    /**
     * „ (60%)” z listy wyniku przypadku — przy ostrzeżeniu widać, jak nisko stoi propozycja.
     *
     * @param  array<string, mixed>  $row
     */
    private function percentLabel(array $row, string $sku): string
    {
        foreach ($row['returned_top'] ?? [] as $top) {
            if (SearchEvalMetrics::normalize((string) ($top['sku'] ?? '')) === $sku && is_int($top['percent'] ?? null)) {
                return ' ('.$top['percent'].'%)';
            }
        }

        return '';
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderWorst(array $rows, int $count): void
    {
        if ($count === 0) {
            return;
        }

        $worst = $rows;
        usort($worst, static function (array $a, array $b): int {
            return [(float) $a['ndcg_at_k'], (float) $a['retrieval_recall']]
                <=> [(float) $b['ndcg_at_k'], (float) $b['retrieval_recall']];
        });
        $worst = array_slice(array_filter($worst, static fn (array $row): bool => (float) $row['ndcg_at_k'] < 1.0), 0, $count);
        if ($worst === []) {
            return;
        }

        $this->line('<options=bold>Najsłabsze przypadki</>');
        foreach ($worst as $row) {
            // Retrieval ma dobrą kartę, a wynik jej nie ma → problem w rankingu, nie w recallu.
            $where = (float) $row['retrieval_recall'] > (float) $row['recall_at_k'] ? 'ranking' : 'retrieval';
            $this->line(sprintf(
                '  %s [%s] recall_retr=%.2f recall@k=%.2f nDCG=%.2f · brakuje: %s%s',
                $row['id'],
                $where,
                (float) $row['retrieval_recall'],
                (float) $row['recall_at_k'],
                (float) $row['ndcg_at_k'],
                $row['missing_skus'] === [] ? '—' : implode(', ', array_slice($row['missing_skus'], 0, 5)),
                ($row['acceptable_hits'] ?? []) === []
                    ? ''
                    : ' · równoważniki (inny producent/model): '.implode(', ', array_slice($row['acceptable_hits'], 0, 5)),
            ));
            $this->line('      '.mb_substr((string) $row['query'], 0, 110));
        }
    }

    /**
     * @param  array<string, float|int>  $summary
     * @param  bool  $withAcceptable  golden set tego przebiegu ma choć jedną listę acceptable_skus
     */
    private function renderBaseline(string $path, array $summary, bool $withAcceptable, string $goldenSignature = ''): void
    {
        if (! is_file($path)) {
            $this->warn("Nie ma raportu bazowego: {$path}");

            return;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        $base = is_array($raw['summary'] ?? null) ? $raw['summary'] : null;
        if ($base === null) {
            $this->warn("Raport bazowy nie ma sekcji `summary`: {$path}");

            return;
        }

        $this->line('<options=bold>Zmiana względem '.basename($path).'</>');
        $lines = [];
        foreach (['retrieval_recall', 'recall_at_k', 'precision_at_k', 'ndcg_at_k', 'mrr'] as $key) {
            $before = (float) ($base[$key] ?? 0.0);
            $after = (float) ($summary[$key] ?? 0.0);
            $delta = $after - $before;
            $lines[] = [
                $key,
                number_format($before, 3),
                number_format($after, 3),
                sprintf('%+.3f', $delta),
            ];
        }
        $lines[] = [
            'violations',
            (string) ($base['violations'] ?? 0),
            (string) $summary['violations'],
            sprintf('%+d', $summary['violations'] - (int) ($base['violations'] ?? 0)),
        ];
        // Pola z 25.09.2026 — raport bazowy sprzed nich nie ma czym się porównać, więc „—” zamiast zera.
        foreach (['violations_blocking', 'forbidden_shown', 'acceptable_hits'] as $key) {
            $known = array_key_exists($key, $base);
            $lines[] = [
                $key,
                $known ? (string) (int) $base[$key] : '—',
                (string) $summary[$key],
                $known ? sprintf('%+d', $summary[$key] - (int) $base[$key]) : '—',
            ];
        }
        $this->table(['metryka', 'przed', 'po', 'delta'], $lines);
        $baseSignature = is_string($raw['golden_signature'] ?? null) ? $raw['golden_signature'] : null;
        if ($baseSignature !== null && $goldenSignature !== '' && $baseSignature !== $goldenSignature) {
            $this->warn('Golden set różni się od użytego w raporcie bazowym (wzorcowe, zakazane albo równoważniki) — część różnic to zmiana miary, nie wyszukiwarki.');
        } elseif ($baseSignature === null && $withAcceptable) {
            $this->warn('Raport bazowy powstał bez acceptable_skus — precision, nDCG i MRR liczą teraz także równoważniki (zmiana miary, nie wyszukiwarki).');
        }
    }

    /**
     * Skrót list golden setu, od których zależą metryki — kolejność przypadków i SKU bez znaczenia.
     *
     * @param  list<array<string, mixed>>  $cases
     */
    private function goldenSignature(array $cases): string
    {
        $lists = [];
        foreach ($cases as $case) {
            $entry = [];
            foreach (['expected_skus', 'forbidden_skus', 'acceptable_skus'] as $key) {
                $skus = SearchEvalMetrics::normalizeAll(is_array($case[$key] ?? null) ? $case[$key] : []);
                sort($skus);
                $entry[$key] = $skus;
            }
            $lists[(string) ($case['id'] ?? $case['query'] ?? '')] = $entry;
        }
        ksort($lists);

        return substr(sha1((string) json_encode($lists)), 0, 16);
    }

    /** @param array<string, mixed> $report */
    private function saveReport(array $report): void
    {
        $dir = storage_path('app/search-eval/reports');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error('Nie udało się utworzyć katalogu raportów: '.$dir);

            return;
        }
        $path = $dir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'.json';
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info('Raport: '.$path);
        $this->line('Porównanie następnego przebiegu: php artisan search:eval --baseline="'.$path.'"');
    }
}
