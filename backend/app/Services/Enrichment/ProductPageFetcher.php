<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductPageFetcher
{
    public function __construct(
        private readonly BlockedPageReader $blockedPages = new BlockedPageReader,
    ) {}

    /**
     * @param  list<array{url: string, title?: string, snippet?: string}>  $results
     * @param  list<string>  $manufacturerDomains  hosty producenta — wtedy bierzemy PDF ze strony (zwykle certyfikaty)
     * @return array{
     *     pages: list<array{url: string, text: string}>,
     *     image_urls: list<string>,
     *     trusted_image_urls: list<string>,
     *     document_urls: list<string>
     * }
     */
    public function fetch(array $results, string $sku, int $maxPages = 2, array $manufacturerDomains = []): array
    {
        $wanted = max(1, $maxPages);
        $ranked = $this->rankResults($results, $sku);
        $skuNorm = mb_strtolower(trim($sku));
        $documents = [];
        foreach ($results as $row) {
            $u = (string) ($row['url'] ?? '');
            if (ProductDocumentDownloader::looksLikeDocumentUrl($u)) {
                $documents[] = $u;
            }
        }

        $htmlRows = [];
        foreach ($ranked as $row) {
            if (! ProductDocumentDownloader::looksLikePdfUrl((string) ($row['url'] ?? ''))) {
                $htmlRows[] = $row;
            }
        }
        $htmlRows = array_slice($htmlRows, 0, min(count($htmlRows), $wanted * 3));

        $goodPages = [];
        $fallbackPages = [];
        $images = [];
        $trustedImages = [];

        for ($offset = 0; $offset < count($htmlRows) && count($goodPages) < $wanted; $offset += $wanted) {
            $wave = array_values(array_slice($htmlRows, $offset, $wanted));
            $responses = $this->fetchWave($wave);
            foreach ($wave as $i => $row) {
                $this->ingestFetchedRow(
                    $row,
                    $responses[(string) $i] ?? null,
                    $skuNorm,
                    $manufacturerDomains,
                    $goodPages,
                    $fallbackPages,
                    $images,
                    $trustedImages,
                    $documents
                );
            }
        }

        $pages = $goodPages !== []
            ? $this->bestPages($goodPages, $skuNorm, $wanted)
            : array_slice($fallbackPages, 0, $wanted);

        return [
            'pages' => $pages,
            'image_urls' => array_values(array_unique($images)),
            'trusted_image_urls' => array_values(array_unique($trustedImages)),
            'document_urls' => array_values(array_unique($documents)),
        ];
    }

    /**
     * @param  list<array{url: string, title?: string, snippet?: string}>  $htmlRows
     * @return array<string, mixed>
     */
    private function fetchWave(array $htmlRows): array
    {
        try {
            return Http::pool(function (Pool $pool) use ($htmlRows) {
                foreach ($htmlRows as $i => $row) {
                    $pool->as((string) $i)
                        ->timeout(10)
                        ->connectTimeout(4)
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                .'AppleWebKit/537.36 (KHTML, like Gecko) '
                                .'Chrome/124.0.0.0 Safari/537.36',
                            'Accept' => 'text/html,application/xhtml+xml',
                        ])
                        ->withOptions([
                            'allow_redirects' => true,
                            'cookies' => new CookieJar,
                        ])
                        ->get($row['url']);
                }
            });
        } catch (Throwable $e) {
            Log::info('Product page fetch pool failed', [
                'urls' => array_slice(array_column($htmlRows, 'url'), 0, 3),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /** Kody, którymi WAF-y odsyłają boty, zanim w ogóle dojdzie do treści karty. */
    private function isBlockedStatus(?int $status): bool
    {
        return $status !== null && in_array($status, [401, 403, 405, 429, 451, 503], true);
    }

    /**
     * @param  list<array{url: string, text: string}>  $goodPages
     * @param  list<string>  $images
     * @param  list<string>  $documents
     */
    private function ingestViaReader(
        string $url,
        array &$goodPages,
        array &$images,
        array &$documents,
    ): bool {
        $viaReader = $this->blockedPages->fetch($url);
        if ($viaReader === null) {
            return false;
        }

        $used = false;
        if ($viaReader['text'] !== '') {
            $goodPages[] = ['url' => $url, 'text' => $viaReader['text']];
            $used = true;
        }
        foreach ($viaReader['image_urls'] as $img) {
            $images[] = $img;
            $used = true;
        }
        foreach ($viaReader['document_urls'] as $doc) {
            $documents[] = $doc;
        }

        return $used;
    }

    /**
     * @param  array{url: string, title?: string, snippet?: string}  $row
     * @param  list<array{url: string, text: string}>  $goodPages
     * @param  list<array{url: string, text: string}>  $fallbackPages
     * @param  list<string>  $images
     * @param  list<string>  $trustedImages
     * @param  list<string>  $documents
     * @param  list<string>  $manufacturerDomains
     */
    private function ingestFetchedRow(
        array $row,
        mixed $response,
        string $skuNorm,
        array $manufacturerDomains,
        array &$goodPages,
        array &$fallbackPages,
        array &$images,
        array &$trustedImages,
        array &$documents,
    ): void {
        $url = (string) ($row['url'] ?? '');
        $ok = $response instanceof Response && $response->successful();

        if (! $ok) {
            $status = $response instanceof Response ? $response->status() : null;
            // WAF sklepu (gloves.co.uk, rs-online) odrzuca IP serwerowni jeszcze przed treścią,
            // więc kartę czytamy przez zewnętrzny reader zamiast odpuszczać stronę.
            if ($this->isBlockedStatus($status)
                && $this->ingestViaReader($url, $goodPages, $images, $documents)) {
                return;
            }
            Log::info('Product page fetch skipped', ['url' => $url, 'status' => $status]);
            $snippet = trim((string) ($row['snippet'] ?? ''));
            if ($snippet !== '') {
                $fallbackPages[] = ['url' => $url, 'text' => mb_substr($snippet, 0, 3000)];
            }

            return;
        }

        $html = $response->body();
        if ($this->looksLikeBotWall($html)) {
            if (! $this->ingestViaReader($url, $goodPages, $images, $documents)) {
                $snippet = trim((string) ($row['snippet'] ?? ''));
                if ($snippet !== '') {
                    $fallbackPages[] = ['url' => $url, 'text' => mb_substr($snippet, 0, 3000)];
                }
            }

            return;
        }

        $text = $this->extractProductPageText($html, $skuNorm);
        $title = (string) ($row['title'] ?? '');
        if ($this->hayHasLongerAlphanumericSkuVariant($url.' '.$title.' '.$text, $skuNorm)) {
            return;
        }
        $pageLooksLikeProduct = $this->pageMentionsSku($url, $text, $title, $skuNorm);

        if ($text !== '') {
            $goodPages[] = ['url' => $url, 'text' => mb_substr($text, 0, 5000)];
            // Kody z naszego cennika (HEATRESIST-GAT11) nie występują na kartach sklepów,
            // więc zdjęcia bierzemy z każdej odczytanej strony — trafność oceni AI Vision.
            foreach ($this->extractImageUrls($html, $url, $skuNorm) as $img) {
                $images[] = $img;
            }
        }

        // og:image bez potwierdzonego SKU nie zasługuje na status zaufanego
        if ($pageLooksLikeProduct) {
            foreach ($this->extractStructuredImageUrls($html) as $img) {
                $absolute = $this->absolutize($img, $url);
                if ($absolute !== null
                    && ! $this->isJunkImageUrl($absolute)
                    && ProductImageDownloader::looksLikeImageUrl($absolute)) {
                    $trustedImages[] = $absolute;
                }
            }
        }
        $fromManufacturer = $this->hostMatchesDomains($url, $manufacturerDomains);
        foreach ($this->extractDocumentUrls($html, $url, $skuNorm, $fromManufacturer) as $doc) {
            $documents[] = $doc;
        }
    }

    /**
     * @param  list<array{url: string, text: string}>  $pages
     * @return list<array{url: string, text: string}>
     */
    private function bestPages(array $pages, string $skuNorm, int $wanted): array
    {
        usort($pages, function (array $a, array $b) use ($skuNorm): int {
            return $this->livePageScore($b, $skuNorm) <=> $this->livePageScore($a, $skuNorm);
        });

        return array_slice(array_values($pages), 0, $wanted);
    }

    /**
     * @param  array{url: string, text: string}  $page
     */
    private function livePageScore(array $page, string $skuNorm): int
    {
        $url = (string) ($page['url'] ?? '');
        $text = (string) ($page['text'] ?? '');
        $score = min(3000, mb_strlen($text));
        if ($this->pageMentionsSku($url, $text, '', $skuNorm)) {
            $score += 4000;
        }

        return $score;
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
        $hayCompact = preg_replace('/[^a-z0-9]/i', '', $hay) ?? $hay;
        if ($skuCompact !== '' && str_contains($hayCompact, $skuCompact)) {
            $score += 30;
        }
        if (preg_match('/\b(\d{1,2}-\d{3})\b/', $skuNorm, $m)) {
            $core = $m[1];
            if (str_contains($url, $core) || str_contains($url, str_replace('-', '', $core))) {
                $score += 80;
            }
            if (str_contains($title, $core)) {
                $score += 30;
            }
        }
        if (str_contains($url, 'product') || str_contains($url, 'produkt') || str_contains($url, '/sklep/')) {
            $score += 10;
        }
        if (preg_match('#/(category|manufacturer|kategoria|producent|catalog)/#', $url)) {
            $score -= 50;
        }
        if (str_contains($url, 'atg') || str_contains($url, 'ansell') || str_contains($url, 'delta') || str_contains($url, 'demar')) {
            $score += 15;
        }
        if (str_contains($url, 'demar24') || str_contains($url, 'roboczystyl') || str_contains($url, 'gama-bhp') || str_contains($url, 'sklepzbhp')) {
            $score += 25;
        }
        if (str_contains($url, 'ceneo.pl') || str_contains($url, 'allegro.pl')) {
            $score -= 20;
        }

        return $score;
    }

    private function pageMentionsSku(string $url, string $text, string $title, string $skuNorm): bool
    {
        if ($skuNorm === '') {
            return false;
        }
        // „7-003 b 6060” → też „7-003 b” / „7-003b”
        $variants = [$skuNorm];
        if (preg_match('/^(\d{1,2}-\d{3}(?:\s+[a-z])?)/u', $skuNorm, $sm)) {
            $variants[] = $sm[1];
            $variants[] = str_replace(' ', '', $sm[1]);
        }
        $hay = mb_strtolower($url.' '.$title.' '.$text);
        $hayCompact = preg_replace('/[^a-z0-9]+/i', '', $hay) ?? $hay;
        if ($this->hayHasLongerAlphanumericSkuVariant($hay, $skuNorm)) {
            return false;
        }
        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }
            $compact = preg_replace('/[^a-z0-9]+/i', '', $variant) ?? $variant;
            if ($this->isAlphanumericSkuCode($compact)) {
                if (preg_match('/(?<![a-z0-9])'.preg_quote($variant, '/').'(?![a-z0-9])/iu', $hay) === 1) {
                    return true;
                }
                if ($compact !== '' && strlen($compact) >= 4
                    && preg_match('/(?<![a-z0-9])'.preg_quote($compact, '/').'(?![a-z0-9])/iu', $hay) === 1) {
                    return true;
                }

                continue;
            }
            if (str_contains($hay, $variant)) {
                return true;
            }
            if ($compact !== '' && strlen($compact) >= 4 && str_contains($hayCompact, $compact)) {
                return true;
            }
        }
        // PROS-101-S1-MAX ≈ URL …pros-101s-34… / tytuł „PROS 101/S”
        if ($this->pageMentionsBrandModelSku($hay, $hayCompact, $skuNorm)) {
            return true;
        }
        if (! preg_match('/\b(\d{1,2}-\d{3})\b/', $skuNorm, $m)) {
            // nazwa handlowa (CLIC UP, BOLT UP…) — tokeny w URL/tekście
            return $this->hayMentionsNameTokens($hay, $skuNorm);
        }
        $core = $m[1];
        if (! $this->containsArtCode($hay, $core)) {
            return false;
        }
        $parts = preg_split('/[\s\-·\/]+/u', $skuNorm) ?: [];
        $extras = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === $core) {
                continue;
            }
            // litery/normy; wariant „B” (1 znak) też
            if (preg_match('/^[a-z][a-z0-9]*$/i', $part) === 1) {
                $extras[] = mb_strtolower($part);
            }
        }
        if ($extras === []) {
            return true;
        }
        $hayCompact = preg_replace('/[^a-z0-9]/i', '', $hay) ?? $hay;
        $hits = 0;
        foreach ($extras as $token) {
            if (str_contains($hay, $token) || str_contains($hayCompact, $token)) {
                $hits++;
            }
        }

        return $hits >= max(1, (int) ceil(count($extras) * 0.5));
    }

    private function looksLikeBotWall(string $html): bool
    {
        $trim = trim($html);
        if ($trim === '' || strlen($trim) < 800) {
            return true;
        }
        $hay = mb_strtolower($trim);

        return str_contains($hay, '_incapsula_resource')
            || str_contains($hay, 'incapsula')
            || str_contains($hay, 'imperva')
            || str_contains($hay, 'cf-browser-verification')
            || str_contains($hay, 'attention required! | cloudflare')
            || (str_contains($hay, 'captcha') && strlen($trim) < 4000);
    }

    /**
     * Tylko treść produktu — bez menu, koszyka, logowania, zwrotów, breadcrumbów.
     */
    private function extractProductPageText(string $html, string $skuNorm): string
    {
        $chunks = [];

        $ogDesc = $this->extractOgDescription($html);
        if ($ogDesc !== '' && ! $this->looksLikeShopChrome($ogDesc)) {
            $chunks[] = $ogDesc;
        }

        foreach ($this->extractMetaProductFields($html) as $field) {
            if ($field !== '' && ! $this->looksLikeShopChrome($field)) {
                $chunks[] = $field;
            }
        }

        $focused = $this->extractFocusedHtmlBlocks($html);
        if ($focused !== '') {
            $chunks[] = $focused;
        } else {
            $chunks[] = $this->htmlToText($this->stripShopChromeHtml($html));
        }

        $text = trim(implode("\n\n", array_filter($chunks)));
        $text = $this->stripShopChromePhrases($text);
        $text = $this->keepProductRelevantParagraphs($text, $skuNorm);

        return mb_substr(trim($text), 0, 5000);
    }

    /**
     * @return list<string>
     */
    private function extractMetaProductFields(string $html): array
    {
        $out = [];
        if (preg_match('#name=["\']description["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $m)
            || preg_match('#content=["\']([^"\']+)["\'][^>]*name=["\']description["\']#i', $html, $m)) {
            $out[] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        // JSON-LD Product
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
            foreach ($blocks[1] as $json) {
                $data = json_decode(trim((string) $json), true);
                if (! is_array($data)) {
                    continue;
                }
                $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
                foreach ($nodes as $node) {
                    if (! is_array($node)) {
                        continue;
                    }
                    $type = $node['@type'] ?? '';
                    $types = is_array($type) ? $type : [$type];
                    if (! in_array('Product', $types, true) && ! in_array('ProductGroup', $types, true)) {
                        continue;
                    }
                    foreach (['description', 'name', 'sku', 'brand', 'material', 'category'] as $key) {
                        $val = $node[$key] ?? null;
                        if (is_string($val) && trim($val) !== '') {
                            $out[] = trim($val);
                        } elseif (is_array($val) && isset($val['name']) && is_string($val['name'])) {
                            $out[] = trim($val['name']);
                        }
                    }
                }
            }
        }

        return $out;
    }

    private function extractFocusedHtmlBlocks(string $html): string
    {
        $patterns = [
            '#<(?:div|section|article)[^>]*(?:id|class)=["\'][^"\']*(?:product[-_ ]?desc|opis[-_ ]?produkt|short[-_ ]?desc|full[-_ ]?desc|product[-_ ]?detail|tab[-_ ]?description|description|specyfik|cechy|parametr)[^"\']*["\'][^>]*>(.*?)</(?:div|section|article)>#is',
            '#<div[^>]*itemprop=["\']description["\'][^>]*>(.*?)</div>#is',
            '#<(?:p|div)[^>]*itemprop=["\']description["\'][^>]*>(.*?)</(?:p|div)>#is',
        ];
        $parts = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $m)) {
                foreach ($m[1] as $block) {
                    $t = $this->htmlToText((string) $block);
                    if (mb_strlen($t) >= 40 && ! $this->looksLikeShopChrome($t)) {
                        $parts[] = $t;
                    }
                }
            }
        }

        return trim(implode("\n\n", array_slice(array_unique($parts), 0, 6)));
    }

    private function stripShopChromeHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|iframe)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(nav|header|footer|aside|form|button)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        // typowe bloki sklepu
        $html = preg_replace(
            '#<(?:div|section|ul|li)[^>]*(?:id|class)=["\'][^"\']*(?:menu|navbar|breadcrumb|koszyk|cart|login|rejestr|footer|header|cookie|popup|modal|obserwowan|wishlist|porown|shipping|wysylk|platnosc|zwrot|regulamin|polityka)[^"\']*["\'][^>]*>.*?</(?:div|section|ul|li)>#is',
            ' ',
            $html
        ) ?? $html;

        return $html;
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function stripShopChromePhrases(string $text): string
    {
        $patterns = [
            '/\b(logowanie|zaloguj się|rejestracja|zarejestruj się|twoje konto|obserwowane|dodaj do koszyka|do koszyka|realizuj zamówienie|złóż zamówienie|suma:\s*[\d,\.]+\s*zł)\b/iu',
            '/\b(wyszukiwanie zaawansowane|jesteś tutaj|strona główna|sprawdź status zamówienia|sposoby płatności|prowizje|regulamin|polityka prywatności|odstąpienie od umowy)\b/iu',
            '/\b(łatwy zwrot|14\s*dni|kupuj i sprawdź|bez stresu i obaw|cena w punktach|kup za punkty|dodaj do porównania|dodaj do obserwowanych|powiadom mnie o dostępności)\b/iu',
            '/\b(sprawdź czasy i koszty wysyłki|wysyłka\s*:|dostępność\s*:|produkt dostępny w bardzo dużej ilości|nasza cena|cena katalogowa)\b/iu',
            '/\b(tel\.?\s*\d[\d\s]+|e-?mail\s*\S+@\S+)\b/iu',
            '/\|\s*/u',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function keepProductRelevantParagraphs(string $text, string $skuNorm): string
    {
        $parts = preg_split('/\n{2,}/u', $text) ?: [$text];
        $kept = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || mb_strlen($part) < 25) {
                continue;
            }
            if ($this->looksLikeShopChrome($part)) {
                continue;
            }
            $low = mb_strtolower($part);
            $productish = (bool) preg_match(
                '#(trzewik|p[oó]łbut|obuwie|buty|rękaw|ochron|norm|en\s*\d|iso|s3|s1|src|hro|o1|podnosek|podeszw|skór|nitryl|producent|przeznacz|materiał|cholewka|wkładka|kalosz|winter|gloss|clic)#iu',
                $low
            );
            $mentionsSku = $skuNorm !== '' && (
                str_contains($low, mb_strtolower($skuNorm))
                || $this->hayMentionsNameTokens($low, mb_strtolower($skuNorm))
            );
            if ($productish || $mentionsSku || mb_strlen($part) >= 120) {
                $kept[] = $part;
            }
        }
        if ($kept === []) {
            // ostatnia deska: wyczyść cały tekst ze śmieci i skróć
            $flat = $this->stripShopChromePhrases(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));

            return mb_substr($flat, 0, 2000);
        }

        return implode("\n\n", array_slice($kept, 0, 12));
    }

    private function looksLikeShopChrome(string $text): bool
    {
        $low = mb_strtolower($text);
        $hits = 0;
        foreach ([
            'logowanie', 'rejestracja', 'do koszyka', 'obserwowane', 'realizuj zamówienie',
            'polityka prywatności', 'odstąpienie od umowy', 'łatwy zwrot', 'kup za punkty',
            'wyszukiwanie zaawansowane', 'jesteś tutaj', 'sprawdź status zamówienia',
            'sposoby płatności', 'dodano do koszyka',
        ] as $needle) {
            if (str_contains($low, $needle)) {
                $hits++;
            }
        }
        if ($hits >= 2) {
            return true;
        }
        // sam slogan sklepu / koszyk
        if ($hits >= 1 && mb_strlen(trim($text)) < 160) {
            return true;
        }

        return (bool) preg_match('/^\s*(0,00\s*zł|suma:)/iu', $text);
    }

    /**
     * @return list<string>
     */
    private function extractDocumentUrls(string $html, string $pageUrl, string $skuNorm, bool $fromManufacturer = false): array
    {
        /** @var list<array{href: string, label: string}> $raw */
        $raw = [];
        if (preg_match_all('#<a\b[^>]*href=["\']([^"\']+\.pdf[^"\']*)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $raw[] = [
                    'href' => html_entity_decode((string) ($row[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'label' => mb_strtolower(trim(strip_tags((string) ($row[2] ?? '')))),
                ];
            }
        }
        if (preg_match_all('#href=["\']([^"\']+\.pdf[^"\']*)["\']#i', $html, $m)) {
            foreach ($m[1] as $href) {
                $raw[] = [
                    'href' => html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'label' => '',
                ];
            }
        }
        if (preg_match_all('#https?://[^"\'\s<>]+\.pdf(?:\?[^"\'\s<>]*)?#i', $html, $m)) {
            foreach ($m[0] as $href) {
                $raw[] = ['href' => (string) $href, 'label' => ''];
            }
        }
        // Ansell: /products/…/pds|doc|ukdoc/…
        if (preg_match_all('#href=["\']([^"\']+/(?:pds|doc|ukdoc)/[^"\']+)["\']#i', $html, $m)) {
            foreach ($m[1] as $href) {
                $raw[] = [
                    'href' => html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'label' => 'document',
                ];
            }
        }
        if (preg_match_all('#href=["\']([^"\']+\.ashx[^"\']*)["\']#i', $html, $m)) {
            foreach ($m[1] as $href) {
                $raw[] = [
                    'href' => html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'label' => '',
                ];
            }
        }

        $out = [];
        $skuTokens = $this->skuTokens($skuNorm);
        foreach ($raw as $item) {
            $abs = $this->absolutize($item['href'], $pageUrl);
            if ($abs === null || ! ProductDocumentDownloader::looksLikeDocumentUrl($abs)) {
                continue;
            }
            $meta = mb_strtolower(urldecode($abs));
            $label = $item['label'];
            $hay = $meta.' '.$label;

            if ($this->looksLikeJunkManufacturerPdf($hay)) {
                continue;
            }

            $matched = $skuNorm !== '' && str_contains($hay, mb_strtolower($skuNorm));
            foreach ($skuTokens as $token) {
                if (str_contains($hay, $token)) {
                    $matched = true;
                    break;
                }
            }
            // „CLIC UP” → plik …CLIC_UP….pdf
            if (! $matched && $this->hayMentionsNameTokens($meta, $skuNorm)) {
                $matched = true;
            }
            // na karcie produktu: „deklaracja / certyfikat / DoC” bez SKU w nazwie pliku
            if (! $matched && $this->looksLikeCertificateDocument($hay)) {
                $matched = true;
            }
            // strona producenta: każdy sensowny PDF (zazwyczaj certyfikat / karta)
            if (! $matched && $fromManufacturer) {
                $matched = true;
            }
            if (! $matched) {
                continue;
            }
            $out[] = $abs;
        }

        return array_values(array_unique($out));
    }

    private function looksLikeCertificateDocument(string $hay): bool
    {
        foreach ([
            'deklarac', 'declaration', 'conformity', 'certyfik', 'certificate',
            'datasheet', 'datenblatt', 'karta-katalog', 'karta_katalog', 'karta produktu',
            'doc_', '_doc', '/doc/', 'doctype', 'ue-type', 'eu-type',
            'examination', 'attestation', 'swiadectw', 'świadectw', 'pdb_', '_pdb',
        ] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Raporty CSR / regulaminy — nie certyfikaty produktu. */
    private function looksLikeJunkManufacturerPdf(string $hay): bool
    {
        foreach ([
            'sustainability', 'nachhaltig', 'annual-report', 'jahresbericht',
            'privacy', 'datenschutz', 'cookie', 'terms-of', 'agb', 'imprint',
            'newsletter', 'press-release', 'investor', 'code-of-conduct',
            'code_of_conduct', 'compliance-report',
        ] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $domains
     */
    private function hostMatchesDomains(string $url, array $domains): bool
    {
        if ($domains === []) {
            return false;
        }
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }
        foreach ($domains as $domain) {
            $d = mb_strtolower(trim($domain));
            if ($d === '') {
                continue;
            }
            if ($host === $d || str_ends_with($host, '.'.$d)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractImageUrls(string $html, string $pageUrl, string $skuNorm): array
    {
        $rawUrls = [];

        if (preg_match_all('#<img\b[^>]*>#i', $html, $imgTags)) {
            foreach ($imgTags[0] as $tag) {
                if (! is_string($tag)) {
                    continue;
                }
                foreach (['src', 'data-src', 'data-lazy-src'] as $attr) {
                    if (preg_match('#\b'.$attr.'=["\']([^"\']+)["\']#i', $tag, $m)) {
                        $rawUrls[] = $m[1];
                    }
                }
                if (preg_match('#\bsrcset=["\']([^"\']+)["\']#i', $tag, $m)) {
                    foreach ($this->parseSrcset($m[1]) as $u) {
                        $rawUrls[] = $u;
                    }
                }
            }
        }

        if (preg_match_all('#srcset=["\']([^"\']+)["\']#i', $html, $sets)) {
            foreach ($sets[1] as $set) {
                foreach ($this->parseSrcset($set) as $u) {
                    $rawUrls[] = $u;
                }
            }
        }

        // Galerie JS często przechowują pełny obraz poza znacznikiem <img>.
        if (preg_match_all(
            '#\b(?:data-big|data-full|data-image|data-zoom-image)=["\']([^"\']+)["\']#i',
            $html,
            $galleryImages
        )) {
            foreach ($galleryImages[1] as $u) {
                $rawUrls[] = $u;
            }
        }

        /** @var list<string> $trustedUrls og:image / JSON-LD — galeria karty, nie wymaga SKU w nazwie pliku */
        $trustedUrls = $this->extractStructuredImageUrls($html);
        foreach ($trustedUrls as $u) {
            $rawUrls[] = $u;
        }

        // Ansell Sitecore: /-/media/.../065g_primary.ashx
        if (preg_match_all('#(?:https?:)?//[^"\'\s<>]+/-/media/[^"\'\s<>]+\.ashx[^"\'\s<>]*#i', $html, $m)) {
            foreach ($m[0] as $u) {
                $rawUrls[] = str_starts_with($u, '//') ? 'https:'.$u : $u;
                $trustedUrls[] = str_starts_with($u, '//') ? 'https:'.$u : $u;
            }
        }
        if (preg_match_all('#/-/media/[^"\'\s<>]+\.ashx[^"\'\s<>]*#i', $html, $m)) {
            foreach ($m[0] as $path) {
                $rawUrls[] = $path;
                $trustedUrls[] = $path;
            }
        }
        // Magento / JSON galeria (często escaped \/ )
        if (preg_match_all('#https?:\\\\?/\\\\?/[^"\'\s<>]+/(?:media/catalog/product|media/wysiwyg)/[^"\'\s<>]+\.(?:jpe?g|png|webp)#i', $html, $m)) {
            foreach ($m[0] as $u) {
                $rawUrls[] = str_replace('\\/', '/', $u);
            }
        }
        if (preg_match_all('#https?://[^"\'\s<>]+/media/catalog/product/[^"\'\s<>]+\.(?:jpe?g|png|webp)#i', $html, $m)) {
            foreach ($m[0] as $u) {
                $rawUrls[] = $u;
            }
        }
        // PrestaShop: /34818-large_default/nazwa.jpg
        if (preg_match_all('#https?://[^"\'\s<>]+/\d+-(?:large_default|medium_default|home_default|pdt_\d+)/[^"\'\s<>]+\.(?:jpe?g|png|webp)#i', $html, $m)) {
            foreach ($m[0] as $u) {
                $rawUrls[] = $u;
                if (str_contains(mb_strtolower($u), 'large_default')) {
                    $trustedUrls[] = $u;
                }
            }
        }

        if ($skuNorm !== '' && preg_match_all(
            '#(/[^"\'\s<>]*'.preg_quote($skuNorm, '#').'[^"\'\s<>]*\.(?:jpe?g|png|webp|gif)(?:\.webp)?)#i',
            $html,
            $m
        )) {
            foreach ($m[1] as $path) {
                $rawUrls[] = $path;
            }
        }

        $skuTokens = $this->skuTokens($skuNorm);
        $trustedAbs = [];
        foreach ($trustedUrls as $t) {
            $a = $this->absolutize(trim((string) $t), $pageUrl);
            if ($a !== null) {
                $trustedAbs[mb_strtolower($a)] = true;
            }
        }
        $pageHost = mb_strtolower((string) (parse_url($pageUrl, PHP_URL_HOST) ?? ''));

        $candidates = [];
        foreach ($rawUrls as $src) {
            if (! is_string($src) || $src === '') {
                continue;
            }
            $src = trim(explode(' ', trim($src))[0] ?? '');
            $abs = $this->absolutize($src, $pageUrl);
            if ($abs === null || $this->isJunkImageUrl($abs) || ! ProductImageDownloader::looksLikeImageUrl($abs)) {
                continue;
            }
            $meta = mb_strtolower($abs);
            // WordPress thumbs: -80x80, -150x150…
            if (preg_match('/-(\d{2,4})x(\d{2,4})\.(jpe?g|png|webp)(\?|$)/i', $meta, $wm)
                && (((int) $wm[1] < 400) || ((int) $wm[2] < 400))) {
                continue;
            }
            // uvex imgproxy miniatury /w:60/h:60/
            if (preg_match('#/w:(\d+)/h:(\d+)/#i', $meta, $wm)
                && (((int) $wm[1] < 200) || ((int) $wm[2] < 200))) {
                continue;
            }
            // PrestaShop miniatury kolorów / thumbs
            if (preg_match('#/\d+-(small_default|cart_default|pdt_180)/#i', $meta)) {
                continue;
            }
            $score = 5;
            $skuInUrl = false;
            $trusted = isset($trustedAbs[$meta]);
            if ($trusted) {
                $score += 100;
                $skuInUrl = true;
            }
            if ($skuNorm !== '' && $this->metaContainsSkuToken($meta, $skuNorm)) {
                $score += 120;
                $skuInUrl = true;
            }
            foreach ($skuTokens as $token) {
                if ($this->metaContainsSkuToken($meta, $token)) {
                    $score += 80;
                    $skuInUrl = true;
                    break;
                }
            }
            if ($this->hayHasLongerAlphanumericSkuVariant($meta.' '.$pageUrl, $skuNorm)) {
                continue;
            }
            // PROS-101… ↔ …pros-101s-34…
            if (! $skuInUrl && $this->pageMentionsBrandModelSku($meta, preg_replace('/[^a-z0-9]+/i', '', $meta) ?? $meta, $skuNorm)) {
                $score += 70;
                $skuInUrl = true;
            }
            // Inny kod art. — na karcie z dwoma kodami (9-003B/7-003B) galeria może mieć jeden z nich
            $pageHasOurCore = $skuTokens !== [] && $this->containsArtCode(mb_strtolower($pageUrl), $skuTokens[0] ?? '');
            if (! $pageHasOurCore && $this->urlLooksLikeWrongSku($meta, $skuNorm, $skuTokens)) {
                continue;
            }
            if (str_contains($meta, 'pim/products') || str_contains($meta, 'product_detail') || str_contains($meta, 'media/catalog/product') || str_contains($meta, 'shop-media') || str_contains($meta, 'zdjecia-safety') || str_contains($meta, 'trzewiki') || str_contains($meta, 'polbuty')) {
                $score += 50;
                $skuInUrl = true;
            }
            // menu / kafle nawigacji uvex (nie zdjęcie produktu) — też w base64 imgproxy
            $decodedMeta = $meta;
            if (preg_match('#/([A-Za-z0-9_\-+/=]{24,})$#', (string) (parse_url($abs, PHP_URL_PATH) ?? ''), $bm)) {
                $raw = strtr($bm[1], '-_', '+/');
                $pad = strlen($raw) % 4;
                if ($pad > 0) {
                    $raw .= str_repeat('=', 4 - $pad);
                }
                $dec = base64_decode($raw, true);
                if (is_string($dec) && $dec !== '') {
                    $decodedMeta .= ' '.mb_strtolower($dec);
                }
            }
            if (preg_match('#(menu-|menue-|/01_menue|menue-pics|menu-neuheit|menukachel|favicon)#i', $decodedMeta) === 1) {
                continue;
            }
            // uvex shop-media na karcie produktu — prawdziwa galeria
            if (str_contains($meta, 'shop-media')) {
                $score += 120;
                $skuInUrl = true;
                $trusted = true;
            }
            // PrestaShop galeria produktu
            if (preg_match('#/\d+-(large_default|medium_default|home_default|pdt_\d+)/#i', $meta)) {
                $score += 85;
                $skuInUrl = true;
            }
            if (str_contains($meta, 'large_default')) {
                $score += 40;
            }
            if (str_contains($meta, 'product_detail_large') || str_contains($meta, '_hr.')) {
                $score += 40;
            }
            if (preg_match('/_[sm]\.(jpe?g|png|webp)(\?|$)/i', $meta)) {
                $score -= 25; // miniatury Demar _s/_m
            }
            if (str_contains($meta, 'thumb') || str_contains($meta, 'thumbnail') || str_ends_with($meta, '.gif') || str_contains($meta, '.gif?')) {
                $score -= 60;
            }
            if (str_contains($meta, 'logo') || str_contains($meta, 'fav') || str_contains($meta, 'piktogram') || str_contains($meta, 'social-media') || str_contains($meta, 'manufacturer-logo') || str_contains($meta, '/img/m/')) {
                continue;
            }
            // ten sam host co karta + slug produktu w nazwie pliku
            $imgHost = mb_strtolower((string) (parse_url($abs, PHP_URL_HOST) ?? ''));
            if ($pageHost !== '' && $imgHost === $pageHost && $this->imageSlugOverlapsPage($meta, mb_strtolower($pageUrl))) {
                $score += 45;
                $skuInUrl = true;
            }
            // Bez śladu SKU w URL pozostaw jako słaby kandydat. Trafność obrazu
            // zweryfikuje później AI Vision na podstawie produktu i kontekstu karty.
            if (! $skuInUrl && ! $pageHasOurCore && ! $trusted) {
                $score = min($score, 1);
            }
            if ($score > 0) {
                $candidates[] = ['url' => $abs, 'score' => $score];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $out = [];
        foreach ($candidates as $row) {
            $out[] = $row['url'];
        }

        return array_slice(array_values(array_unique($out)), 0, 6);
    }

    /**
     * @return list<string>
     */
    private function extractStructuredImageUrls(string $html): array
    {
        $urls = [];
        foreach ([
            '#property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']#i',
            '#content=["\']([^"\']+)["\'][^>]*property=["\']og:image["\']#i',
        ] as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $url) {
                    $urls[] = $url;
                }
            }
        }

        if (preg_match_all('#"image"\s*:\s*\[([^\]]+)\]#is', $html, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('#https?://[^"\'\s]+#i', (string) $block, $matches)) {
                    foreach ($matches[0] as $url) {
                        $urls[] = $url;
                    }
                }
            }
        }
        if (preg_match_all('#"image"\s*:\s*"(https?://[^"]+)"#i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<string>
     */
    private function parseSrcset(string $srcset): array
    {
        $out = [];
        foreach (explode(',', $srcset) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $url = trim(explode(' ', $part)[0] ?? '');
            if ($url !== '') {
                $out[] = $url;
            }
        }

        return $out;
    }

    private function isJunkImageUrl(string $url): bool
    {
        $u = mb_strtolower($url);
        foreach ([
            'logo', 'icon', 'sprite', 'favicon', 'banner', 'payment',
            'dhl', 'inpost', 'poczta', 'ups', 'fedex', 'dpd', 'gls',
            'cart', 'koszyk', 'wallet', 'payu', 'przelewy', 'blik',
            'ochronki na buty', 'shoe-cover', 'shoe_cover', 'nakladki', 'folie-na',
            'placeholder', 'blank', 'pixel', 'bg_environment', 'environment_oily', '.svg',
            // placeholdery Magento / lazy-load
            'loader', 'spinner', 'loading', 'preloader', 'ajax-loader', 'load.gif',
            'loading.gif', 'loader-1', 'loader-2', 'progress.gif',
            'related', 'upsell', 'crosssell', 'widget',
            'piktogram', 'social-media', 'logo-social', 'btn-youtube', '/_icons/',
            // uvex menu / kafle / mapy — nie produkt
            'menue-', 'menu-', '/01_menue', 'menue-pics', 'menu_pics', 'flag',
            'sustainability', 'bamboo-twinflex', 'world-map', 'sitemap',
        ] as $needle) {
            if (str_contains($u, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function extractOgDescription(string $html): string
    {
        if (preg_match('#property=["\']og:description["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $m)
            || preg_match('#content=["\']([^"\']+)["\'][^>]*property=["\']og:description["\']#i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function containsArtCode(string $hay, string $core): bool
    {
        $core = mb_strtolower(trim($core));
        if ($core === '') {
            return false;
        }
        if (preg_match('/(?<![0-9])'.preg_quote($core, '/').'(?![0-9])/u', $hay) === 1) {
            return true;
        }
        $compact = str_replace('-', '', $core);

        return preg_match('/(?<![0-9])'.preg_quote($compact, '/').'(?![0-9])/u', $hay) === 1;
    }

    private function hayMentionsNameTokens(string $hay, string $skuNorm): bool
    {
        $parts = preg_split('/[\s\-·\/_]+/u', mb_strtolower(trim($skuNorm))) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && mb_strlen($part) >= 2) {
                $tokens[] = $part;
            }
        }
        if ($tokens === []) {
            return false;
        }
        $normSet = ['s1', 's2', 's3', 'src', 'hro', 'o1', 'o2', 'fo', 'ci', 'hi', 'wr', 'an'];
        $nameTokens = array_values(array_filter($tokens, static fn (string $t): bool => ! in_array($t, $normSet, true)));
        $normTokens = array_values(array_filter($tokens, static fn (string $t): bool => in_array($t, $normSet, true)));
        if ($nameTokens === []) {
            $nameTokens = $tokens;
            $normTokens = [];
        }
        $hayCompact = preg_replace('/[^a-z0-9]+/i', '', $hay) ?? $hay;
        foreach ($nameTokens as $token) {
            if (! str_contains($hay, $token) && ! str_contains($hayCompact, $token)) {
                return false;
            }
        }
        if ($normTokens === []) {
            return true;
        }
        $hits = 0;
        foreach ($normTokens as $token) {
            if (str_contains($hay, $token) || str_contains($hayCompact, $token)) {
                $hits++;
            }
        }

        return $hits >= max(1, (int) ceil(count($normTokens) * 0.5));
    }

    /**
     * @return list<string>
     */
    private function skuTokens(string $skuNorm): array
    {
        $tokens = [];
        if (preg_match('/\d{1,2}-\d{3}/', $skuNorm, $m)) {
            $tokens[] = $m[0]; // 9-084
            $tokens[] = str_replace('-', '', $m[0]); // 9084
        }
        if (preg_match('/\b(\d{4,})\b/', $skuNorm, $m)) {
            $tokens[] = $m[1];
        }
        // PROS-101-S1-MAX → 101
        if (preg_match('/(?:^|[\-_])(\d{2,4})(?:[\-_]|$)/', $skuNorm, $m)) {
            $tokens[] = $m[1];
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * SKU „PROS-101-S1-MAX” vs strona „pros 101/S” / „pros-101s-34”.
     */
    private function pageMentionsBrandModelSku(string $hay, string $hayCompact, string $skuNorm): bool
    {
        $parts = preg_split('/[\s\-_\/]+/u', mb_strtolower(trim($skuNorm))) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => mb_strlen($p) >= 2));
        if (count($parts) < 2) {
            return false;
        }
        $brand = null;
        $model = null;
        foreach ($parts as $part) {
            if ($brand === null && preg_match('/^[a-z]{2,}$/u', $part) === 1) {
                $brand = $part;
            }
            if ($model === null && preg_match('/^\d{2,4}[a-z]?$/u', $part) === 1) {
                $model = $part;
            }
        }
        if ($brand === null || $model === null) {
            return false;
        }
        $modelDigits = preg_replace('/\D+/u', '', $model) ?? $model;
        $hasBrand = str_contains($hay, $brand) || str_contains($hayCompact, $brand);
        $hasModel = str_contains($hay, $model)
            || ($modelDigits !== '' && (
                preg_match('/(?<![0-9])'.preg_quote($modelDigits, '/').'(?![0-9])/u', $hay) === 1
                || str_contains($hayCompact, $modelDigits)
            ));

        return $hasBrand && $hasModel;
    }

    private function imageSlugOverlapsPage(string $imageUrl, string $pageUrl): bool
    {
        $imgPath = (string) (parse_url($imageUrl, PHP_URL_PATH) ?? '');
        $pagePath = (string) (parse_url($pageUrl, PHP_URL_PATH) ?? '');
        $imgBase = mb_strtolower((string) pathinfo($imgPath, PATHINFO_FILENAME));
        $pageBase = mb_strtolower(basename($pagePath));
        if ($imgBase === '' || $pageBase === '') {
            return false;
        }
        $imgBits = array_values(array_filter(
            preg_split('/[\s\-_]+/u', $imgBase) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 4
        ));
        $hits = 0;
        foreach ($imgBits as $bit) {
            if (str_contains($pageBase, $bit)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    /** NB27 w tekście/URL z NB27B/NB27S — inny wariant produktu. */
    private function hayHasLongerAlphanumericSkuVariant(string $hay, string $skuNorm): bool
    {
        $skuCompact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower(trim($skuNorm))) ?? '';
        if ($skuCompact === '' || mb_strlen($skuCompact) < 3 || ! $this->isAlphanumericSkuCode($skuCompact)) {
            return false;
        }

        return preg_match(
            '/(?<![a-z0-9])'.preg_quote($skuCompact, '/').'[a-z0-9]+/iu',
            mb_strtolower($hay)
        ) === 1;
    }

    private function isAlphanumericSkuCode(string $token): bool
    {
        $compact = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($token)) ?? '';
        if ($compact === '' || mb_strlen($compact) < 3) {
            return false;
        }

        return preg_match('/[a-z]/u', $compact) === 1
            && preg_match('/\d/u', $compact) === 1;
    }

    private function metaContainsSkuToken(string $meta, string $token): bool
    {
        $token = mb_strtolower(trim($token));
        if ($token === '') {
            return false;
        }
        $compact = preg_replace('/[^a-z0-9]+/iu', '', $token) ?? $token;
        if ($this->isAlphanumericSkuCode($compact)) {
            return preg_match('/(?<![a-z0-9])'.preg_quote($token, '/').'(?![a-z0-9])/iu', $meta) === 1
                || preg_match('/(?<![a-z0-9])'.preg_quote($compact, '/').'(?![a-z0-9])/iu', $meta) === 1;
        }

        return str_contains($meta, $token);
    }

    /**
     * @param  list<string>  $skuTokens
     */
    private function urlLooksLikeWrongSku(string $urlMeta, string $skuNorm, array $skuTokens): bool
    {
        if ($skuTokens === []) {
            return false;
        }
        if (! preg_match_all('/\b(\d{1,2}-\d{3})\b/', $urlMeta, $m)) {
            return false;
        }
        foreach ($m[1] as $found) {
            $ok = false;
            foreach ($skuTokens as $token) {
                if ($found === $token || str_replace('-', '', $found) === $token) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok && ! str_contains($skuNorm, $found)) {
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
            $url = 'https:'.$url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        $parts = parse_url($base);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }
        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }
        $path = $parts['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';

        return $origin.$dir.$url;
    }
}
