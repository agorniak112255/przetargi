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
        $preferred = $this->preferredDomains();
        $merged = [];
        $seen = [];
        $provider = 'tavily';

        foreach ($queries as $query) {
            $cacheKey = 'enrich_search_v2:'.hash('sha256', $query.'|'.implode(',', $preferred));
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['results']) && is_array($cached['results'])) {
                $packResults = $this->filterResultsBySku($cached['results'], (string) $product->sku);
            } else {
                $packResults = [];
                try {
                    if ($preferred !== []) {
                        $pack = $this->searchViaTavily($query, $preferred);
                        $packResults = $this->filterResultsBySku($pack['results'], (string) $product->sku);
                        $provider = 'tavily_preferred';
                    }
                } catch (Throwable $e) {
                    $errors[] = 'preferred: '.$e->getMessage();
                }

                if ($packResults === []) {
                    try {
                        $pack = $this->searchViaTavily($query, []);
                        $packResults = $this->filterResultsBySku($pack['results'], (string) $product->sku);
                        $provider = 'tavily';
                    } catch (Throwable $e) {
                        $errors[] = $e->getMessage();
                    }
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

            if (count($merged) >= 3) {
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

            if (count($merged) >= 2) {
                break;
            }
        }

        return [
            'results' => $merged,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildQueries(Product $product, string $phase): array
    {
        $sku = trim((string) $product->sku);
        $mfr = trim((string) $product->manufacturer);
        $name = trim((string) $product->name);
        $queries = array_values(array_unique(array_filter([
            '"'.$sku.'" '.$mfr,
            $sku.' '.$mfr.' '.$name,
            $sku.' MaxiChem',
            $sku.' ATG gloves datasheet',
            $phase === 'industry' ? $sku.' '.$mfr.' karta produktu sklep' : $sku.' '.$mfr.' official product',
        ])));

        return $queries !== [] ? $queries : [$sku];
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
        $skuCompact = preg_replace('/[^a-z0-9]/i', '', $skuNorm) ?? $skuNorm;

        $matched = [];
        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hay = mb_strtolower(
                ($row['url'] ?? '').' '.($row['title'] ?? '').' '.($row['snippet'] ?? '')
            );
            $hayCompact = preg_replace('/[^a-z0-9]/i', '', $hay) ?? $hay;
            if (str_contains($hay, $skuNorm) || ($skuCompact !== '' && str_contains($hayCompact, $skuCompact))) {
                // odrzuć oczywiste nie-produkty
                if (preg_match('#(ochraniacz|ochronki|buty|bluza|polar|koszulka|spodnie)#i', $hay)
                    && ! preg_match('#(rekaw|glove|maxi|chem|gauntlet)#i', $hay)) {
                    continue;
                }
                $matched[] = [
                    'url' => (string) $row['url'],
                    'title' => (string) ($row['title'] ?? $row['url']),
                    'snippet' => (string) ($row['snippet'] ?? ''),
                ];
            }
        }

        return $matched;
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
            'max_results' => 8,
        ];
        if ($includeDomains !== []) {
            $body['include_domains'] = $includeDomains;
        }

        $response = Http::acceptJson()
            ->timeout(25)
            ->connectTimeout(10)
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
        $raw = config('enrichment.preferred_domains', []);
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
