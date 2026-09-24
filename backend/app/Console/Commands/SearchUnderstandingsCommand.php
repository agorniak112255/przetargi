<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RequirementUnderstanding;
use App\Services\ProductAiSearchService;
use App\Services\Search\UnderstandingAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Zapisane zrozumienia wymagań do przejrzenia. Zrozumienie zapisuje się raz na zawsze, więc błąd modelu zostaje
 * na stałe (24.09.2026: „spodnie do pasa z polipropylenu” → „spodnie robocze”). Komenda pokazuje zapisy, w których
 * model zgubił słowo z wymagania albo dopisał własne, a wskazane przez człowieka kasuje — przy następnym
 * wyszukiwaniu wymaganie zostanie zrozumiane od nowa. Bez --apply niczego nie zmienia.
 */
final class SearchUnderstandingsCommand extends Command
{
    private const MAX_TOKENS_SHOWN = 8;

    protected $signature = 'search:understandings
                            {--all : Pokaż wszystkie zapisy, nie tylko podejrzane}
                            {--forget= : Numery zapisów do skasowania, po przecinku}
                            {--apply : Naprawdę skasuj (bez tego tylko podgląd)}';

    protected $description = 'Pokazuje podejrzane zapisane zrozumienia wymagań i kasuje wskazane, żeby model zrozumiał je od nowa';

    public function handle(UnderstandingAudit $audit): int
    {
        $forget = $this->option('forget');
        if (is_string($forget) && trim($forget) !== '') {
            return $this->forget($forget, $audit);
        }

        return $this->list($audit);
    }

    private function list(UnderstandingAudit $audit): int
    {
        $version = ProductAiSearchService::UNDERSTAND_PROMPT_VERSION;
        $showAll = (bool) $this->option('all');
        $checked = 0;
        $flagged = 0;
        $rows = [];

        foreach (RequirementUnderstanding::query()->where('prompt_version', $version)->lazyById() as $row) {
            $checked++;
            $result = $this->audit($audit, $row);
            $suspicious = $result['dropped'] !== [] || $result['added'] !== [];
            if ($suspicious) {
                $flagged++;
            }
            if ($suspicious || $showAll) {
                $rows[] = $this->tableRow($row, $result);
            }
        }

        $this->line("Wersja instrukcji: {$version}");
        if ($rows !== []) {
            $this->table(['id', 'wymaganie', 'needed', 'zgubione', 'dopisane', 'data'], $rows);
        }
        $this->info("Sprawdzone zapisy: {$checked}, podejrzane: {$flagged}.");
        $this->line('Podgląd wybranych zapisów: php artisan search:understandings --forget=1,5 '
            .'(skasowanie: dodaj --apply; wymaganie zostanie zrozumiane od nowa przy następnym wyszukiwaniu)');

        return self::SUCCESS;
    }

    private function forget(string $option, UnderstandingAudit $audit): int
    {
        $ids = [];
        foreach (explode(',', $option) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^[1-9]\d*$/', $part) !== 1) {
                $this->error("Niepoprawny numer zapisu: „{$part}”. Nic nie zostało skasowane.");

                return self::FAILURE;
            }
            $ids[(int) $part] = true;
        }
        $ids = array_keys($ids);

        $found = RequirementUnderstanding::query()->whereIn('id', $ids)->orderBy('id')->get();
        $missing = array_values(array_diff($ids, $found->pluck('id')->map(fn ($id): int => (int) $id)->all()));

        if ($found->isNotEmpty()) {
            $this->table(
                ['id', 'wersja', 'wymaganie', 'needed', 'zgubione', 'dopisane', 'data'],
                $found->map(function (RequirementUnderstanding $row) use ($audit): array {
                    $cells = $this->tableRow($row, $this->audit($audit, $row));

                    return [$cells[0], $row->prompt_version, ...array_slice($cells, 1)];
                })->all(),
            );
        }
        if ($missing !== []) {
            $this->warn('Nie znaleziono zapisów: '.implode(', ', $missing).'.');
        }
        if ($found->isEmpty()) {
            $this->line('Nic do skasowania.');

            return self::SUCCESS;
        }

        $foundIds = $found->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (! $this->option('apply')) {
            $this->line('Podgląd — nic nie zostało skasowane.');
            $this->line('Aby skasować te zapisy: php artisan search:understandings --forget='.implode(',', $foundIds).' --apply');

            return self::SUCCESS;
        }

        $deleted = RequirementUnderstanding::query()->whereIn('id', $foundIds)->delete();
        $this->info("Skasowano zapisy: {$deleted}. Przy następnym wyszukiwaniu te wymagania zostaną zrozumiane od nowa.");

        return self::SUCCESS;
    }

    /** @return array{dropped: list<string>, added: list<string>} */
    private function audit(UnderstandingAudit $audit, RequirementUnderstanding $row): array
    {
        $answer = $row->answer;

        return $audit->check((string) $row->requirement, is_array($answer) ? $answer : []);
    }

    /**
     * @param  array{dropped: list<string>, added: list<string>}  $result
     * @return list<string>
     */
    private function tableRow(RequirementUnderstanding $row, array $result): array
    {
        $answer = is_array($row->answer) ? $row->answer : [];
        $requirement = trim((string) preg_replace('/\s+/u', ' ', (string) $row->requirement));

        return [
            (string) $row->id,
            Str::limit($requirement, 70),
            is_string($answer['needed'] ?? null) ? $answer['needed'] : '',
            $this->shortList($result['dropped']),
            $this->shortList($result['added']),
            (string) $row->created_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * Długi opis z SIWZ gubi w zrozumieniu dziesiątki słów — w tabeli pierwsze, reszta jako liczba.
     *
     * @param  list<string>  $tokens
     */
    private function shortList(array $tokens): string
    {
        $shown = implode(', ', array_slice($tokens, 0, self::MAX_TOKENS_SHOWN));
        $rest = count($tokens) - self::MAX_TOKENS_SHOWN;

        return $rest > 0 ? "{$shown} (+{$rest})" : $shown;
    }
}
