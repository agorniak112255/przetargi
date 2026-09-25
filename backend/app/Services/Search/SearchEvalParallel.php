<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * `search:eval` w kilku procesach naraz. Każdy przypadek idzie tą samą drogą co dotąd (pojedyncze wyszukiwanie jak
 * w oknie aplikacji, SearchEvalRunner::evaluate), tylko przypadki są rozdzielone między procesy `search:eval-worker`.
 * Jeden proces PHP nie puści kilku wyszukiwań naraz, a fala (searchMany, jak „Dopasuj wszystkie”) to inna droga
 * niż ta, którą mierzy golden set — dlatego procesy, a nie fala.
 *
 * Proces zgłasza każdy skończony przypadek wierszem DONE (pasek postępu), a na końcu wypisuje wiersz RESULT z wierszami
 * raportu i liczbą zejść z profilu na konfigurację główną (base64 z JSON).
 */
final class SearchEvalParallel
{
    public const DONE = 'search-eval-done:';

    public const RESULT = 'search-eval-result:';

    /** Górna granica procesów — każdy to osobna aplikacja w pamięci i osobne połączenie z bazą. */
    public const MAX_WORKERS = 10;

    public function __construct(
        private readonly SearchEvalRunner $runner,
    ) {}

    /**
     * Przypadki po kolei do procesów (0, 1, 2, 0, 1, 2…) — długie przypadki nie trafiają wszystkie do jednego procesu.
     *
     * @template T of array
     *
     * @param  list<T>  $cases
     * @return list<list<T>>
     */
    public function shards(array $cases, int $workers): array
    {
        $workers = max(1, min($workers, count($cases)));
        $shards = array_fill(0, $workers, []);
        foreach (array_values($cases) as $i => $case) {
            $shards[$i % $workers][] = $case;
        }

        return array_values(array_filter($shards, static fn (array $shard): bool => $shard !== []));
    }

    /**
     * @param  list<array{id: string, query: string, expected_skus: list<string>}>  $cases
     * @param  callable(string): void  $onDone  id skończonego przypadku
     * @return array{rows: list<array<string, mixed>>, profile_fallbacks: int, failures: list<string>}
     */
    public function run(array $cases, string $file, int $k, int $limit, bool $noVector, int $workers, callable $onDone): array
    {
        $shards = $this->shards($cases, min($workers, self::MAX_WORKERS));
        $idsFiles = [];
        foreach ($shards as $i => $shard) {
            $idsFiles[$i] = (string) tempnam(sys_get_temp_dir(), 'search-eval-ids-');
            file_put_contents($idsFiles[$i], json_encode(array_column($shard, 'id'), JSON_UNESCAPED_UNICODE));
        }

        $buffers = [];
        try {
            $pool = Process::pool(function (Pool $pool) use ($shards, $idsFiles, $file, $k, $limit, $noVector): void {
                foreach (array_keys($shards) as $i) {
                    $pool->as((string) $i)->forever()->command([
                        PHP_BINARY,
                        '-d',
                        'memory_limit='.(string) ini_get('memory_limit'),
                        base_path('artisan'),
                        'search:eval-worker',
                        '--file='.$file,
                        '--k='.$k,
                        '--limit='.$limit,
                        '--ids-file='.$idsFiles[$i],
                        ...($noVector ? ['--no-vector'] : []),
                    ]);
                }
            })->start(function (string $type, string $output, string|int $key) use (&$buffers, $onDone): void {
                if ($type !== 'out') {
                    return;
                }
                $buffers[$key] = ($buffers[$key] ?? '').$output;
                while (($newline = strpos($buffers[$key], "\n")) !== false) {
                    $line = trim(substr($buffers[$key], 0, $newline));
                    $buffers[$key] = substr($buffers[$key], $newline + 1);
                    if (str_starts_with($line, self::DONE)) {
                        $onDone(substr($line, strlen(self::DONE)));
                    }
                }
            });
            while ($pool->running()->isNotEmpty()) {
                usleep(200_000);
            }
            $results = $pool->wait();
        } finally {
            foreach ($idsFiles as $path) {
                @unlink($path);
            }
        }

        $rowsById = [];
        $fallbacks = 0;
        $failures = [];
        foreach (array_keys($shards) as $i) {
            $result = $results[(string) $i] ?? null;
            $payload = $result === null ? null : $this->resultPayload($result->output());
            if ($result === null || ! $result->successful() || $payload === null) {
                $failures[] = sprintf(
                    'proces %d (exit %s): %s',
                    $i + 1,
                    $result?->exitCode() ?? '?',
                    mb_substr(trim((string) $result?->errorOutput()), -300) ?: 'brak wyniku',
                );

                continue;
            }
            $fallbacks += (int) ($payload['profile_fallbacks'] ?? 0);
            foreach (is_array($payload['rows'] ?? null) ? $payload['rows'] : [] as $row) {
                if (is_array($row) && is_string($row['id'] ?? null)) {
                    $rowsById[$row['id']] = $row;
                }
            }
        }

        // Kolejność przypadków jak w golden secie; przypadek bez wiersza (proces padł) liczy się jako błąd.
        $rows = [];
        foreach ($cases as $case) {
            $rows[] = $rowsById[$case['id']]
                ?? $this->runner->errorRow($case, 'Proces równoległy nie oddał wyniku: '.($failures[0] ?? 'nieznany błąd'));
        }

        return ['rows' => $rows, 'profile_fallbacks' => $fallbacks, 'failures' => $failures];
    }

    /** @return array{rows?: mixed, profile_fallbacks?: mixed}|null */
    private function resultPayload(string $output): ?array
    {
        foreach (array_reverse(preg_split('/\R/', $output) ?: []) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, self::RESULT)) {
                continue;
            }
            try {
                $decoded = json_decode((string) base64_decode(substr($line, strlen(self::RESULT)), true), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return null;
            }

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
