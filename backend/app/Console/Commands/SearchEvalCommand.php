<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProductAiSearchService;
use App\Services\Search\SearchEvalRunner;
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
        {--baseline= : Raport do porównania (ścieżka JSON z poprzedniego przebiegu)}';

    protected $description = 'Mierzy jakość wyszukiwania AI: recall retrievalu i ranking (recall@k, nDCG@k, MRR)';

    public function handle(SearchEvalRunner $runner, ProductAiSearchService $search): int
    {
        $file = (string) ($this->option('file') ?: base_path('resources/search-eval/golden.json'));
        $k = max(1, (int) $this->option('k'));
        $limit = (int) ($this->option('limit') ?: ProductAiSearchService::CATALOG_LIMIT);

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

        $this->info(sprintf(
            'Golden set: %s · przypadków: %d · k=%d · limit=%d · prompt=%s',
            $file,
            count($cases),
            $k,
            $limit,
            ProductAiSearchService::RANK_PROMPT_VERSION,
        ));

        $rows = [];
        $bar = $this->output->createProgressBar(count($cases));
        $bar->start();
        foreach ($cases as $case) {
            $rows[] = $runner->evaluate($case, $k, $limit);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->renderCases($rows, $k);
        $summary = $runner->summarize($rows);
        $this->renderSummary($summary, $k);
        $this->renderProblems($rows);
        $this->renderWorst($rows, max(0, (int) $this->option('worst')));

        $report = [
            'generated_at' => now()->toIso8601String(),
            'file' => $file,
            'k' => $k,
            'limit' => $limit,
            'prompt_version' => ProductAiSearchService::RANK_PROMPT_VERSION,
            'summary' => $summary,
            'cases' => $rows,
        ];

        $baseline = trim((string) $this->option('baseline'));
        if ($baseline !== '') {
            $this->renderBaseline($baseline, $summary);
        }

        if ($this->option('save')) {
            $this->saveReport($report);
        }

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
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
    private function renderSummary(array $summary, int $k): void
    {
        $this->line('<options=bold>Podsumowanie</>');
        $this->table(['metryka', 'wartość', 'co mówi'], [
            ['recall retrievalu', number_format((float) $summary['retrieval_recall'], 3), 'sufit pipeline\'u — ile dobrych kart w ogóle weszło do puli'],
            ["recall@{$k}", number_format((float) $summary['recall_at_k'], 3), 'ile z nich przetrwało ranking i bramki'],
            ["precision@{$k}", number_format((float) $summary['precision_at_k'], 3), 'dzielone przez k — tylko do porównań między przebiegami'],
            ["nDCG@{$k}", number_format((float) $summary['ndcg_at_k'], 3), 'jakość kolejności'],
            ['MRR', number_format((float) $summary['mrr'], 3), 'jak wysoko pierwsze trafienie'],
            ['naruszenia', (string) $summary['violations'], 'zwrócone SKU z listy zakazanych (fałszywe pozytywy)'],
            ['błędy', (string) $summary['errors'], 'przypadki, które się wywróciły'],
            ['średni czas', $summary['avg_ms'].' ms', ''],
        ]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderProblems(array $rows): void
    {
        $unknown = [];
        $violations = [];
        $errors = [];
        foreach ($rows as $row) {
            foreach ($row['unknown_skus'] as $sku) {
                $unknown[] = $row['id'].': '.$sku;
            }
            foreach ($row['violations'] as $sku) {
                $violations[] = $row['id'].': '.$sku;
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
        if ($violations !== []) {
            $this->warn('Zwrócone SKU z listy zakazanych:');
            foreach ($violations as $line) {
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
                '  %s [%s] recall_retr=%.2f recall@k=%.2f nDCG=%.2f · brakuje: %s',
                $row['id'],
                $where,
                (float) $row['retrieval_recall'],
                (float) $row['recall_at_k'],
                (float) $row['ndcg_at_k'],
                $row['missing_skus'] === [] ? '—' : implode(', ', array_slice($row['missing_skus'], 0, 5)),
            ));
            $this->line('      '.mb_substr((string) $row['query'], 0, 110));
        }
    }

    /** @param array<string, float|int> $summary */
    private function renderBaseline(string $path, array $summary): void
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
        $this->table(['metryka', 'przed', 'po', 'delta'], $lines);
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
