<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AiServedProviderTally;
use App\Services\Search\SearchEvalParallel;
use App\Services\Search\SearchEvalRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Proces pomocniczy `search:eval --parallel`: liczy wskazane przypadki golden setu tą samą drogą co `search:eval`
 * (SearchEvalRunner::evaluate), zgłasza każdy skończony przypadek i oddaje wiersze raportu na standardowe wyjście.
 * Nie do uruchamiania ręcznie.
 */
final class SearchEvalWorkerCommand extends Command
{
    protected $signature = 'search:eval-worker
        {--file= : Plik golden setu}
        {--k=10 : Ile pozycji wyniku liczy się do metryk rankingu}
        {--limit= : Limit wyników wyszukiwarki}
        {--ids-file= : Plik JSON z listą id przypadków do policzenia}
        {--no-vector : Ablacja — wyłącz Qdrant tylko w tym procesie}';

    protected $description = 'Proces pomocniczy search:eval --parallel (nie uruchamiać ręcznie)';

    protected $hidden = true;

    public function handle(SearchEvalRunner $runner): int
    {
        if ($this->option('no-vector')) {
            config(['ai.vector_eval_disabled' => true]);
        }
        try {
            $ids = json_decode((string) file_get_contents((string) $this->option('ids-file')), true, 512, JSON_THROW_ON_ERROR);
            $cases = $runner->loadCases((string) $this->option('file'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $byId = [];
        foreach ($cases as $case) {
            $byId[$case['id']] = $case;
        }

        $tally = app(AiServedProviderTally::class);
        $tally->reset();
        $k = max(1, (int) $this->option('k'));
        $limit = (int) $this->option('limit');
        $rows = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            $case = $byId[(string) $id] ?? null;
            if ($case === null) {
                continue;
            }
            $rows[] = $runner->evaluate($case, $k, $limit);
            $this->line(SearchEvalParallel::DONE.$case['id']);
        }

        $this->line(SearchEvalParallel::RESULT.base64_encode((string) json_encode(
            ['rows' => $rows, 'profile_fallbacks' => $tally->profileFallbacks()],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        )));

        return self::SUCCESS;
    }
}
