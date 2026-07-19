<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

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
    ) {}

    /**
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     provider: string,
     *     raw_content: ?string
     * }
     */
    public function searchProduct(Product $product, string $phase = 'manufacturer'): array
    {
        $queries = $this->buildQueries($product, $phase);
        $cfg = $this->settings->resolve();
        $errors = [];
        $mfrDomains = $this->manufacturers->domainsFor($product);
        $preferred = $phase === 'manufacturer' && $mfrDomains !== []
            ? $mfrDomains
            : $this->preferredDomains();
        $merged = [];
        $seen = [];
        $provider = 'tavily';

        // Max 2 zapytania; jedno Tavily na query (bez podwójnego preferred→global).
        foreach (array_slice($queries, 0, 2) as $query) {
            $cacheKey = 'enrich_search_v11:'.hash('sha256', $phase.'|'.$query);
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['results']) && is_array($cached['results'])) {
                $packResults = $this->filterResultsBySku($cached['results'], (string) $product->sku);
                $provider = (string) ($cached['provider'] ?? 'tavily_cache');
            } else {
                $packResults = [];
                try {
                    // manufacturer → domeny producenta; industry → sklepy+katalogi; potem całe internety
                    if ($preferred !== []) {
                        $pack = $this->searchViaTavily($query, $preferred);
                        $packResults = $this->filterResultsBySku($pack['results'], (string) $product->sku);
                        $packResults = array_values(array_filter(
                            $packResults,
                            fn (array $row): bool => $this->resultQuality($row, (string) $product->sku) >= 40
                        ));
                        $provider = $phase === 'manufacturer' ? 'tavily_manufacturer' : 'tavily_preferred';
                    }
                    if ($packResults === [] && $phase === 'manufacturer' && $mfrDomains !== []) {
                        // brak na stronie producenta → sklepy (opis), nie PDF
                        $pack = $this->searchViaTavily($query, $this->retailerDomains());
                        $packResults = $this->filterResultsBySku($pack['results'], (string) $product->sku);
                        $provider = 'tavily_retailer';
                    }
                    if ($packResults === []) {
                        $pack = $this->searchViaTavily($query, []);
                        $packResults = $this->filterResultsBySku($pack['results'], (string) $product->sku);
                        $provider = 'tavily';
                    }
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }

                if ($packResults !== []) {
                    Cache::put($cacheKey, [
                        'results' => $packResults,
                        'provider' => $provider,
                    ], now()->addDays(7));
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

            // Wystarczy 1–2 trafienia ze SKU — nie ciągnij kolejnych query.
            if (count($merged) >= 1) {
                break;
            }
        }

        if ($merged === [] && $cfg['web_search_enabled']) {
            try {
                $pack = $this->searchViaAiWeb($queries[0] ?? (string) $product->sku, $product, $phase);
                $merged = $this->filterResultsBySku($pack['results'], (string) $product->sku);
                $provider = 'ai_web_search';
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($merged === []) {
            throw new RuntimeException(
                'Brak stron z kodem SKU '.$product->sku.'. '
                .($errors !== [] ? implode(' | ', array_slice($errors, 0, 2)) : '')
            );
        }

        return [
            'results' => array_slice($merged, 0, 8),
            'provider' => $provider,
            'raw_content' => null,
        ];
    }

    /**
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     errors: list<string>
     * }
     */
    public function searchBothPhases(Product $product): array
    {
        $merged = [];
        $seen = [];
        $errors = [];

        // Zawsze obie fazy: producent (karta/PDF) + branża/sklepy (pełniejsze opisy).
        foreach (['manufacturer', 'industry'] as $phase) {
            try {
                $pack = $this->searchProduct($product, $phase);
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
        }

        usort($merged, function (array $a, array $b) use ($product): int {
            $scoreA = $this->resultQuality($a, (string) $product->sku)
                + ($this->manufacturers->isManufacturerUrl((string) ($a['url'] ?? ''), $product) ? 80 : 0);
            $scoreB = $this->resultQuality($b, (string) $product->sku)
                + ($this->manufacturers->isManufacturerUrl((string) ($b['url'] ?? ''), $product) ? 80 : 0);

            return $scoreB <=> $scoreA;
        });

        return [
            'results' => array_slice($merged, 0, 8),
            'errors' => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildQueries(Product $product, string $phase): array
    {
        $phrase = $this->searchPhrase($product);
        $compact = preg_replace('/\s+/u', '', $phrase) ?? $phrase; // 7-003B S1SRC… / sklepy często bez spacji
        $mfr = $this->shortBrand((string) $product->manufacturer);
        $hint = $this->productSearchHint($product);
        // Krótka lista — każde query = 1–2 HTTP do Tavily.
        $queries = array_values(array_unique(array_filter([
            trim('"'.$phrase.'" '.$mfr.' '.$hint),
            $compact !== $phrase ? trim($compact.' '.$mfr.' '.$hint) : '',
            $phase === 'industry'
                ? trim($phrase.' '.$mfr.' '.$hint.' karta produktu')
                : trim($phrase.' '.$mfr.' '.$hint.' datasheet'),
        ])));

        return $queries !== [] ? $queries : [trim((string) $product->sku)];
    }

    /**
     * Preferuj nazwę z normami („7-003 B S1 SRC”) zamiast samego SKU z kodem katalogowym („7-003 B 6060”).
     */
    private function searchPhrase(Product $product): string
    {
        $name = trim((string) $product->name);
        $sku = trim((string) $product->sku);

        if ($name !== '' && preg_match('/\d{1,2}-\d{3}/', $name)
            && preg_match('/\b(S1|S2|S3|SRC|HRO|P|FO|CI|HI)\b/i', $name)) {
            return $name;
        }

        // „7-003 B 6060” → „7-003 B” (odrzuć końcowy kod 4+ cyfr)
        if (preg_match('/^(\d{1,2}-\d{3}(?:\s+[A-Za-z])?(?:\s+(?:S1|S2|S3|SRC|HRO|P|FO|CI|HI))+)/iu', $sku, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(\d{1,2}-\d{3}(?:\s+[A-Za-z])?)/u', $sku, $m)) {
            // dołóż normy z nazwy, jeśli SKU ich nie ma
            if ($name !== '' && preg_match_all('/\b(S1|S2|S3|SRC|HRO|P|FO|CI|HI)\b/i', $name, $norms)) {
                return trim($m[1].' '.implode(' ', array_unique($norms[0])));
            }

            return $m[1];
        }

        return $sku !== '' ? $sku : $name;
    }

    private function productSearchHint(Product $product): string
    {
        $hay = mb_strtolower(
            (string) $product->manufacturer.' '.(string) $product->name.' '.(string) $product->sku
        );
        if (preg_match('#(demar|befado|trzewik|p[oó]łbut|polbut|buty|obuwie|\bs1\b|\bs3\b|\bsrc\b|\bhro\b)#u', $hay)) {
            return 'buty ochronne';
        }
        if (preg_match('#(atg|glove|r[eę]kaw|maxiflex|maxicut|maxidry|ansell)#u', $hay)) {
            return 'rękawice';
        }

        return 'BHP';
    }

    /** „ATG / Maxiflex (…)" → „ATG” */
    private function shortBrand(string $manufacturer): string
    {
        $m = trim($manufacturer);
        if ($m === '') {
            return '';
        }
        $first = trim(explode('/', $m)[0] ?? $m);
        $first = trim(explode('(', $first)[0] ?? $first);

        return mb_substr($first, 0, 40);
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $results
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function filterResultsBySku(array $results, string $sku): array
    {
        $skuNorm = mb_strtolower(trim($sku));
        if ($skuNorm === '') {
            return $results;
        }

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
            $skuMatch = $skuNorm;
            if (preg_match('/^(\d{1,2}-\d{3}(?:\s+[a-z])?)/u', $skuNorm, $sm)) {
                $skuMatch = $sm[1]; // „7-003 b 6060” → „7-003 b”
            }
            if (! $this->hayMentionsSku($hay, $skuNorm) && ! $this->hayMentionsSku($hay, $skuMatch)) {
                continue;
            }
            // Kod art. musi być w URL lub tytule — sam snippet (blog/listing) nie wystarczy
            if (! $this->coreInUrlOrTitle($url, $title, $skuNorm)
                && ! $this->coreInUrlOrTitle($url, $title, $skuMatch)) {
                continue;
            }
            // tylko oczywiste śmieci (nie blokuj „półbuty” / obuwie BHP)
            if (preg_match('#(ochronki na buty|shoe[- ]?cover|folie na buty|nakladki na obuwie)#i', $hay)) {
                continue;
            }
            // odrzuć trafienia poza BHP (FCC, fora aut itd.)
            if (! preg_match(
                '#(glove|r[eę]kaw|rekaw|maxiflex|maxicut|maxidry|maxifoam|atg|demar|bhp|ochron|ppe|en\s*388|buty|trzewik|p[oó]łbut|obuwie|shoe|boot|\bs1\b|\bs3\b|\bsrc\b|\bo1\b|\bo2\b|\bfo\b)#iu',
                $hay.' '.$url.' '.$title
            )) {
                continue;
            }
            // listing producenta/kategorii bez SKU w ścieżce — nie karta produktu
            if ($this->isListingWithoutSku($url, $skuNorm)) {
                continue;
            }
            $matched[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'snippet' => (string) ($row['snippet'] ?? ''),
            ];
        }

        usort($matched, function (array $a, array $b) use ($skuNorm): int {
            return $this->resultQuality($b, $skuNorm) <=> $this->resultQuality($a, $skuNorm);
        });

        return $matched;
    }

    private function isListingWithoutSku(string $url, string $skuNorm): bool
    {
        $u = mb_strtolower($url);
        $core = null;
        if (preg_match('/\b(\d{1,2}-\d{3})\b/', mb_strtolower($skuNorm), $m)) {
            $core = $m[1];
        }
        if ($core !== null && $this->containsArtCode($u, $core)) {
            return false;
        }
        // nazwa handlowa w ścieżce = karta produktu, nie listing
        if ($core === null && $this->hayMentionsSkuTokens($u, mb_strtolower($skuNorm))) {
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

    private function coreInUrlOrTitle(string $url, string $title, string $skuNorm): bool
    {
        $skuNorm = mb_strtolower(trim($skuNorm));
        $hay = mb_strtolower($url.' '.$title);
        if ($skuNorm !== '' && str_contains($hay, $skuNorm)) {
            return true;
        }
        if (! preg_match('/\b(\d{1,2}-\d{3})\b/', $skuNorm, $m)) {
            // nazwa handlowa: „clic up o1 fo src” w URL clic_up_…_o1_fo_src
            return $this->hayMentionsSkuTokens($hay, $skuNorm);
        }

        return $this->containsArtCode($hay, $m[1]);
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
    private function resultQuality(array $row, string $skuNorm): int
    {
        $url = mb_strtolower($row['url'] ?? '');
        $title = mb_strtolower((string) ($row['title'] ?? ''));
        $score = 0;
        if (preg_match('/\b(\d{1,2}-\d{3})\b/', mb_strtolower($skuNorm), $m)) {
            $core = $m[1];
            if ($this->containsArtCode($url, $core)) {
                $score += 100;
            }
            if ($this->containsArtCode($title, $core)) {
                $score += 40;
            }
        }
        if (str_contains($url, 'produkt') || str_contains($url, 'product') || str_contains($url, '/sklep/')) {
            $score += 20;
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
     *     provider: string,
     *     raw_content: ?string
     * }
     */
    private function searchViaTavily(string $query, array $includeDomains = []): array
    {
        $cfg = $this->settings->resolve();
        $key = $cfg['tavily_api_key'] ?? null;
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Brak klucza Tavily. Uzupełnij go w Ustawieniach AI.');
        }

        $body = [
            'api_key' => $key,
            'query' => $query,
            'search_depth' => 'basic',
            'include_answer' => false,
            'max_results' => 5,
        ];
        if ($includeDomains !== []) {
            $body['include_domains'] = $includeDomains;
        }

        $response = Http::acceptJson()
            ->timeout(12)
            ->connectTimeout(5)
            ->post('https://api.tavily.com/search', $body);

        if (! $response->successful()) {
            throw new RuntimeException('Tavily HTTP '.$response->status().': '.$response->body());
        }

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

        if ($results === []) {
            throw new RuntimeException(
                $includeDomains !== []
                    ? 'Brak wyników w preferowanych sklepach BHP.'
                    : 'Tavily nie zwróciło wyników.'
            );
        }

        return [
            'results' => $results,
            'provider' => $includeDomains !== [] ? 'tavily_preferred' : 'tavily',
            'raw_content' => null,
        ];
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
