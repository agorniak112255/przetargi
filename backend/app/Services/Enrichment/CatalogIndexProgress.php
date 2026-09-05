<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Cache;

/**
 * Log jednego sprawdzenia domeny — admin widzi kolejne kroki, zanim skończy się job.
 */
final class CatalogIndexProgress
{
    private const TTL_SECONDS = 7200;

    private const MAX_LINES = 80;

    /**
     * @return array{
     *     host: string,
     *     status: 'idle'|'queued'|'running'|'done'|'failed',
     *     started_at: string|null,
     *     finished_at: string|null,
     *     lines: list<array{at: string, text: string}>
     * }
     */
    public function snapshot(string $host): array
    {
        $host = $this->normalize($host);
        $raw = $host === '' ? null : Cache::get($this->key($host));

        return $this->normalizePayload($host, is_array($raw) ? $raw : null);
    }

    public function start(string $host, string $message = 'W kolejce — czekam na workera.'): void
    {
        $host = $this->normalize($host);
        if ($host === '') {
            return;
        }

        Cache::put($this->key($host), [
            'host' => $host,
            'status' => 'queued',
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'lines' => [$this->entry($message)],
        ], self::TTL_SECONDS);
    }

    public function markRunning(string $host, string $message = 'Start indeksowania.'): void
    {
        $this->append($host, $message, 'running', false);
    }

    public function line(string $host, string $message): void
    {
        $this->append($host, $message, null, false);
    }

    public function finish(string $host, string $message, bool $ok): void
    {
        $this->append($host, $message, $ok ? 'done' : 'failed', true);
    }

    private function append(string $host, string $message, ?string $status, bool $finished): void
    {
        $host = $this->normalize($host);
        if ($host === '') {
            return;
        }

        $current = $this->snapshot($host);
        $lines = $current['lines'];
        $lines[] = $this->entry($message);
        if (count($lines) > self::MAX_LINES) {
            $lines = array_values(array_slice($lines, -self::MAX_LINES));
        }

        Cache::put($this->key($host), [
            'host' => $host,
            'status' => $status ?? ($current['status'] === 'idle' ? 'running' : $current['status']),
            'started_at' => $current['started_at'] ?? now()->toIso8601String(),
            'finished_at' => $finished ? now()->toIso8601String() : $current['finished_at'],
            'lines' => $lines,
        ], self::TTL_SECONDS);
    }

    /**
     * @return array{at: string, text: string}
     */
    private function entry(string $message): array
    {
        return [
            'at' => now()->toIso8601String(),
            'text' => mb_substr(trim($message), 0, 400),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{
     *     host: string,
     *     status: 'idle'|'queued'|'running'|'done'|'failed',
     *     started_at: string|null,
     *     finished_at: string|null,
     *     lines: list<array{at: string, text: string}>
     * }
     */
    private function normalizePayload(string $host, ?array $raw): array
    {
        $status = is_string($raw['status'] ?? null) ? $raw['status'] : 'idle';
        if (! in_array($status, ['idle', 'queued', 'running', 'done', 'failed'], true)) {
            $status = 'idle';
        }

        $lines = [];
        foreach ((array) ($raw['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $text = trim((string) ($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $lines[] = [
                'at' => is_string($line['at'] ?? null) ? $line['at'] : now()->toIso8601String(),
                'text' => $text,
            ];
        }

        return [
            'host' => $host,
            'status' => $raw === null ? 'idle' : $status,
            'started_at' => is_string($raw['started_at'] ?? null) ? $raw['started_at'] : null,
            'finished_at' => is_string($raw['finished_at'] ?? null) ? $raw['finished_at'] : null,
            'lines' => $lines,
        ];
    }

    private function key(string $host): string
    {
        return 'catalog-index-progress:'.$host;
    }

    private function normalize(string $host): string
    {
        $host = mb_strtolower(trim(preg_replace('#^https?://#i', '', $host) ?? $host));
        $host = trim(explode('/', $host)[0] ?? $host);

        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
