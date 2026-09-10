<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\CatalogPage;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Szuka karty produktu w lokalnym indeksie sitemap — zanim zapytamy wyszukiwarkę.
 * Dopasowanie idzie po tokenach adresu: najpierw kod, potem marka ze słowami z nazwy.
 */
final class CatalogIndexSearch
{
    /** Ile stron zwracamy do dalszego przetwarzania. */
    private const MAX_HITS = 8;

    /** Ile wierszy bierzemy z bazy przed filtrem tożsamości. */
    private const SQL_LIMIT = 40;

    public function __construct(
        private readonly ProductSearchIdentity $identity,
        private readonly CatalogPageManufacturer $pageManufacturer,
    ) {}

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function findFor(Product $product): array
    {
        $hits = $this->byCode($product);
        if ($hits !== []) {
            return $hits;
        }
        $hits = $this->byBrandAndName($product);

        return $hits !== [] ? $hits : $this->byDistinctiveName($product);
    }

    /**
     * Kody, po których ma sens szukać w adresie: SKU, rdzeń kodu cennikowego, wersja bez znaków.
     *
     * @return list<string>
     */
    public function codes(Product $product): array
    {
        $raw = [
            trim((string) $product->sku),
            $this->identity->catalogSkuWithoutSize($product),
            $this->identity->internalSkuCore($product),
            $this->identity->stripBrandPrefix(
                trim((string) $product->sku),
                $this->identity->shortBrand((string) $product->manufacturer)
            ),
        ];
        foreach ($this->identity->catalogArticleCodes($product) as $article) {
            $raw[] = $article;
        }
        // „MT-212-2” leży w indeksie pod adresem „maska-mt-212-p-8” — człon
        // z wariantem sklepy zostawiają dopiero w treści karty
        foreach ($this->identity->variantBaseCodes($product) as $base) {
            $raw[] = $base;
        }
        foreach ($this->identity->modelAliases($product) as $alias) {
            $raw[] = $alias;
        }
        foreach ($this->identity->shopIdentityPhrases($product) as $phrase) {
            if ($this->identity->isWeakShopIndexPhrase($phrase, $product)) {
                continue;
            }
            $raw[] = $phrase;
        }
        foreach ($this->identity->skuSearchNeedles($product) as $needle) {
            $raw[] = $needle;
        }

        $out = [];
        foreach ($raw as $code) {
            $code = mb_strtolower(trim($code));
            if ($code === '' || mb_strlen($code) < 3) {
                continue;
            }
            // „131-s1” jest w adresie jako „131”, „s1” i „131s1”
            $compact = preg_replace('/[^a-z0-9]+/u', '', $code) ?? $code;
            $min = preg_match('/^\d{3}$/u', $compact) === 1 ? 3 : 4;
            if ($compact !== '' && mb_strlen($compact) >= $min && mb_strlen($compact) <= 64) {
                $out[] = $compact;
            }
            if (mb_strlen($code) <= 64 && preg_match('/^[a-z0-9]+$/u', $code) === 1) {
                $out[] = $code;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function byCode(Product $product): array
    {
        $codes = $this->codes($product);
        if ($codes === []) {
            return [];
        }

        $typePrefixes = $this->shortNumericVariantNeedsType($product, $codes)
            ? $this->identity->catalogTypeTokenPrefixes($product)
            : [];
        $query = DB::table('catalog_page_tokens as t')
            ->whereIn('t.token', $codes);
        if ($typePrefixes !== []) {
            $query->whereExists(function ($q) use ($typePrefixes): void {
                $q->select(DB::raw(1))
                    ->from('catalog_page_tokens as typ')
                    ->whereColumn('typ.catalog_page_id', 't.catalog_page_id')
                    ->where(function ($inner) use ($typePrefixes): void {
                        foreach ($typePrefixes as $prefix) {
                            $inner->orWhere('typ.token', 'like', $prefix.'%');
                        }
                    });
            });
        }
        $ids = $query
            ->groupBy('t.catalog_page_id')
            ->orderByRaw('COUNT(DISTINCT t.token) DESC')
            ->limit(self::SQL_LIMIT)
            ->pluck('t.catalog_page_id')
            ->all();
        $ids = array_values(array_unique(array_merge(
            $ids,
            $this->gluedNumericTokenPageIds($codes, $typePrefixes)
        )));

        return $this->pages($ids, $product);
    }

    /**
     * Produkty bez kodu producenta („WKLADKI-ALUTERMICZNE”) rozpoznajemy po marce
     * i znaczących słowach z nazwy.
     *
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function byBrandAndName(Product $product): array
    {
        $brand = $this->brandToken($product);
        $words = $this->nameTokens($product);
        if ($brand === '' || $words === []) {
            return [];
        }

        $need = min(2, count($words));
        $ids = DB::table('catalog_page_tokens as t')
            ->whereIn('t.token', $words)
            ->whereExists(function ($q) use ($brand): void {
                $q->select(DB::raw(1))
                    ->from('catalog_page_tokens as b')
                    ->whereColumn('b.catalog_page_id', 't.catalog_page_id')
                    ->where('b.token', $brand);
            })
            ->groupBy('t.catalog_page_id')
            ->havingRaw('COUNT(DISTINCT t.token) >= ?', [$need])
            ->orderByRaw('COUNT(DISTINCT t.token) DESC')
            ->limit(self::SQL_LIMIT)
            ->pluck('t.catalog_page_id')
            ->all();

        return $this->pages($ids, $product);
    }

    /**
     * Karta bez SKU w slugu („peleryna-dla-niepelnosprawnych-wozek-aktywny”).
     *
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function byDistinctiveName(Product $product): array
    {
        $words = $this->nameTokens($product);
        if (count($words) < 3) {
            return [];
        }

        $typePrefixes = $this->shortNumericVariantNeedsType($product, $this->codes($product))
            ? $this->identity->catalogTypeTokenPrefixes($product)
            : [];
        $query = DB::table('catalog_page_tokens as t')
            ->whereIn('t.token', $words);
        if ($typePrefixes !== []) {
            $query->whereExists(function ($q) use ($typePrefixes): void {
                $q->select(DB::raw(1))
                    ->from('catalog_page_tokens as typ')
                    ->whereColumn('typ.catalog_page_id', 't.catalog_page_id')
                    ->where(function ($inner) use ($typePrefixes): void {
                        foreach ($typePrefixes as $prefix) {
                            $inner->orWhere('typ.token', 'like', $prefix.'%');
                        }
                    });
            });
        }
        $ids = $query
            ->groupBy('t.catalog_page_id')
            ->havingRaw('COUNT(DISTINCT t.token) >= ?', [3])
            ->orderByRaw('COUNT(DISTINCT t.token) DESC')
            ->limit(self::SQL_LIMIT)
            ->pluck('t.catalog_page_id')
            ->all();

        return $this->pages($ids, $product);
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function pages(array $ids, Product $product): array
    {
        if ($ids === []) {
            return [];
        }

        $pages = CatalogPage::query()
            ->whereIn('id', $ids)
            ->get(['url', 'title', 'haystack', 'manufacturer']);

        $brand = $this->brandToken($product);
        $ambiguous = $this->isAmbiguousNumericSku($product);
        $codes = $this->codes($product);
        $needType = $this->shortNumericVariantNeedsType($product, $codes);
        $official = [];
        $withManufacturer = [];
        $withBrand = [];
        $rest = [];
        foreach ($pages as $page) {
            $url = (string) $page->url;
            if ($url === '') {
                continue;
            }
            $pageManufacturer = $page->manufacturer !== null ? (string) $page->manufacturer : null;
            if ($this->pageManufacturer->conflictsWithProduct($pageManufacturer, $product)) {
                continue;
            }
            $row = [
                'url' => $url,
                'title' => (string) ($page->title ?? ''),
                'snippet' => '',
            ];
            if ($needType && ! $this->identity->hayHasRequiredTypeFromName(
                $url.' '.$row['title'].' '.(string) $page->haystack,
                $product
            )) {
                continue;
            }
            if ($this->identity->pageClaimsAnotherCode($url, $row['title'], $product)) {
                continue;
            }
            $hay = (string) $page->haystack;
            $matchesManufacturer = $this->pageManufacturer->matchesProduct($pageManufacturer, $product);
            $hasBrand = $matchesManufacturer
                || $this->identity->hayHasBrand($hay, $product)
                || ($brand !== '' && str_contains($hay, $brand));
            // ICD i producent mają tę samą rodzinę (pros) — karta z domeny marki
            // musi być przed wariantami kolorystycznymi sklepu (limit 8).
            if ($this->urlIsOfficialCatalogHost($url, $product)) {
                $official[] = $row;
            } elseif ($matchesManufacturer) {
                $withManufacturer[] = $row;
            } elseif ($hasBrand) {
                $withBrand[] = $row;
            } elseif (! $ambiguous
                || $this->identity->urlHasGluedNumericModel($url.' '.$row['title'].' '.$hay, $product)
                || $this->identity->hayHasDistinctiveNamePhrase($url.' '.$row['title'].' '.$hay, $product)) {
                $rest[] = $row;
            }
        }

        return array_slice(array_merge($official, $withManufacturer, $withBrand, $rest), 0, self::MAX_HITS);
    }

    private function urlIsOfficialCatalogHost(string $url, Product $product): bool
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./u', '', $host) ?? $host;
        if ($host === '') {
            return false;
        }
        foreach ($this->identity->officialCatalogHosts($product) as $official) {
            $official = preg_replace('/^www\./u', '', mb_strtolower(trim($official))) ?? '';
            if ($official !== '' && ($host === $official || str_ends_with($host, '.'.$official))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sklep skleja model z id karty (902 → 9022002). LIKE zamiast REGEXP — działa też na sqlite.
     *
     * @param  list<string>  $codes
     * @param  list<string>  $typePrefixes
     * @return list<int>
     */
    private function gluedNumericTokenPageIds(array $codes, array $typePrefixes): array
    {
        $ids = [];
        foreach ($codes as $code) {
            if (preg_match('/^\d{3,4}$/u', $code) !== 1) {
                continue;
            }
            $query = DB::table('catalog_page_tokens as t')
                ->where('t.token', 'like', $code.'%')
                ->whereRaw('LENGTH(t.token) >= ?', [strlen($code) + 3]);
            if ($typePrefixes !== []) {
                $query->whereExists(function ($q) use ($typePrefixes): void {
                    $q->select(DB::raw(1))
                        ->from('catalog_page_tokens as typ')
                        ->whereColumn('typ.catalog_page_id', 't.catalog_page_id')
                        ->where(function ($inner) use ($typePrefixes): void {
                            foreach ($typePrefixes as $prefix) {
                                $inner->orWhere('typ.token', 'like', $prefix.'%');
                            }
                        });
                });
            }
            foreach ($query->select(['t.catalog_page_id', 't.token'])->limit(self::SQL_LIMIT * 3)->get() as $row) {
                $token = (string) $row->token;
                if (ctype_digit($token) && str_starts_with($token, $code)
                    && strlen($token) >= strlen($code) + 3) {
                    $ids[] = (int) $row->catalog_page_id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function isAmbiguousNumericSku(Product $product): bool
    {
        $sku = mb_strtolower(trim((string) $product->sku));

        return preg_match('/^\d{3,4}$/', $sku) === 1;
    }

    /**
     * Krótki numer (300, 109/O → 109) bez typu z nazwy trafia w znaki BC109
     * i ginie w limicie SQL wśród tysięcy tokenów.
     *
     * @param  list<string>  $codes
     */
    private function shortNumericVariantNeedsType(Product $product, array $codes): bool
    {
        foreach ($codes as $code) {
            if (preg_match('/^\d{3,4}$/u', $code) === 1) {
                return true;
            }
        }

        return false;
    }

    private function brandToken(Product $product): string
    {
        $brand = mb_strtolower($this->identity->shortBrand((string) $product->manufacturer));
        $brand = preg_replace('/[^a-z0-9]+/u', ' ', $brand) ?? $brand;
        $first = trim(explode(' ', trim($brand))[0] ?? '');

        return mb_strlen($first) >= 3 || $first === '3m' ? $first : '';
    }

    /**
     * @return list<string>
     */
    private function nameTokens(Product $product): array
    {
        $out = [];
        foreach ($this->identity->nameWords($product) as $word) {
            // w adresach stron „wkładki” występuje jako „wkladki”
            $out[] = mb_strtolower(Str::ascii($word));
        }
        foreach (preg_split('/[^a-z0-9]+/u', mb_strtolower((string) $product->name)) ?: [] as $word) {
            // liczby z nazwy („SECAIR 2000”) są mocnym sygnałem
            if (preg_match('/^\d{3,}$/u', $word) === 1) {
                $out[] = $word;
            }
        }

        return array_values(array_unique(array_slice(array_filter($out), 0, 6)));
    }
}
