<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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

    /** Po 429 dwie sekundy to za mało — limit Jiny liczy się w oknie minutowym. */
    private const READER_LIMIT_PAUSE_MICROS = 8_000_000;

    private const READER_LAST_AT_KEY = 'blocked_page_reader_last_at';

    private const READER_MAX_WAIT = 30.0;

    /**
     * Dlaczego reader nie oddał strony — per adres. Bez tego przebieg mówił tylko
     * „reader też nie przeszedł”, a nie wiadomo było, czy to limit Jiny (429),
     * odmowa, timeout czy strona „nie znaleziono”.
     *
     * @var array<string, string>
     */
    private array $failures = [];

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

        unset($this->failures[$url]);
        $response = $this->requestReader($url);
        if ($response === null) {
            $this->failures[$url] ??= 'reader nie odpowiedział';

            return null;
        }

        $markdown = $this->readLimitedBody($response, self::MAX_TEXT_BYTES);
        if ($markdown === '' || mb_strlen($markdown) < 80) {
            $this->failures[$url] = 'reader oddał pustą stronę';

            return null;
        }
        if (str_contains(mb_strtolower($markdown), 'incapsula') && mb_strlen($markdown) < 1200) {
            $this->failures[$url] = 'zapora także u readera';

            return null;
        }
        // Jina oddaje 200 także wtedy, gdy strona zwróciła 404 — dopisuje tylko ostrzeżenie.
        // Ekran „Sorry to interrupt / CSS Error” (sklep na Salesforce) też nie jest kartą.
        if ($this->readerReportsTargetError($markdown) || self::looksLikeAppErrorShell($markdown)) {
            $this->failures[$url] = 'strona błędu u celu';

            return null;
        }
        $head = mb_strtolower(mb_substr($markdown, 0, 500));
        if (str_contains($head, 'product not found') || str_contains($head, 'nie znaleziono produktu')) {
            $this->failures[$url] = 'strona „nie znaleziono produktu”';

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
            $this->reserveReaderSlot();
            try {
                $response = Http::timeout(35)
                    ->connectTimeout(8)
                    ->withHeaders($this->readerHeaders())
                    ->withOptions(['stream' => true])
                    ->get('https://r.jina.ai/'.$url);
            } catch (Throwable $e) {
                Log::info('Blocked page reader failed', ['url' => $url, 'error' => $e->getMessage()]);
                $this->failures[$url] = 'reader: timeout albo brak połączenia';

                return null;
            }
            if ($response->successful()) {
                return $response;
            }
            $status = $response->status();
            // bez „HTTP 403” w tekscie: taki ciag SearchEngineOutage bierze za awarie
            // wyszukiwarki i produkt szedlby do ponowienia zamiast do reki
            $this->failures[$url] = $status === 429 ? 'reader: limit 429' : 'reader: odmowa '.$status;
            Log::info('Blocked page reader refused', ['url' => $url, 'status' => $status, 'attempt' => $attempt]);
            if ($status !== 429 && $status < 500) {
                return null;
            }
            if ($attempt < self::READER_ATTEMPTS && ! app()->environment('testing')) {
                usleep($status === 429 ? self::READER_LIMIT_PAUSE_MICROS : self::READER_RETRY_PAUSE_MICROS);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function readerHeaders(): array
    {
        $headers = [
            'Accept' => 'text/plain,text/markdown,*/*',
            'User-Agent' => 'Mozilla/5.0 (compatible; SUPON-Enrichment/1.4)',
        ];
        $key = trim((string) config('enrichment.reader_api_key', ''));
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer '.$key;
        }

        return $headers;
    }

    /**
     * Jedno miejsce w kolejce dla całego serwera — jak reserveSearchSlot przy
     * SearXNG. Zwykły odstęp „od ostatniego requestu” nie działa przy kilkunastu
     * workerach: wszystkie czytają ten sam znacznik i ruszają razem, a Jina
     * odpowiada 429 każdemu z nich.
     */
    private function reserveReaderSlot(): void
    {
        if (app()->environment('testing')) {
            return;
        }
        $interval = max(0.0, (float) config('enrichment.reader_min_interval', 3.5));
        if ($interval <= 0.0) {
            return;
        }
        $wait = 0.0;
        $lock = Cache::lock(self::READER_LAST_AT_KEY.'_lock', 10);
        try {
            if (! $lock->block(15, static fn (): bool => true)) {
                return;
            }
            $now = microtime(true);
            $at = max($now, (float) Cache::get(self::READER_LAST_AT_KEY, 0.0));
            Cache::put(self::READER_LAST_AT_KEY, $at + $interval, 300);
            $wait = min($at - $now, self::READER_MAX_WAIT);
        } catch (Throwable) {
            return;
        } finally {
            $lock->release();
        }
        if ($wait > 0) {
            usleep((int) round($wait * 1_000_000));
        }
    }

    /** Powód ostatniej porażki readera dla adresu — do listy odrzuceń w przebiegu. */
    public function failureFor(string $url): ?string
    {
        return $this->failures[trim($url)] ?? null;
    }

    /**
     * Limit 429, timeout i 5xx to porażki readera, nie sklepu — za chwilę może
     * przejść, więc produkt ma wrócić do ponowienia. Stała jest dopiero odmowa
     * sklepu wobec readera („strona błędu u celu”, zapora także u readera) albo
     * odmowa readera inna niż limit.
     */
    public function failureIsTransient(string $url): bool
    {
        $failure = $this->failureFor($url);
        if ($failure === null) {
            return false;
        }

        return str_contains($failure, 'limit 429')
            || str_contains($failure, 'timeout')
            || str_contains($failure, 'nie odpowiedział')
            || preg_match('/odmowa 5[0-9]{2}$/u', $failure) === 1;
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
