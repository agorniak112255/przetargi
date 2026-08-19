<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\TavilyQuotaGuard;
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
                    'query' => mb_substr($query, 0, 400),
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

        return $this->remember($cacheKey, $this->pickBestResult($rows));
    }

    /**
     * Strona produktu przed PDF-em / świadectwem / deklaracją.
     *
     * @param  list<mixed>  $rows
     * @return array{url: string, title: string}|null
     */
    public function pickBestResult(array $rows): ?array
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
            $candidates[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'score' => $this->scoreResult($url, $title),
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

    private function scoreResult(string $url, string $title): int
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
