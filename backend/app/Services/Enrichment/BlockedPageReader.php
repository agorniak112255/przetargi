<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Odczyt kart produktu gdy bezpośredni GET dostaje Incapsula/Cloudflare.
 * Używa publicznego readera Jina (markdown z linkami do mediów).
 */
final class BlockedPageReader
{
    private const MAX_TEXT_BYTES = 400000;

    private const MAX_SCREENSHOT_BYTES = 8000000;

    /** Druga próba po 429/5xx — limit Jiny przy wielu workerach bywa chwilowy. */
    private const READER_ATTEMPTS = 2;

    private const READER_RETRY_PAUSE_MICROS = 2_000_000;

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
                ->withOptions(['allow_redirects' => true, 'stream' => true])
                ->get('https://r.jina.ai/'.$url);
        } catch (Throwable $e) {
            Log::info('Blocked page screenshot failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $bytes = $this->readLimitedBody($response, self::MAX_SCREENSHOT_BYTES);
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

        $response = $this->requestReader($url);
        if ($response === null) {
            return null;
        }

        $markdown = $this->readLimitedBody($response, self::MAX_TEXT_BYTES);
        if ($markdown === '' || mb_strlen($markdown) < 80) {
            return null;
        }
        if (str_contains(mb_strtolower($markdown), 'incapsula') && mb_strlen($markdown) < 1200) {
            return null;
        }
        // Jina oddaje 200 także wtedy, gdy strona zwróciła 404 — dopisuje tylko ostrzeżenie.
        // Ekran „Sorry to interrupt / CSS Error” (sklep na Salesforce) też nie jest kartą.
        if ($this->readerReportsTargetError($markdown) || self::looksLikeAppErrorShell($markdown)) {
            return null;
        }
        $head = mb_strtolower(mb_substr($markdown, 0, 500));
        if (str_contains($head, 'product not found') || str_contains($head, 'nie znaleziono produktu')) {
            return null;
        }

        return [
            'text' => mb_substr($this->stripReaderChrome($markdown), 0, 5000),
            'image_urls' => $this->extractImageUrls($markdown, $url),
            'document_urls' => $this->extractDocumentUrls($markdown, $url),
        ];
    }

    /**
     * Przy wielu workerach naraz Jina potrafi chwilowo odmówić (429) albo paść (5xx) —
     * wtedy druga próba po chwili. Timeoutu nie ponawiamy: to kolejne 35 s zadania.
     */
    private function requestReader(string $url): ?Response
    {
        for ($attempt = 1; $attempt <= self::READER_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(35)
                    ->connectTimeout(8)
                    ->withHeaders([
                        'Accept' => 'text/plain,text/markdown,*/*',
                        'User-Agent' => 'Mozilla/5.0 (compatible; SUPON-Enrichment/1.4)',
                    ])
                    ->withOptions(['stream' => true])
                    ->get('https://r.jina.ai/'.$url);
            } catch (Throwable $e) {
                Log::info('Blocked page reader failed', ['url' => $url, 'error' => $e->getMessage()]);

                return null;
            }
            if ($response->successful()) {
                return $response;
            }
            $status = $response->status();
            Log::info('Blocked page reader refused', ['url' => $url, 'status' => $status, 'attempt' => $attempt]);
            if ($status !== 429 && $status < 500) {
                return null;
            }
            if ($attempt < self::READER_ATTEMPTS && ! app()->environment('testing')) {
                usleep(self::READER_RETRY_PAUSE_MICROS);
            }
        }

        return null;
    }

    /** „Warning: Target URL returned error 404: Not Found” — Jina przeczytała stronę błędu. */
    private function readerReportsTargetError(string $markdown): bool
    {
        return preg_match('/^Warning:\s*Target URL returned error\s+[45]\d\d\b/mi', $markdown) === 1;
    }

    /** Sklep na Salesforce bez przeglądarki pokazuje tylko „Loading… Sorry to interrupt… CSS Error”. */
    public static function looksLikeAppErrorShell(string $text): bool
    {
        $low = mb_strtolower($text);

        return str_contains($low, 'sorry to interrupt')
            && (str_contains($low, 'css error') || str_contains($low, 'this page has an error'));
    }

    /** Surowy HTML albo markdown z linkami — do crawla sklepu bez sitemapy. */
    public function fetchForCrawl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://'))) {
            return null;
        }

        try {
            $response = Http::timeout(35)
                ->connectTimeout(8)
                ->withHeaders([
                    'Accept' => 'text/html,text/markdown,text/plain,*/*',
                    'X-Return-Format' => 'html',
                    'User-Agent' => 'Mozilla/5.0 (compatible; SUPON-Enrichment/1.4)',
                ])
                ->withOptions(['stream' => true])
                ->get('https://r.jina.ai/'.$url);
        } catch (Throwable $e) {
            Log::info('Blocked page crawl fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }
        $body = $this->readLimitedBody($response, self::MAX_TEXT_BYTES);
        if ($body === '' || strlen($body) < 40) {
            return null;
        }

        return $body;
    }

    private function readLimitedBody(Response $response, int $maxBytes): string
    {
        $buf = $this->readStreamPrefix($response, $maxBytes);
        if ($buf !== null) {
            return trim($buf);
        }
        $body = $response->body();

        return trim(strlen($body) <= $maxBytes ? $body : substr($body, 0, $maxBytes));
    }

    private function readStreamPrefix(Response $response, int $maxBytes): ?string
    {
        try {
            $stream = $response->toPsrResponse()->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $buf = '';
            while (! $stream->eof() && strlen($buf) < $maxBytes) {
                $chunk = $stream->read(min(8192, $maxBytes - strlen($buf)));
                if ($chunk === '') {
                    break;
                }
                $buf .= $chunk;
            }

            return $buf;
        } catch (Throwable) {
            return null;
        }
    }

    private function stripReaderChrome(string $markdown): string
    {
        $markdown = preg_replace('/^Title:.*$/mi', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/^URL Source:.*$/mi', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/^Markdown Content:\s*/mi', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $markdown) ?? $markdown;

        // Reader oddaje menu sklepu jako listę linków — bez tego „Popular Styles”
        // ląduje w opisie produktu. Zdania z pojedynczym odnośnikiem zostają.
        $lines = [];
        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            $clean = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $line) ?? $line;
            if ($this->lineIsNavigation($line, $clean)) {
                continue;
            }
            $lines[] = $clean;
        }

        return trim(preg_replace('/\n{3,}/u', "\n\n", implode("\n", $lines)) ?? implode("\n", $lines));
    }

    /** Punkt listy, w którym poza odnośnikami nie ma żadnej treści. */
    private function lineIsNavigation(string $raw, string $clean): bool
    {
        $links = preg_match_all('/\[[^\]]*\]\([^)]*\)/u', $raw);
        if ($links === false || $links === 0) {
            return false;
        }
        $withoutLinks = trim(preg_replace('/\[[^\]]*\]\([^)]*\)/u', '', $raw) ?? '');
        $withoutLinks = trim((string) preg_replace('/^[\s*\-–—•|>#]+|[\s*\-–—•|>#]+$/u', '', $withoutLinks));

        return $links >= 2 || $withoutLinks === '' || mb_strlen($withoutLinks) < 25;
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
