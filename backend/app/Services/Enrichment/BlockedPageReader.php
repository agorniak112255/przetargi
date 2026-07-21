<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Odczyt kart produktu gdy bezpośredni GET dostaje Incapsula/Cloudflare.
 * Używa publicznego readera Jina (markdown z linkami do mediów).
 */
final class BlockedPageReader
{
    /**
     * Zrzut karty produktu (PNG) — gdy CDN obrazków też za Incapsulą (Ansell .ashx).
     */
    public function fetchScreenshot(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://'))) {
            return null;
        }

        try {
            $response = Http::timeout(55)
                ->connectTimeout(8)
                ->withHeaders([
                    'Accept' => 'image/png,image/jpeg,*/*',
                    'X-Return-Format' => 'screenshot',
                    'User-Agent' => 'Mozilla/5.0 (compatible; SUPON-Enrichment/1.4)',
                ])
                ->get('https://r.jina.ai/'.$url);
        } catch (Throwable $e) {
            Log::info('Blocked page screenshot failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) < 8000) {
            return null;
        }
        if (! str_starts_with($bytes, "\x89PNG") && ! str_starts_with($bytes, "\xFF\xD8")) {
            return null;
        }

        return $bytes;
    }

    /**
     * @return array{
     *     text: string,
     *     image_urls: list<string>,
     *     document_urls: list<string>
     * }|null
     */
    public function fetch(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://'))) {
            return null;
        }

        $proxy = 'https://r.jina.ai/'.$url;

        try {
            $response = Http::timeout(35)
                ->connectTimeout(8)
                ->withHeaders([
                    'Accept' => 'text/plain,text/markdown,*/*',
                    'User-Agent' => 'Mozilla/5.0 (compatible; SUPON-Enrichment/1.4)',
                ])
                ->get($proxy);
        } catch (Throwable $e) {
            Log::info('Blocked page reader failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $markdown = trim($response->body());
        if ($markdown === '' || mb_strlen($markdown) < 80) {
            return null;
        }
        if (str_contains(mb_strtolower($markdown), 'incapsula') && mb_strlen($markdown) < 1200) {
            return null;
        }

        return [
            'text' => mb_substr($this->stripReaderChrome($markdown), 0, 5000),
            'image_urls' => $this->extractImageUrls($markdown, $url),
            'document_urls' => $this->extractDocumentUrls($markdown, $url),
        ];
    }

    private function stripReaderChrome(string $markdown): string
    {
        $markdown = preg_replace('/^Title:.*$/mi', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/^URL Source:.*$/mi', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/^Markdown Content:\s*/mi', '', $markdown) ?? $markdown;

        return trim($markdown);
    }

    /**
     * @return list<string>
     */
    private function extractImageUrls(string $markdown, string $pageUrl): array
    {
        $found = [];
        if (preg_match_all('#!\[[^\]]*\]\((https?://[^)\s]+)\)#i', $markdown, $m)) {
            foreach ($m[1] as $u) {
                $found[] = $this->cleanUrl((string) $u);
            }
        }
        if (preg_match_all('#\((https?://[^)\s]+\.(?:jpe?g|png|webp|gif|ashx)(?:\?[^)\s]*)?)\)#i', $markdown, $m)) {
            foreach ($m[1] as $u) {
                $found[] = $this->cleanUrl((string) $u);
            }
        }
        if (preg_match_all('#https?://[^\s\)\"\']+/-/media/[^\s\)\"\']+#i', $markdown, $m)) {
            foreach ($m[0] as $u) {
                $found[] = $this->cleanUrl((string) $u);
            }
        }

        $out = [];
        foreach ($found as $url) {
            if ($url === null || ! ProductImageDownloader::looksLikeImageUrl($url)) {
                continue;
            }
            $out[] = $url;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function extractDocumentUrls(string $markdown, string $pageUrl): array
    {
        $found = [];
        if (preg_match_all('#\[[^\]]*\]\((https?://[^)\s]+)\)#i', $markdown, $m)) {
            foreach ($m[1] as $u) {
                $found[] = $this->cleanUrl((string) $u);
            }
        }
        if (preg_match_all('#https?://[^\s\)\"\']+\.pdf(?:\?[^\s\)\"\']*)?#i', $markdown, $m)) {
            foreach ($m[0] as $u) {
                $found[] = $this->cleanUrl((string) $u);
            }
        }

        $out = [];
        foreach ($found as $url) {
            if ($url === null) {
                continue;
            }
            if (ProductDocumentDownloader::looksLikeDocumentUrl($url)) {
                $out[] = $url;
            }
        }

        return array_values(array_unique($out));
    }

    private function cleanUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        $url = rtrim($url, '.,);]');
        if ($url === '' || ! str_starts_with($url, 'http')) {
            return null;
        }

        return $url;
    }
}
