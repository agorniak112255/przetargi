<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ProductDocumentDownloader
{
    private const MAX_BYTES = 15_000_000;

    public static function looksLikePdfUrl(string $url): bool
    {
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return false;
        }
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $full = mb_strtolower($url);

        return str_ends_with($path, '.pdf')
            || str_contains($full, '.pdf?')
            || str_contains($full, '/pdf/')
            || str_contains($full, 'filetype=pdf');
    }

    /**
     * PDF + trasy producenta (Ansell /pds|/doc|/ukdoc, IFU .ashx).
     */
    public static function looksLikeDocumentUrl(string $url): bool
    {
        if (self::looksLikePdfUrl($url)) {
            return true;
        }
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return false;
        }
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $full = mb_strtolower($url);

        if (preg_match('#/(pds|doc|ukdoc)(/|$)#i', $path) === 1) {
            return true;
        }
        if (str_ends_with($path, '.ashx') && preg_match(
            '#(ifu|datasheet|declaration|deklar|conform|pdb|pds|certificate|certyfik)#i',
            $full
        ) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $urls
     * @return list<ProductDocument>
     */
    public function downloadMany(Product $product, array $urls, int $max = 3): array
    {
        $saved = [];
        $sort = 0;

        foreach ($this->rankUrls($urls) as $url) {
            if (count($saved) >= $max) {
                break;
            }
            if (! is_string($url) || ! self::looksLikeDocumentUrl($url)) {
                continue;
            }

            try {
                $doc = $this->downloadOne($product, $url, $sort);
            } catch (Throwable $e) {
                Log::info('Product PDF download skipped', [
                    'product_id' => $product->id,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($doc === null) {
                continue;
            }

            $saved[] = $doc;
            $sort++;
        }

        return $saved;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function rankUrls(array $urls): array
    {
        $scored = [];
        foreach (array_values(array_unique($urls)) as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }
            $u = mb_strtolower(urldecode($url));
            $score = 10;
            if (preg_match('#(cert|conform|declaration|deklarac|zgodno|doc|ue|eu[-_]?doc|oeko|oeeko|reach)#iu', $u)) {
                $score += 80;
            }
            if (preg_match('#(datasheet|data[-_]?sheet|pds|tds|spec|karta|pdb)#i', $u)) {
                $score += 50;
            }
            if (preg_match('#/(pds|doc|ukdoc)(/|$)#i', $u)) {
                $score += 70;
            }
            if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '.pdf')) {
                $score += 20;
            }
            $scored[] = ['url' => $url, 'score' => $score];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(static fn (array $r): string => $r['url'], $scored);
    }

    private function downloadOne(Product $product, string $url, int $sortOrder): ?ProductDocument
    {
        $response = Http::timeout(20)
            ->connectTimeout(5)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'application/pdf,*/*;q=0.8',
            ])
            ->withOptions(['allow_redirects' => true])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status());
        }

        $bytes = $response->body();
        $size = strlen($bytes);
        if ($bytes === '' || $size > self::MAX_BYTES) {
            throw new \RuntimeException('Pusty lub zbyt duży PDF');
        }
        if (! str_starts_with($bytes, '%PDF')) {
            // Ansell /pds|/doc czasem zwraca HTML z linkiem do PDF albo challenge
            $fromHtml = $this->extractPdfUrlFromHtml($bytes, $url);
            if ($fromHtml !== null && $fromHtml !== $url) {
                return $this->downloadOne($product, $fromHtml, $sortOrder);
            }
            throw new \RuntimeException('Plik nie wygląda na PDF');
        }

        $checksum = hash('sha256', $bytes);
        $existing = ProductDocument::query()
            ->where('product_id', $product->id)
            ->where('checksum', $checksum)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $relative = 'products/'.$product->id.'/docs/'.Str::lower(Str::random(16)).'.pdf';
        Storage::disk('public')->put($relative, $bytes);

        $kind = $this->guessKind($url);
        $title = $this->guessTitle($url, $kind);

        return ProductDocument::query()->create([
            'product_id' => $product->id,
            'path' => $relative,
            'source_url' => mb_substr($url, 0, 2000),
            'title' => $title,
            'kind' => $kind,
            'sort_order' => $sortOrder,
            'checksum' => $checksum,
            'size_bytes' => $size,
        ]);
    }

    private function extractPdfUrlFromHtml(string $html, string $pageUrl): ?string
    {
        if (str_contains(mb_strtolower($html), 'incapsula') || str_contains(mb_strtolower($html), '_incapsula_resource')) {
            return null;
        }
        if (preg_match('#https?://[^"\'\s<>]+\.pdf(?:\?[^"\'\s<>]*)?#i', $html, $m) === 1) {
            return $m[0];
        }
        if (preg_match('#href=["\']([^"\']+\.pdf[^"\']*)["\']#i', $html, $m) === 1) {
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            if (str_starts_with($href, 'http')) {
                return $href;
            }
            $base = rtrim($pageUrl, '/');
            if (str_starts_with($href, '/')) {
                $parts = parse_url($pageUrl);

                return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$href;
            }

            return $base.'/'.ltrim($href, '/');
        }

        return null;
    }

    private function guessKind(string $url): string
    {
        $u = mb_strtolower(urldecode($url));
        if (preg_match('#(cert|conform|declaration|deklarac|zgodno|/doc/|ukdoc|oeko)#iu', $u)) {
            return ProductDocument::KIND_CERTIFICATE;
        }
        if (preg_match('#(datasheet|data[-_]?sheet|/pds/|pds|tds|spec|karta|pdb)#i', $u)) {
            return ProductDocument::KIND_DATASHEET;
        }

        return ProductDocument::KIND_OTHER;
    }

    private function guessTitle(string $url, string $kind): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $base = basename($path);
        $base = urldecode($base);
        if ($base !== '' && $base !== '/') {
            return mb_substr($base, 0, 255);
        }

        return match ($kind) {
            ProductDocument::KIND_CERTIFICATE => 'Certyfikat.pdf',
            ProductDocument::KIND_DATASHEET => 'Karta produktu.pdf',
            default => 'Dokument.pdf',
        };
    }
}
