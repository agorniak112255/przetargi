<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\CatalogHostScore;
use Illuminate\Database\QueryException;

/**
 * Kolejność domen przy szukaniu kart. Liczba zapytań site: jest ograniczona, więc
 * pierwsze idą sklepy, które już oddały kartę, a na koniec te, które nigdy nic nie dały.
 * Bez zebranych danych kolejność z konfiguracji zostaje nietknięta.
 */
final class CatalogHostRanking
{
    /**
     * @param  list<string>  $hosts
     * @return list<string>
     */
    public function order(array $hosts): array
    {
        $hosts = array_values($hosts);
        if (count($hosts) < 2) {
            return $hosts;
        }

        $scores = $this->scoresFor($hosts);
        if ($scores === []) {
            return $hosts;
        }

        // sortowanie stabilne — pozycja z konfiguracji rozstrzyga remisy
        $rows = [];
        foreach ($hosts as $position => $host) {
            $score = $scores[$this->bareHost($host)] ?? null;
            $rows[] = [
                'host' => $host,
                'position' => $position,
                'group' => $this->groupFor($score),
                'hits' => (int) ($score['hits'] ?? 0),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['group'], -$a['hits'], $a['position']]
                <=> [$b['group'], -$b['hits'], $b['position']];
        });

        return array_column($rows, 'host');
    }

    /** Domena oddała potwierdzoną kartę — następnym razem pytamy ją wcześniej. */
    public function recordHit(string $url): void
    {
        $this->bump($url, 'hits');
    }

    /** Domena była pytana i nic nie wniosła. */
    public function recordMiss(string $url): void
    {
        $this->bump($url, 'misses');
    }

    private function bump(string $url, string $column): void
    {
        $host = $this->bareHost($url);
        if ($host === '') {
            return;
        }

        try {
            $row = CatalogHostScore::query()->firstOrCreate(['host' => $host]);
            $row->increment($column);
            if ($column === 'hits') {
                $row->forceFill(['last_hit_at' => now()])->save();
            }
        } catch (QueryException) {
            // brak tabeli (stara instalacja) nie może przerwać wzbogacania
        }
    }

    /**
     * 0 — domena z trafieniami, 1 — bez danych, 2 — same pudła.
     *
     * @param  array{hits: int, misses: int}|null  $score
     */
    private function groupFor(?array $score): int
    {
        if ($score === null) {
            return 1;
        }
        if ($score['hits'] > 0) {
            return 0;
        }

        return $score['misses'] > 0 ? 2 : 1;
    }

    /**
     * @param  list<string>  $hosts
     * @return array<string, array{hits: int, misses: int}>
     */
    private function scoresFor(array $hosts): array
    {
        $bare = [];
        foreach ($hosts as $host) {
            $host = $this->bareHost($host);
            if ($host !== '') {
                $bare[$host] = true;
            }
        }
        if ($bare === []) {
            return [];
        }

        try {
            $rows = CatalogHostScore::query()
                ->whereIn('host', array_keys($bare))
                ->get(['host', 'hits', 'misses']);
        } catch (QueryException) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->host] = [
                'hits' => (int) $row->hits,
                'misses' => (int) $row->misses,
            ];
        }

        return $out;
    }

    private function bareHost(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '/')) {
            $value = (string) (parse_url(
                str_contains($value, '://') ? $value : 'https://'.$value,
                PHP_URL_HOST
            ) ?? '');
        }

        return ltrim($value, '.');
    }
}
