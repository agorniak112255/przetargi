<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use App\Services\Enrichment\TavilyQuotaGuard;
use App\Support\PpeAssortment;
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
        private readonly PpeAssortment $assortment = new PpeAssortment,
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

        $ranked = $this->hints($query, 1);

        return $this->remember($cacheKey, $ranked[0] ?? null);
    }

    /**
     * Jawne „AI Internet” — kilka stron produktu, bez progu 12 znaków.
     *
     * @return list<array{url: string, title: string}>
     */
    public function hints(string $requirement, int $limit = 5): array
    {
        $query = trim($requirement);
        $limit = max(1, min(8, $limit));
        if (mb_strlen($query) < 3) {
            return [];
        }

        $rows = [];
        foreach ($this->webQueries($query) as $search) {
            foreach ($this->searchWeb($search) as $row) {
                $rows[] = $row;
            }
            $ranked = $this->rankResults($rows, $query);
            if (count($ranked) >= $limit) {
                return array_slice($ranked, 0, $limit);
            }
        }

        return array_slice($this->rankResults($rows, $query), 0, $limit);
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
        return $this->rankResults($rows, $requirement)[0] ?? null;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{url: string, title: string}>
     */
    public function rankResults(array $rows, string $requirement = ''): array
    {
        $candidates = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || ! str_starts_with($url, 'http')) {
                continue;
            }
            $key = mb_strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? $url));
            $extra = trim((string) ($row['content'] ?? $row['snippet'] ?? $row['body'] ?? ''));
            $hay = trim($url.' '.$title.' '.$extra);
            if (! $this->filterType->covers($requirement, $hay)) {
                continue;
            }
            if ($requirement !== '' && ! $this->assortment->compatibleOrUnknown($requirement, $hay)) {
                continue;
            }
            if ($requirement !== '' && ! $this->mentionsRequiredProduct($hay, $requirement)) {
                continue;
            }
            $seen[$key] = true;
            $candidates[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'score' => $this->scoreResult($url, $title, $requirement),
            ];
        }
        if ($candidates === []) {
            return [];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values(array_map(static fn (array $c): array => [
            'url' => $c['url'],
            'title' => $c['title'],
        ], $candidates));
    }

    /**
     * Najpierw ciasne zapytanie z klasą (A2-B2-E2-K2-NO), potem pełne wymaganie.
     *
     * @return list<string>
     */
    private function webQueries(string $requirement): array
    {
        $out = [];
        foreach ($this->filterType->compactCodes($requirement) as $code) {
            $hyphen = $this->filterType->hyphenated($code);
            $out[] = $hyphen.' pochłaniacz filtr';
            $out[] = $code.' pochłaniacz';
        }
        if (in_array('no', $this->filterType->required($requirement), true)) {
            $out[] = 'A2-B2-E2-K2-NO-P3 pochłaniacz wielogazowy';
            $out[] = 'A2-B2-E2-K2-Hg-CO-NO-P3 filtr';
        }
        $out[] = $this->productSearchQuery($requirement);

        return array_values(array_unique(array_filter(
            array_map(static fn (string $q): string => mb_substr(trim($q), 0, 400), $out)
        )));
    }

    /**
     * Szuka towaru (kombinezon…), substancja jest tylko warunkiem „odporność na”.
     */
    public function productSearchQuery(string $requirement): string
    {
        $bits = [];
        foreach ($this->filterType->compactCodes($requirement) as $code) {
            $bits[] = $code;
            $hyphen = $this->filterType->hyphenated($code);
            if (mb_strtolower($hyphen) !== $code) {
                $bits[] = $hyphen;
            }
        }
        if ($this->assortment->isUnderHelmetLiner($requirement)) {
            $bits[] = 'czepek wkładka ocieplana pod hełm ESD';
        }
        $bits[] = $this->productFirstPhrase($requirement);

        return mb_substr(implode(' ', array_unique($bits)), 0, 180);
    }

    private function productFirstPhrase(string $requirement): string
    {
        $raw = $this->unglueSpecLabels($requirement);
        $hazard = '';
        if (preg_match('/\(\s*w\s+szczeg(?:ól|ol)no[sś]ci\s+na\s+([^)]+)\)/ui', $raw, $m) === 1) {
            $hazard = trim($m[1], " \t,.;");
            $raw = trim((string) preg_replace('/\(\s*w\s+szczeg(?:ól|ol)no[sś]ci\s+na\s+[^)]+\)/ui', ' ', $raw));
        } elseif (preg_match('/\bw\s+szczeg(?:ól|ol)no[sś]ci\s+na\s+(.+?)(?:,|$)/ui', $raw, $m) === 1) {
            $hazard = trim($m[1], " \t,.;");
            $raw = trim((string) preg_replace('/\bw\s+szczeg(?:ól|ol)no[sś]ci\s+na\s+.+?(?:,|$)/ui', ' ', $raw));
        } elseif (preg_match('/\bna\s+(kwas\s+[^,;.()]+)/ui', $raw, $m) === 1) {
            $hazard = trim($m[1], " \t,.;");
            $raw = trim((string) preg_replace('/\bna\s+kwas\s+[^,;.()]+/ui', ' ', $raw));
        }
        $raw = $this->productHeadline($raw);
        if ($this->modelNeedles($requirement) === []) {
            foreach ($this->assortment->catalogNounLikes($requirement) as $like) {
                if ($like !== '' && ! str_contains(mb_strtolower($raw), mb_strtolower($like))) {
                    $raw = $like.' '.$raw;
                }
                break;
            }
        }
        if ($hazard !== '') {
            $raw = trim($raw.' odporność na '.$hazard);
        }

        return $raw !== '' ? $raw : $requirement;
    }

    /** Skleja z SIWZ: „10:1Długość” / „kgPrzełożenie” → osobne słowa. */
    private function unglueSpecLabels(string $text): string
    {
        $text = preg_replace('/(?<=[0-9a-ząćęłńóśźż])(?=[A-ZĄĆĘŁŃÓŚŹŻ])/u', ' ', $text) ?? $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Nagłówek karty (model + nazwa + marka), bez tabeli DOR/MBS/mm. */
    private function productHeadline(string $text): string
    {
        $text = trim((string) preg_replace('/[\s,;]+/u', ' ', $text));
        if (preg_match('/^(.{12,200}?)\s*:\s*[A-ZĄĆĘŁŃÓŚŹŻ(]/u', $text, $m) === 1) {
            return trim($m[1], " \t-–·•");
        }
        $words = preg_split('/\s+/u', $text) ?: [];

        return implode(' ', array_slice($words, 0, 12));
    }

    /**
     * @return list<string>
     */
    private function modelNeedles(string $requirement): array
    {
        $norm = $this->assortment->normalize($this->unglueSpecLabels($requirement));
        $out = [];
        if (preg_match_all('/\b[a-z]{2,6}\s+\d{2,5}(?:\s+[a-z0-9]{1,6})?\b/u', $norm, $m) > 0) {
            foreach ($m[0] as $raw) {
                $token = trim((string) preg_replace('/\s+/u', ' ', $raw));
                if ($token === '' || preg_match('/^(en|iso|pn|din|ce|ansi|typ|klasa|kat|dor|mbs|min|max)\b/u', $token) === 1) {
                    continue;
                }
                $out[] = $token;
                $compact = str_replace(' ', '', $token);
                if ($compact !== $token) {
                    $out[] = $compact;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function mentionsModelCode(string $hay, string $requirement): bool
    {
        $needles = $this->modelNeedles($requirement);
        if ($needles === []) {
            return false;
        }
        $norm = $this->assortment->normalize($hay);
        $compact = str_replace(' ', '', $norm);
        foreach ($needles as $needle) {
            if ($needle !== '' && (str_contains($norm, $needle) || str_contains($compact, str_replace(' ', '', $needle)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchWeb(string $search): array
    {
        if ($this->settings->usesFreeWebSearch()) {
            try {
                return $this->duckDuckGo->search($search, 8);
            } catch (Throwable) {
                return [];
            }
        }

        $cfg = $this->settings->resolve();
        $key = $cfg['tavily_api_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return [];
        }

        try {
            TavilyQuotaGuard::assertAllowed();
        } catch (Throwable) {
            return [];
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
            return [];
        }

        if (! $response->successful() || $response->status() === 429) {
            return [];
        }

        $rows = $response->json('results');

        return is_array($rows) ? $rows : [];
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
        if ($requirement !== '' && $this->mentionsRequiredProduct($hay, $requirement)) {
            $score += 35;
        }
        if ($requirement !== '' && $this->mentionsModelCode($hay, $requirement)) {
            $score += 50;
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

        if (str_contains($path, 'kategoria-produkt') || str_contains($path, '/category/')) {
            return false;
        }
        foreach (['/produkt/', '/produkt-', '/product/', '/product-', '/sklep/', '/p/', 'karta produktu'] as $needle) {
            if (str_contains($path, $needle) || str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Gdy znamy towar (kombinezon…), wynik musi być o tym towarze — nie o samej substancji. */
    private function mentionsRequiredProduct(string $hay, string $requirement): bool
    {
        if ($this->mentionsModelCode($hay, $requirement)) {
            return true;
        }
        $likes = $this->assortment->catalogNounLikes($requirement);
        if ($likes === []) {
            return true;
        }
        $norm = $this->assortment->normalize($hay);
        foreach ($likes as $like) {
            $needle = $this->assortment->normalize($like);
            if ($needle !== '' && str_contains($norm, $needle)) {
                return true;
            }
        }

        return preg_match('/\b(tychem|coverall|overall|hazmat)\w*/u', $norm) === 1;
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
