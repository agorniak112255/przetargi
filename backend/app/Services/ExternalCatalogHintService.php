<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use App\Services\Enrichment\TavilyQuotaGuard;
use App\Support\PpeFilterType;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Podpowiedź spoza katalogu — nigdy nie tworzy produktu SUPON.
 */
final class ExternalCatalogHintService
{
    /** @var array<string, array{url: string, title: string}|null> */
    private array $cache = [];

    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly PpeFilterType $filterType = new PpeFilterType,
        private readonly DuckDuckGoHtmlSearch $duckDuckGo = new DuckDuckGoHtmlSearch,
    ) {}

    /**
     * @return array{url: string, title: string}|null
     */
    public function hint(string $requirement): ?array
    {
        $query = trim($requirement);
        if (mb_strlen($query) < 12) {
            return null;
        }
        $cacheKey = mb_strtolower(mb_substr($query, 0, 400));
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $search = $this->webQuery($query);

        if ($this->settings->usesFreeWebSearch()) {
            try {
                $hits = $this->duckDuckGo->search($search, 8);
            } catch (Throwable) {
                return $this->remember($cacheKey, null);
            }

            return $this->remember($cacheKey, $this->pickBestResult($hits, $query));
        }

        $cfg = $this->settings->resolve();
        $key = $cfg['tavily_api_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return $this->remember($cacheKey, null);
        }

        try {
            TavilyQuotaGuard::assertAllowed();
        } catch (Throwable) {
            return $this->remember($cacheKey, null);
        }

        try {
            $response = Http::acceptJson()
                ->timeout(12)
                ->connectTimeout(5)
                ->post('https://api.tavily.com/search', [
                    'api_key' => $key,
                    'query' => $search,
                    'search_depth' => 'basic',
                    'include_answer' => false,
                    'max_results' => 8,
                    'include_images' => false,
                ]);
        } catch (Throwable) {
            return $this->remember($cacheKey, null);
        }

        if (! $response->successful() || $response->status() === 429) {
            return $this->remember($cacheKey, null);
        }

        $rows = $response->json('results');
        if (! is_array($rows)) {
            return $this->remember($cacheKey, null);
        }

        return $this->remember($cacheKey, $this->pickBestResult($rows, $query));
    }

    /**
     * Strona produktu przed PDF-em / świadectwem / deklaracją.
     * Przy klasie EN 14387 odrzuca kartę bez wymaganych typów (A2B2E2K2 ≠ A2B2E2K2NO).
     *
     * @param  list<mixed>  $rows
     * @return array{url: string, title: string}|null
     */
    public function pickBestResult(array $rows, string $requirement = ''): ?array
    {
        $candidates = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || ! str_starts_with($url, 'http')) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? $url));
            $hay = $url.' '.$title;
            if (! $this->filterType->covers($requirement, $hay)) {
                continue;
            }
            $candidates[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'score' => $this->scoreResult($url, $title, $requirement),
            ];
        }
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $candidates[0];

        return [
            'url' => $best['url'],
            'title' => $best['title'],
        ];
    }

    private function webQuery(string $requirement): string
    {
        $bits = [];
        foreach ($this->filterType->compactCodes($requirement) as $code) {
            $bits[] = $code;
            $hyphen = $this->filterType->hyphenated($code);
            if (mb_strtolower($hyphen) !== $code) {
                $bits[] = $hyphen;
            }
        }
        $bits[] = $requirement;

        return mb_substr(implode(' ', array_unique($bits)), 0, 400);
    }

    private function scoreResult(string $url, string $title, string $requirement = ''): int
    {
        $hay = mb_strtolower($url.' '.$title);
        $score = 50;

        if ($this->looksLikePdf($url, $hay)) {
            $score -= 80;
        }
        if ($this->looksLikeCertificateDoc($hay)) {
            $score -= 50;
        }
        if ($this->looksLikeProductPage($url, $hay)) {
            $score += 40;
        }
        $score += $this->filterType->coverageScore($requirement, $url.' '.$title);

        return $score;
    }

    private function looksLikePdf(string $url, string $hay): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return str_ends_with($path, '.pdf')
            || str_contains($url, '.pdf?')
            || str_contains($hay, '/pdf/')
            || str_contains($hay, 'filetype=pdf');
    }

    private function looksLikeCertificateDoc(string $hay): bool
    {
        foreach ([
            'swiadectwo', 'świadectwo', 'dopuszczenia', 'dopuszczenie',
            'deklaracja zgodnosci', 'deklaracja zgodności', 'declaration of conformity',
            'karta charakterystyki', 'safety data sheet', 'msds',
        ] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeProductPage(string $url, string $hay): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if ($this->looksLikePdf($url, $hay)) {
            return false;
        }

        foreach (['/produkt', '/product', '/sklep', '/p/', 'karta produktu'] as $needle) {
            if (str_contains($path, $needle) || str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{url: string, title: string}|null  $value
     * @return array{url: string, title: string}|null
     */
    private function remember(string $cacheKey, ?array $value): ?array
    {
        $this->cache[$cacheKey] = $value;

        return $value;
    }
}
