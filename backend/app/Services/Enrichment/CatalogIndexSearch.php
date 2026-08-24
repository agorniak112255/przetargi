<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\CatalogPage;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Szuka karty produktu w lokalnym indeksie sitemap — zanim zapytamy wyszukiwarkę.
 */
final class CatalogIndexSearch
{
    /** Ile stron zwracamy do dalszego przetwarzania. */
    private const MAX_HITS = 8;

    /** Ile wierszy na jeden kod bierzemy z bazy przed filtrem tożsamości. */
    private const SQL_LIMIT = 40;

    public function __construct(private readonly ProductSearchIdentity $identity) {}

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function findFor(Product $product): array
    {
        $codes = $this->codes($product);
        if ($codes === []) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($codes as $code) {
            foreach ($this->rowsForCode($code) as $page) {
                $url = (string) $page->url;
                $key = mb_strtolower($url);
                if ($url === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'url' => $url,
                    'title' => (string) ($page->title ?? $url),
                    'snippet' => '',
                ];
                if (count($out) >= self::MAX_HITS) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Kody, po których ma sens szukać w URL-u: SKU, rdzeń kodu cennikowego, wersja bez znaków.
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

        $out = [];
        foreach ($raw as $code) {
            $code = mb_strtolower(trim($code));
            if ($code === '' || mb_strlen($code) < 4) {
                continue;
            }
            $out[] = $code;
            // „urg-c” w slugu bywa jako „urg_c” albo „urgc”
            $compact = preg_replace('/[^a-z0-9]+/u', '', $code) ?? $code;
            if ($compact !== '' && $compact !== $code && mb_strlen($compact) >= 4) {
                $out[] = $compact;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return \Illuminate\Support\Collection<int, CatalogPage>
     */
    private function rowsForCode(string $code)
    {
        return CatalogPage::query()
            ->where(function (Builder $q) use ($code): void {
                $q->where('haystack', 'like', '%'.$code.'%');
            })
            ->orderByDesc('last_seen_at')
            ->limit(self::SQL_LIMIT)
            ->get();
    }
}
