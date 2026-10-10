<?php

declare(strict_types=1);

namespace App\Services\PriceLists\Importers;

use App\Models\CatalogPage;
use App\Models\ManufacturerSite;
use App\Models\PriceList;
use App\Models\Product;
use App\Services\Enrichment\CatalogIndexSearch;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\PartsTable\PartsTables;
use App\Services\Enrichment\PartsTable\PinResult;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\PriceLists\ReadOnlyViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Narzędzia importera przy ustalaniu źródła karty (MapContext) — wyłącznie odczyt: hosty producenta, indeks stron
 * (catalog_pages, catalog_page_tokens), tabele części, pobranie strony. W podglądzie importu liveFetch=false: fetch()
 * zwraca null, a importer decyduje z samego indeksu albo oddaje unresolved(PriceListIntakeRunner::NOT_CHECKED_REASON).
 */
final class DefaultMapContext implements MapContext
{
    /** Kod krótszy (po zdjęciu separatorów) nie jest dowodem — za dużo przypadkowych trafień. */
    private const MIN_CODE_CHARS = 3;

    /** Najwięcej stron z indeksu na jedno pytanie o kod. */
    private const MAX_CODE_PAGES = 50;

    public function __construct(
        private readonly PriceList $list,
        private readonly bool $liveFetch,
    ) {}

    public function priceList(): PriceList
    {
        return $this->list;
    }

    public function manufacturerHosts(Product $card): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $host): string => ManufacturerSite::normalizeHost((string) $host),
            app(ManufacturerDomainResolver::class)->assignedDomainsFor($card),
        ), static fn (string $host): bool => $host !== '')));
    }

    public function listHosts(): array
    {
        return $this->list->enrichmentHosts();
    }

    /**
     * CatalogIndexSearch::findFor z filtrem hostów w SQL — czysty odczyt indeksu (bez filtrów tożsamości
     * wyszukiwarki i bez dziennika przebiegu, które dokłada HybridWebSearchService::catalogHitsOnHosts): decyzję
     * o trafieniu podejmuje importer, np. carriesCode() na adresie i tytule.
     */
    public function indexHits(Product $card, array $hosts): array
    {
        if ($this->normalizedHosts($hosts) === []) {
            return [];
        }
        try {
            $hits = app(CatalogIndexSearch::class)->findFor($card, [], $hosts);
        } catch (ReadOnlyViolation $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::info('Mapa cennika: odczyt indeksu stron nie powiódł się', ['product' => $card->sku, 'error' => $e->getMessage()]);

            return [];
        }

        return array_map(static fn (array $hit): array => ['url' => (string) $hit['url'], 'title' => (string) ($hit['title'] ?? '')], $hits);
    }

    /**
     * Token kodu jak w CatalogSitemapIndexer::tokensFor: małe litery ASCII i cyfry bez separatorów („AF-0100” →
     * „af0100”, indeks skleja krótkie pary). Kod z separatorami bywa w indeksie tylko w częściach („104/1” →
     * „104” + „1”) — wtedy strony ze wszystkimi częściami. Wynik zawsze przez carriesCode na adresie i tytule.
     */
    public function pagesWithCode(string $code, array $hosts): array
    {
        $hosts = $this->normalizedHosts($hosts);
        $code = trim($code);
        $ascii = mb_strtolower(Str::ascii($code));
        $joined = (string) preg_replace('/[^a-z0-9]+/', '', $ascii);
        if ($hosts === [] || strlen($joined) < self::MIN_CODE_CHARS || strlen($joined) > 64) {
            return [];
        }
        $parts = array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', $ascii) ?: [],
            static fn (string $part): bool => $part !== '',
        )));

        $ids = $this->pageIdsWithTokens([$joined], $hosts);
        if (count($parts) > 1) {
            $ids = array_values(array_unique([...$ids, ...$this->pageIdsWithTokens($parts, $hosts)]));
        }
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (CatalogPage::query()->whereIn('id', array_slice($ids, 0, self::MAX_CODE_PAGES * 4))->orderBy('id')->get(['id', 'url', 'title']) as $page) {
            $url = (string) $page->url;
            $title = (string) ($page->title ?? '');
            if (! $this->urlOnHosts($url, $hosts) || ! $this->carriesCode(rawurldecode($url).' '.$title, [$code])) {
                continue;
            }
            $out[] = ['url' => $url, 'title' => $title];
            if (count($out) >= self::MAX_CODE_PAGES) {
                break;
            }
        }

        return $out;
    }

    /**
     * Kod jako osobny ciąg (logika jak PriceListFileSourcesController::carriesCode): między znakami kodu wolno separator
     * („PSSBL30-014”), przed i za nim nie ma litery ani cyfry — TRACPSF nie trafia w TRACPSFX. Do tego krótki dopisek
     * wariantu po spacji (1–2 wielkie litery albo cyfry: „1011 R”, „104/1 OC”) oznacza inny wyrób — kod 1011 go nie
     * niesie. Małe litery po spacji to zwykły tekst („1011 w rozmiarze”).
     * TODO: wspólna klasa z PriceListFileSourcesController::carriesCode (kontroler poza zakresem tej zmiany).
     */
    public function carriesCode(string $text, array $codes): bool
    {
        foreach ($codes as $code) {
            $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $code));
            if (mb_strlen($key) < self::MIN_CODE_CHARS) {
                continue;
            }
            $chars = preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $pattern = implode('[\s._\/-]?', array_map(static fn (string $c): string => preg_quote($c, '/'), $chars));
            if (preg_match_all('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/iu', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach ($matches[0] as [$found, $offset]) {
                $rest = substr($text, $offset + strlen($found));
                // dopisek wariantu: spacja, 1–2 wielkie litery/cyfry, potem koniec albo znak spoza liter i cyfr
                if (preg_match('/^\s+[\p{Lu}\p{N}]{1,2}(?![\p{L}\p{N}])/u', $rest) === 1) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    public function fetch(string $url): ?array
    {
        if (! $this->liveFetch) {
            return null;
        }
        // bez pamięci podręcznej: fetchRaw z pamięci oddaje adres zapytania zamiast adresu po przekierowaniu, a importer
        // rozpoznaje przekierowanie (wycofany wyrób → kategoria) po final_url
        $fetcher = app(ProductPageFetcher::class)->bypassCache();
        try {
            $raw = $fetcher->fetchRaw($url);
        } catch (ReadOnlyViolation $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::info('Mapa cennika: pobranie strony nie powiodło się', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
        if ($raw === null) {
            return null;
        }
        $titles = $fetcher->pageTitles($raw['html']);

        return [
            'url' => $url,
            'final_url' => $raw['final_url'],
            'title' => isset($titles[0]) && trim((string) $titles[0]) !== '' ? (string) $titles[0] : null,
            'text' => $fetcher->mainTextFromHtml($raw['html']),
            'html' => $raw['html'],
        ];
    }

    public function liveFetch(): bool
    {
        return $this->liveFetch;
    }

    public function partsPin(Product $card): ?PinResult
    {
        return app(PartsTables::class)->pinFor($card);
    }

    /**
     * Strony indeksu na hostach (host równy albo subdomena, zapis z „www.” i bez), które mają wszystkie tokeny.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $hosts
     * @return list<int>
     */
    private function pageIdsWithTokens(array $tokens, array $hosts): array
    {
        $tokens = array_values(array_unique(array_filter($tokens, static fn (string $t): bool => $t !== '' && strlen($t) <= 64)));
        if ($tokens === []) {
            return [];
        }

        return DB::table('catalog_page_tokens as t')
            ->join('catalog_pages as p', 'p.id', '=', 't.catalog_page_id')
            ->whereIn('t.token', $tokens)
            ->where(function ($q) use ($hosts): void {
                $exact = [];
                foreach ($hosts as $host) {
                    $exact[] = $host;
                    $exact[] = 'www.'.$host;
                }
                $q->whereIn('p.host', $exact);
                foreach ($hosts as $host) {
                    $q->orWhere('p.host', 'like', '%.'.$host);
                }
            })
            ->groupBy('t.catalog_page_id')
            ->havingRaw('COUNT(DISTINCT t.token) = ?', [count($tokens)])
            ->orderBy('t.catalog_page_id')
            ->limit(self::MAX_CODE_PAGES * 4)
            ->pluck('t.catalog_page_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private function normalizedHosts(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $host) {
            $bare = ManufacturerSite::normalizeHost((string) $host);
            if ($bare !== '') {
                $out[$bare] = true;
            }
        }

        return array_keys($out);
    }

    /** @param  list<string>  $hosts */
    private function urlOnHosts(string $url, array $hosts): bool
    {
        $host = ManufacturerSite::normalizeHost((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }
        foreach ($hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
