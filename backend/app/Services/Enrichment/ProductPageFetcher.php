<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use Throwable;

final class ProductPageFetcher
{
    /**
     * @param  list<array{url: string, title?: string, snippet?: string}>  $results
     * @return array{
     *     pages: list<array{url: string, text: string}>,
     *     image_urls: list<string>
     * }
     */
    public function fetch(array $results, string $sku, int $maxPages = 3): array
    {
        $ranked = $this->rankResults($results, $sku);
        $pages = [];
        $images = [];
        $skuNorm = mb_strtolower(trim($sku));

        foreach (array_slice($ranked, 0, $maxPages) as $row) {
            $url = $row['url'];
            try {
                $response = Http::timeout(20)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; SUPON-Enrichment/1.2)',
                        'Accept' => 'text/html,application/xhtml+xml',
                    ])
                    ->get($url);
                if (! $response->successful()) {
                    $snippet = trim((string) ($row['snippet'] ?? ''));
                    if ($snippet !== '') {
                        $pages[] = ['url' => $url, 'text' => mb_substr($snippet, 0, 4000)];
                    }

                    continue;
                }

                $html = $response->body();
                $text = $this->htmlToText($html);
                $pageLooksLikeProduct = $this->pageMentionsSku($url, $text, (string) ($row['title'] ?? ''), $skuNorm);

                if ($text !== '') {
                    $pages[] = ['url' => $url, 'text' => mb_substr($text, 0, 8000)];
                }

                // Zdjęcie TYLKO ze stron, które wyglądają na kartę tego SKU
                if ($pageLooksLikeProduct) {
                    foreach ($this->extractImageUrls($html, $url, $skuNorm) as $img) {
                        $images[] = $img;
                    }
                }
            } catch (Throwable) {
                $snippet = trim((string) ($row['snippet'] ?? ''));
                if ($snippet !== '') {
                    $pages[] = ['url' => $url, 'text' => mb_substr($snippet, 0, 4000)];
                }
            }
        }

        return [
            'pages' => $pages,
            'image_urls' => array_values(array_unique($images)),
        ];
    }

    /**
     * @param  list<array{url: string, title?: string, snippet?: string}>  $results
     * @return list<array{url: string, title?: string, snippet?: string}>
     */
    private function rankResults(array $results, string $sku): array
    {
        $skuNorm = mb_strtolower(trim($sku));
        $skuCompact = preg_replace('/[^a-z0-9]/i', '', $skuNorm) ?? $skuNorm;

        usort($results, static function (array $a, array $b) use ($skuNorm, $skuCompact): int {
            return self::score($b, $skuNorm, $skuCompact) <=> self::score($a, $skuNorm, $skuCompact);
        });

        return $results;
    }

    /**
     * @param  array{url: string, title?: string, snippet?: string}  $row
     */
    private static function score(array $row, string $skuNorm, string $skuCompact): int
    {
        $url = mb_strtolower($row['url'] ?? '');
        $title = mb_strtolower((string) ($row['title'] ?? ''));
        $snippet = mb_strtolower((string) ($row['snippet'] ?? ''));
        $hay = $url.' '.$title.' '.$snippet;
        $score = 0;

        if ($skuNorm !== '' && str_contains($hay, $skuNorm)) {
            $score += 50;
        }
        if ($skuCompact !== '' && str_contains(preg_replace('/[^a-z0-9]/i', '', $hay) ?? '', $skuCompact)) {
            $score += 30;
        }
        if (preg_match('#/(produkt|product|p)/#i', $url)) {
            $score += 20;
        }
        if (preg_match('#/\d{2,}-[\w\-]+#i', $url)) {
            $score += 10;
        }
        if (preg_match('#/(kategoria|category|content|o-firmie|blog|244-|190-)#i', $url)) {
            $score -= 40;
        }

        return $score;
    }

    private function pageMentionsSku(string $url, string $text, string $title, string $skuNorm): bool
    {
        if ($skuNorm === '') {
            return false;
        }

        $hay = mb_strtolower($url.' '.$title.' '.$text);
        if (str_contains($hay, $skuNorm)) {
            return true;
        }

        $compactSku = preg_replace('/[^a-z0-9]/i', '', $skuNorm) ?? $skuNorm;
        $compactHay = preg_replace('/[^a-z0-9]/i', '', $hay) ?? $hay;

        return $compactSku !== '' && str_contains($compactHay, $compactSku);
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    private function extractImageUrls(string $html, string $pageUrl, string $skuNorm): array
    {
        $candidates = [];

        // 1) img z SKU w src/alt — najlepsze
        if (preg_match_all('#<img\b[^>]*>#i', $html, $imgTags)) {
            foreach ($imgTags[0] as $tag) {
                if (! is_string($tag)) {
                    continue;
                }
                $src = null;
                if (preg_match('#\bsrc=["\']([^"\']+)["\']#i', $tag, $m)) {
                    $src = $m[1];
                } elseif (preg_match('#\bdata-src=["\']([^"\']+)["\']#i', $tag, $m)) {
                    $src = $m[1];
                }
                if (! is_string($src) || $src === '') {
                    continue;
                }
                $alt = '';
                if (preg_match('#\balt=["\']([^"\']*)["\']#i', $tag, $am)) {
                    $alt = $am[1];
                }
                $abs = $this->absolutize($src, $pageUrl);
                if ($abs === null || $this->isJunkImageUrl($abs)) {
                    continue;
                }
                $meta = mb_strtolower($abs.' '.$alt);
                $score = 0;
                if ($skuNorm !== '' && str_contains($meta, $skuNorm)) {
                    $score += 100;
                }
                if (str_contains($meta, 'maxi') || str_contains($meta, 'rekaw') || str_contains($meta, 'glove') || str_contains($meta, 'handschuh')) {
                    $score += 40;
                }
                if (str_contains($meta, 'product') || str_contains($meta, 'katalog')) {
                    $score += 10;
                }
                if ($score > 0) {
                    $candidates[] = ['url' => $abs, 'score' => $score];
                }
            }
        }

        // 2) og:image ze strony karty (wywołujemy extract tylko gdy pageMentionsSku) — akceptuj
        if (preg_match('#property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $m)
            || preg_match('#content=["\']([^"\']+)["\'][^>]*property=["\']og:image["\']#i', $html, $m)) {
            $abs = $this->absolutize($m[1], $pageUrl);
            if ($abs !== null && ! $this->isJunkImageUrl($abs)) {
                $meta = mb_strtolower($abs);
                $score = 45; // strona już przeszła filtr SKU
                if ($skuNorm !== '' && str_contains($meta, $skuNorm)) {
                    $score += 80;
                }
                if (str_contains($meta, 'maxi') || str_contains($meta, 'rekaw') || str_contains($meta, 'glove')) {
                    $score += 30;
                }
                $candidates[] = ['url' => $abs, 'score' => $score];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $out = [];
        foreach ($candidates as $row) {
            $out[] = $row['url'];
        }

        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    private function isJunkImageUrl(string $url): bool
    {
        $u = mb_strtolower($url);
        foreach ([
            'logo', 'icon', 'sprite', 'favicon', 'banner', 'payment',
            'dhl', 'inpost', 'poczta', 'ups', 'fedex', 'dpd', 'gls',
            'cart', 'koszyk', 'wallet', 'payu', 'przelewy', 'blik',
            'shoe', 'buty', 'ochronki', 'ochraniacz', 'bachior', 'bootie',
            'cover', 'nakladki', 'folie-na', 'placeholder', 'blank', 'pixel',
        ] as $needle) {
            if (str_contains($u, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function absolutize(string $url, string $base): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parts = parse_url($base);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }

        $path = (string) ($parts['path'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $origin.$dir.'/'.$url;
    }
}
