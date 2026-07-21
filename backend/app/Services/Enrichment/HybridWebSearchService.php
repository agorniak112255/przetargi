<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Exceptions\TavilyQuotaExceededException;
use App\Models\Product;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class HybridWebSearchService
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly OpenAiCompatibleClient $llm,
        private readonly ManufacturerDomainResolver $manufacturers,
        private readonly ProductSearchIdentity $identity,
    ) {}

    /**
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     images: list<string>,
     *     provider: string,
     *     raw_content: ?string
     * }
     */
    public function searchProduct(Product $product, string $phase = 'manufacturer'): array
    {
        $queries = $this->buildQueries($product, $phase);
        $cfg = $this->settings->resolve();
        $profile = $this->settings->tavilySearchProfile();
        $errors = [];
        $mfrDomains = $this->manufacturers->domainsFor($product);
        $preferred = $phase === 'manufacturer' && $mfrDomains !== []
            ? $mfrDomains
            : $this->preferredDomains();
        $merged = [];
        $images = [];
        $seen = [];
        $seenImages = [];
        $provider = 'tavily';

        // Limit zapytań i próg stopu zależą od trybu Tavily (Ustawienia AI).
        $queryIndex = 0;
        foreach (array_slice($queries, 0, $profile->maxQueries) as $query) {
            $cacheKey = 'enrich_search_v15:'.hash('sha256', $profile->mode.'|'.$phase.'|'.$query);
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['results']) && is_array($cached['results'])) {
                $packResults = $this->filterResultsByIdentity($cached['results'], $product);
                $provider = (string) ($cached['provider'] ?? 'tavily_cache');
                foreach ($this->normalizeImageList($cached['images'] ?? []) as $img) {
                    $ik = mb_strtolower($img);
                    if (! isset($seenImages[$ik])) {
                        $seenImages[$ik] = true;
                        $images[] = $img;
                    }
                }
            } else {
                $packResults = [];
                $packImages = [];
                try {
                    // Pierwsze zapytanie (jak Google): najpierw całe internety — mniej spalone kredytów na pustych domenach.
                    $openFirst = $queryIndex === 0 && $profile->openWebFallback;
                    if ($openFirst) {
                        // include_images=false — Tavily images = śmietnik (LEGO/piwo)
                        $pack = $this->searchViaTavily($query, [], $profile, false);
                        $packResults = $this->filterResultsByIdentity($pack['results'], $product);
                        $provider = 'tavily';
                    }
                    // manufacturer → domeny producenta; industry → sklepy+katalogi
                    if ($packResults === [] && $preferred !== []) {
                        $pack = $this->searchViaTavily($query, $preferred, $profile, false);
                        $packResults = $this->filterResultsByIdentity($pack['results'], $product);
                        $packResults = array_values(array_filter(
                            $packResults,
                            fn (array $row): bool => $this->resultQuality($row, $product) >= 30
                        ));
                        $provider = $phase === 'manufacturer' ? 'tavily_manufacturer' : 'tavily_preferred';
                    }
                    if ($packResults === [] && $profile->retailerFallback
                        && $phase === 'manufacturer' && $mfrDomains !== []) {
                        // brak na stronie producenta → sklepy (opis), nie PDF
                        $pack = $this->searchViaTavily($query, $this->retailerDomains(), $profile, false);
                        $packResults = $this->filterResultsByIdentity($pack['results'], $product);
                        $provider = 'tavily_retailer';
                    }
                    if ($packResults === [] && $profile->openWebFallback && ! $openFirst) {
                        $pack = $this->searchViaTavily($query, [], $profile, false);
                        $packResults = $this->filterResultsByIdentity($pack['results'], $product);
                        $provider = 'tavily';
                    }
                } catch (TavilyQuotaExceededException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }

                foreach ($this->normalizeImageList($packImages) as $img) {
                    $ik = mb_strtolower($img);
                    if (! isset($seenImages[$ik])) {
                        $seenImages[$ik] = true;
                        $images[] = $img;
                    }
                }

                if ($packResults !== []) {
                    Cache::put($cacheKey, [
                        'results' => $packResults,
                        'images' => array_slice($this->normalizeImageList($packImages), 0, 12),
                        'provider' => $provider,
                    ], now()->addDays($profile->cacheDays));
                }
            }

            foreach ($packResults as $row) {
                $key = mb_strtolower($row['url']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $row;
            }

            if (count($merged) >= $profile->stopAfterResults) {
                break;
            }
            $queryIndex++;
        }

        if ($merged === [] && $cfg['web_search_enabled']) {
            try {
                $pack = $this->searchViaAiWeb($queries[0] ?? (string) $product->sku, $product, $phase);
                $merged = $this->filterResultsByIdentity($pack['results'], $product);
                $provider = 'ai_web_search';
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($merged === []) {
            $bare = $this->identity->stripBrandPrefix(
                (string) $product->sku,
                $this->identity->shortBrand((string) $product->manufacturer)
            );
            throw new RuntimeException(
                'Brak stron produktu (SKU '.$product->sku
                .($bare !== '' && $bare !== $product->sku ? ' / '.$bare : '')
                .'). '
                .($errors !== [] ? implode(' | ', array_slice($errors, 0, 2)) : '')
            );
        }

        return [
            'results' => array_slice($merged, 0, 8),
            'images' => array_slice($images, 0, 12),
            'provider' => $provider,
            'raw_content' => null,
        ];
    }

    /**
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     images: list<string>,
     *     errors: list<string>
     * }
     */
    public function searchBothPhases(Product $product): array
    {
        $merged = [];
        $images = [];
        $seen = [];
        $seenImages = [];
        $errors = [];
        $profile = $this->settings->tavilySearchProfile();

        // full: obie fazy zawsze; eco/balanced: druga faza tylko gdy pierwsza nic nie dała
        foreach (['manufacturer', 'industry'] as $phase) {
            if ($phase === 'industry' && ! $profile->bothPhasesAlways && $merged !== []) {
                break;
            }

            try {
                $pack = $this->searchProduct($product, $phase);
            } catch (TavilyQuotaExceededException $e) {
                throw $e;
            } catch (Throwable $e) {
                $errors[] = $phase.': '.$e->getMessage();
                Log::warning('Product search phase failed', [
                    'product_id' => $product->id,
                    'phase' => $phase,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($pack['results'] as $row) {
                $key = mb_strtolower($row['url']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $row;
            }

            foreach ($this->normalizeImageList($pack['images'] ?? []) as $img) {
                $ik = mb_strtolower($img);
                if (isset($seenImages[$ik])) {
                    continue;
                }
                $seenImages[$ik] = true;
                $images[] = $img;
            }
        }

        usort($merged, function (array $a, array $b) use ($product): int {
            $scoreA = $this->resultQuality($a, $product)
                + ($this->manufacturers->isManufacturerUrl((string) ($a['url'] ?? ''), $product) ? 80 : 0);
            $scoreB = $this->resultQuality($b, $product)
                + ($this->manufacturers->isManufacturerUrl((string) ($b['url'] ?? ''), $product) ? 80 : 0);

            return $scoreB <=> $scoreA;
        });

        return [
            'results' => array_slice($merged, 0, 8),
            'images' => array_slice($images, 0, 12),
            'errors' => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildQueries(Product $product, string $phase): array
    {
        $queries = $this->identity->searchQueries($product, $phase);
        // zachowaj też stare frazy „7-003 B S1 SRC” (obuwie z normami w nazwie)
        $legacy = $this->legacySafetyShoePhrase($product);
        if ($legacy !== '') {
            $mfr = $this->identity->shortBrand((string) $product->manufacturer);
            array_unshift($queries, trim('"'.$legacy.'" '.$mfr.' buty ochronne'));
        }

        return $queries !== [] ? array_values(array_unique($queries)) : [trim((string) $product->sku)];
    }

    /** Preferuj nazwę z normami („7-003 B S1 SRC”) zamiast SKU z kodem katalogowym. */
    private function legacySafetyShoePhrase(Product $product): string
    {
        $name = trim((string) $product->name);
        $sku = trim((string) $product->sku);

        if ($name !== '' && preg_match('/\d{1,2}-\d{3}/', $name)
            && preg_match('/\b(S1|S2|S3|SRC|HRO|P|FO|CI|HI)\b/i', $name)) {
            return $name;
        }
        if (preg_match('/^(\d{1,2}-\d{3}(?:\s+[A-Za-z])?(?:\s+(?:S1|S2|S3|SRC|HRO|P|FO|CI|HI))+)/iu', $sku, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(\d{1,2}-\d{3}(?:\s+[A-Za-z])?)/u', $sku, $m)) {
            if ($name !== '' && preg_match_all('/\b(S1|S2|S3|SRC|HRO|P|FO|CI|HI)\b/i', $name, $norms)) {
                return trim($m[1].' '.implode(' ', array_unique($norms[0])));
            }

            return $m[1];
        }

        return '';
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $results
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function filterResultsByIdentity(array $results, Product $product): array
    {
        $matched = [];
        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hay = mb_strtolower(
                ($row['url'] ?? '').' '.($row['title'] ?? '').' '.($row['snippet'] ?? '')
            );
            $url = (string) $row['url'];
            $title = (string) ($row['title'] ?? '');

            if (! $this->identity->hayMentionsProduct($hay, $product)) {
                continue;
            }
            // Kod/model musi być w URL lub tytule — sam snippet (blog/listing) nie wystarczy
            if (! $this->identity->coreInUrlOrTitle($url, $title, $product)) {
                continue;
            }
            if (preg_match('#(ochronki na buty|shoe[- ]?cover|folie na buty|nakladki na obuwie)#i', $hay)) {
                continue;
            }
            // Strona producenta z modelem w URL (np. ansell.com/…/ringers-r065) — bez „glove” w snippecie.
            $fromManufacturer = $this->manufacturers->isManufacturerUrl($url, $product);
            if (! $fromManufacturer && ! preg_match(
                '#(glove|r[eę]kaw|rekaw|ringers|ansell|maxiflex|maxicut|maxidry|maxifoam|atg|demar|uvex|pros|bhp|ochron|ppe|en\s*388|buty|trzewik|p[oó]łbut|obuwie|shoe|boot|wodoochron|plavitex|ubranie|kurtka|spodnie|odzież|odziez|\bs1\b|\bs3\b|\bsrc\b|\bo1\b|\bo2\b|\bfo\b)#iu',
                $hay.' '.$url.' '.$title
            )) {
                continue;
            }
            if ($this->isListingWithoutProduct($url, $product)) {
                continue;
            }
            $matched[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'snippet' => (string) ($row['snippet'] ?? ''),
            ];
        }

        usort($matched, fn (array $a, array $b): int => $this->resultQuality($b, $product) <=> $this->resultQuality($a, $product));

        return $matched;
    }

    private function isListingWithoutProduct(string $url, Product $product): bool
    {
        $u = mb_strtolower($url);
        if ($this->identity->coreInUrlOrTitle($u, '', $product)) {
            return false;
        }
        foreach ([
            '/manufacturer/', '/producent/', '/brand/', '/marka/',
            '/category/', '/kategoria/', '/kategorie/', '/collection/',
            '/search', '/szukaj', '/catalog/', '/katalog/', '/blog/',
        ] as $needle) {
            if (str_contains($u, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** „7-003” nie może trafić w „97-003”. */
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

    /**
     * SKU tekstowe: „clic up o1 fo src” ≈ URL „…clic_up…o1_fo_src…” / tytuł „CLIC UP”.
     * Wymaga wszystkich tokenów nazwy + ≥50% norm (O1/SRC/…).
     */
    private function hayMentionsSkuTokens(string $hay, string $skuNorm): bool
    {
        $parts = preg_split('/[\s\-·\/_]+/u', mb_strtolower(trim($skuNorm))) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            $tokens[] = $part;
        }
        if ($tokens === []) {
            return false;
        }

        $normSet = ['s1', 's2', 's3', 'src', 'hro', 'o1', 'o2', 'fo', 'ci', 'hi', 'wr', 'an'];
        $nameTokens = [];
        $normTokens = [];
        foreach ($tokens as $token) {
            if (in_array($token, $normSet, true)) {
                $normTokens[] = $token;
            } else {
                $nameTokens[] = $token;
            }
        }
        if ($nameTokens === []) {
            $nameTokens = $tokens;
            $normTokens = [];
        }

        $hayCompact = preg_replace('/[^a-z0-9]+/i', '', $hay) ?? $hay;
        foreach ($nameTokens as $token) {
            if (preg_match('/^\d{3,}$/', $token) === 1) {
                // 1000 ≠ 1000g
                $ok = preg_match('/(?<![0-9])'.preg_quote($token, '/').'(?![0-9a-z])/iu', $hay) === 1
                    || preg_match('/(?<![0-9])'.preg_quote($token, '/').'(?![0-9a-z])/iu', $hayCompact) === 1;
                if (! $ok) {
                    return false;
                }

                continue;
            }
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
     * @param  array{url: string, title?: string, snippet?: string}  $row
     */
    private function resultQuality(array $row, Product $product): int
    {
        $url = mb_strtolower($row['url'] ?? '');
        $title = mb_strtolower((string) ($row['title'] ?? ''));
        $score = 0;
        if ($this->identity->coreInUrlOrTitle($url, '', $product)) {
            $score += 100;
        }
        if ($this->identity->coreInUrlOrTitle('', $title, $product)) {
            $score += 40;
        }
        if (str_contains($url, 'produkt') || str_contains($url, 'product') || str_contains($url, '/sklep/')) {
            $score += 20;
        }
        $brand = mb_strtolower($this->identity->shortBrand((string) $product->manufacturer));
        if ($brand !== '' && (str_contains($url, $brand) || str_contains($title, $brand))) {
            $score += 25;
        }

        return $score;
    }

    /**
     * SKU „9-075 S3 SRC HRO” ≈ tytuł „9-075 S3 HRO SRC” (inna kolejność norm).
     */
    private function hayMentionsSku(string $hay, string $skuNorm): bool
    {
        if ($skuNorm === '') {
            return false;
        }
        if (str_contains($hay, $skuNorm)) {
            return true;
        }
        $skuLoose = preg_replace('/\s+/u', '', $skuNorm) ?? $skuNorm;
        $hayLoose = preg_replace('/\s+/u', '', $hay) ?? $hay;
        if ($skuLoose !== '' && str_contains($hayLoose, $skuLoose)) {
            return true;
        }
        $skuCompact = preg_replace('/[^a-z0-9]/i', '', $skuNorm) ?? $skuNorm;
        $hayCompact = preg_replace('/[^a-z0-9]/i', '', $hay) ?? $hay;
        if ($skuCompact !== '' && str_contains($hayCompact, $skuCompact)) {
            return true;
        }

        // Nazwa handlowa bez kodu art. (CLIC UP, BOLT UP, MaxiFlex…)
        if (! preg_match('/\b(\d{1,2}-\d{3})\b/', $skuNorm, $m)) {
            return $this->hayMentionsSkuTokens($hay, $skuNorm);
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
            // litery/normy (S3, HRO, B) — nie cyfry ze SKU (42, 844)
            if (preg_match('/^[a-z][a-z0-9]*$/i', $part) === 1) {
                $extras[] = mb_strtolower($part);
            }
        }
        if ($extras === []) {
            return true;
        }
        $hits = 0;
        foreach ($extras as $token) {
            if (str_contains($hay, $token) || str_contains($hayCompact, $token)) {
                $hits++;
            }
        }

        return $hits >= max(1, (int) ceil(count($extras) * 0.5));
    }

    /**
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     provider: string,
     *     raw_content: ?string
     * }
     */
    private function searchViaAiWeb(string $query, Product $product, string $phase): array
    {
        $prompt = <<<PROMPT
Znajdź kartę produktu BHP ze SKU {$product->sku} ({$product->manufacturer} {$product->name}).
Zapytanie: {$query}. Zwróć tylko URL stron z tym kodem produktu.
PROMPT;

        $response = $this->llm->responsesWithWebSearch($prompt, 20);
        $results = [];

        foreach ($response['citations'] as $citation) {
            $results[] = [
                'url' => $citation['url'],
                'title' => $citation['title'],
                'snippet' => '',
            ];
        }

        if ($results === []) {
            foreach ($this->extractUrlsFromText($response['content']) as $url) {
                $results[] = [
                    'url' => $url,
                    'title' => $url,
                    'snippet' => '',
                ];
            }
        }

        if ($results === []) {
            throw new RuntimeException('AI web_search nie zwróciło URL-i.');
        }

        return [
            'results' => array_slice($results, 0, 5),
            'provider' => 'ai_web_search',
            'raw_content' => $response['content'],
        ];
    }

    /**
     * @param  list<string>  $includeDomains
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     images: list<string>,
     *     provider: string,
     *     raw_content: ?string
     * }
     */
    private function searchViaTavily(
        string $query,
        array $includeDomains = [],
        ?TavilySearchProfile $profile = null,
        bool $includeImages = false,
    ): array {
        TavilyQuotaGuard::assertAllowed();

        $cfg = $this->settings->resolve();
        $key = $cfg['tavily_api_key'] ?? null;
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Brak klucza Tavily. Uzupełnij go w Ustawieniach AI.');
        }

        $profile ??= $this->settings->tavilySearchProfile();
        $body = [
            'api_key' => $key,
            'query' => $query,
            'search_depth' => 'basic',
            'include_answer' => false,
            'max_results' => $profile->maxResults,
            'include_images' => $includeImages,
        ];
        if ($includeDomains !== []) {
            $body['include_domains'] = $includeDomains;
        }

        $response = Http::acceptJson()
            ->timeout(12)
            ->connectTimeout(5)
            ->post('https://api.tavily.com/search', $body);

        TavilyQuotaGuard::ensureSuccessful($response);

        $payload = $response->json();
        $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $results[] = [
                'url' => $url,
                'title' => (string) ($row['title'] ?? $url),
                'snippet' => (string) ($row['content'] ?? ''),
            ];
        }

        $images = $includeImages
            ? $this->normalizeImageList($payload['images'] ?? [])
            : [];

        if ($results === [] && $images === []) {
            throw new RuntimeException(
                $includeDomains !== []
                    ? 'Brak wyników w preferowanych sklepach BHP.'
                    : 'Tavily nie zwróciło wyników.'
            );
        }

        return [
            'results' => $results,
            'images' => $images,
            'provider' => $includeDomains !== [] ? 'tavily_preferred' : 'tavily',
            'raw_content' => null,
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeImageList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $url = null;
            if (is_string($item)) {
                $url = $item;
            } elseif (is_array($item)) {
                $url = (string) ($item['url'] ?? $item['src'] ?? '');
            }
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                continue;
            }
            if (! ProductImageDownloader::looksLikeImageUrl($url)) {
                continue;
            }
            $out[] = $url;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function preferredDomains(): array
    {
        return $this->normalizeDomainList(config('enrichment.preferred_domains', []));
    }

    /**
     * @return list<string>
     */
    private function retailerDomains(): array
    {
        $list = $this->normalizeDomainList(config('enrichment.retailer_domains', []));

        return $list !== [] ? $list : $this->preferredDomains();
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeDomainList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $clean = mb_strtolower(trim(preg_replace('#^https?://#i', '', $domain) ?? $domain));
            $clean = rtrim($clean, '/');
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function extractUrlsFromText(string $text): array
    {
        if (preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches) < 1) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $url) {
            $clean = rtrim((string) $url, '.,);]');
            if ($clean !== '') {
                $urls[] = $clean;
            }
        }

        return array_values(array_unique($urls));
    }
}
