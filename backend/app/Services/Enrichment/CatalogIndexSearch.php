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

    public function __construct(private readonly ProductSearchIdentity $identity) {}

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function findFor(Product $product): array
    {
        $hits = $this->byCode($product);

        return $hits !== [] ? $hits : $this->byBrandAndName($product);
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
            $this->identity->internalSkuCore($product),
            $this->identity->stripBrandPrefix(
                trim((string) $product->sku),
                $this->identity->shortBrand((string) $product->manufacturer)
            ),
        ];
        // „MT-212-2” leży w indeksie pod adresem „maska-mt-212-p-8” — człon
        // z wariantem sklepy zostawiają dopiero w treści karty
        foreach ($this->identity->variantBaseCodes($product) as $base) {
            $raw[] = $base;
        }

        $out = [];
        foreach ($raw as $code) {
            $code = mb_strtolower(trim($code));
            if ($code === '' || mb_strlen($code) < 3) {
                continue;
            }
            // „131-s1” jest w adresie jako „131”, „s1” i „131s1”
            $compact = preg_replace('/[^a-z0-9]+/u', '', $code) ?? $code;
            if ($compact !== '' && mb_strlen($compact) >= 4 && mb_strlen($compact) <= 64) {
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

        $ids = DB::table('catalog_page_tokens')
            ->whereIn('token', $codes)
            ->groupBy('catalog_page_id')
            ->orderByRaw('COUNT(DISTINCT token) DESC')
            ->limit(self::SQL_LIMIT)
            ->pluck('catalog_page_id')
            ->all();

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
            ->get(['url', 'title', 'haystack']);

        $brand = $this->brandToken($product);
        $withBrand = [];
        $rest = [];
        foreach ($pages as $page) {
            $url = (string) $page->url;
            if ($url === '') {
                continue;
            }
            $row = [
                'url' => $url,
                'title' => (string) ($page->title ?? ''),
                'snippet' => '',
            ];
            // strona z marką w adresie jest pewniejsza niż sam zgodny kod
            if ($brand !== '' && str_contains((string) $page->haystack, $brand)) {
                $withBrand[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        return array_slice(array_merge($withBrand, $rest), 0, self::MAX_HITS);
    }

    private function brandToken(Product $product): string
    {
        $brand = mb_strtolower($this->identity->shortBrand((string) $product->manufacturer));
        $brand = preg_replace('/[^a-z0-9]+/u', ' ', $brand) ?? $brand;
        $first = trim(explode(' ', trim($brand))[0] ?? '');

        return mb_strlen($first) >= 3 ? $first : '';
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
