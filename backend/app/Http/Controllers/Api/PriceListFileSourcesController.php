<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ManufacturerSite;
use App\Models\PriceList;
use App\Models\Product;
use App\Services\Enrichment\CatalogSearchHostService;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\PriceListSourceSettings;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Services\PriceListCards;
use App\Services\PriceListFileSources;
use App\Services\PriceLists\PriceListIntakeView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Cenniki → „Z pliku”: źródła opisów per cennik z pliku (prośba użytkownika 05.10.2026). Zapis stron i trybu idzie
 * przez PATCH /price-lists/{id} (PriceListController::update), pobieranie opisów przez POST /price-lists/{id}/enrich.
 */
class PriceListFileSourcesController extends Controller
{
    private const SEARCH_SITES_CACHE_KEY = 'price_lists.search_sites.v2';

    private const SEARCH_SITES_CACHE_SECONDS = 600;

    /** Najkrótszy kod karty, którego szukamy w adresie/tytule strony — jak bramka wariantu przy uzupełnianiu opisu. */
    private const MIN_CODE_CHARS = 5;

    public function __construct(
        private readonly PriceListFileSources $fileSources,
        private readonly PriceListCards $cards,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['lists' => $this->fileSources->lists()]);
    }

    /**
     * Lista Administracja → „Strony wyszukiwarka” do wyboru stron cennika — tylko pola potrzebne oknu wyboru.
     * list() liczy strony całego indeksu (GROUP BY po catalog_pages), stąd pamięć 10 min.
     */
    public function searchSites(CatalogSearchHostService $hosts): JsonResponse
    {
        $sites = Cache::remember(self::SEARCH_SITES_CACHE_KEY, self::SEARCH_SITES_CACHE_SECONDS, static function () use ($hosts): array {
            // producenci przypisani świadomie (manual/config) — wykryci automatem (discovered) są tylko w manufacturers
            $assigned = [];
            foreach (ManufacturerSite::brandsByHost() as $host => $brands) {
                foreach ($brands as $brand) {
                    if (in_array($brand['source'], PriceListIntakeView::SITE_SOURCES, true)) {
                        $assigned[$host][$brand['manufacturer']] = true;
                    }
                }
            }

            return array_map(
                static fn (array $row): array => [
                    'host' => (string) $row['host'],
                    'links' => (int) $row['links'],
                    'manufacturers' => array_values(array_map('strval', $row['manufacturers'] ?? [])),
                    'assigned_manufacturers' => array_map('strval', array_keys($assigned[(string) $row['host']] ?? [])),
                    'priority' => isset($row['priority']) ? (int) $row['priority'] : null,
                    'sources' => array_values(array_map('strval', $row['sources'] ?? [])),
                ],
                $hosts->list(),
            );
        });

        return response()->json(['sites' => $sites]);
    }

    /**
     * „Sprawdź na karcie”: które strony cennika indeks lokalny zna dla tej karty — bez zapytań site:, bez modelu
     * i bez zapisów. coded = kod karty (≥ 5 znaków, separatory bez znaczenia) stoi w adresie albo tytule strony.
     */
    public function siteCheck(
        Request $request,
        PriceList $priceList,
        HybridWebSearchService $search,
        ProductSearchIdentity $identity,
    ): JsonResponse {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);
        $productId = (int) $data['product_id'];

        if (! in_array($productId, $this->cards->fileSlotIds($priceList), true)) {
            return response()->json([
                'message' => 'Ta karta nie należy do cennika '.$priceList->manufacturer.'.',
                'errors' => ['product_id' => ['Ta karta nie należy do tego cennika.']],
            ], 422);
        }
        $hosts = $priceList->enrichmentHosts();
        if ($hosts === []) {
            return response()->json([
                'message' => 'Cennik '.$priceList->manufacturer.' nie ma wpisanych stron z opisami.',
            ], 422);
        }

        /** @var Product $product */
        $product = Product::query()->findOrFail($productId);
        $settings = PriceListSourceSettings::fromList($priceList);
        $codes = $this->codeKeys([(string) $product->sku, $identity->catalogSkuWithoutSize($product)]);

        $hits = [];
        foreach ($search->catalogHitsOnHosts($product, $hosts) as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            $hits[] = [
                'url' => $url,
                'title' => $title,
                'host' => (string) preg_replace('/^www\./', '', mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''))),
                'position' => $settings?->position($url),
                'coded' => $this->carriesCode(rawurldecode($url)."\n".$title, $codes),
            ];
        }

        return response()->json([
            'product' => [
                'id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
            ],
            'hits' => $hits,
        ]);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string> małe litery i cyfry, bez separatorów, co najmniej MIN_CODE_CHARS znaków
     */
    private function codeKeys(array $codes): array
    {
        $keys = [];
        foreach ($codes as $code) {
            $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($code));
            if (mb_strlen($key) >= self::MIN_CODE_CHARS) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Kod jako osobny ciąg: między jego znakami wolno separator („PSSBL30-014”), przed i za nim nie ma litery ani
     * cyfry — TRACPSF nie trafia w TRACPSFX (inny wariant).
     *
     * @param  list<string>  $keys
     */
    private function carriesCode(string $text, array $keys): bool
    {
        foreach ($keys as $key) {
            $chars = preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $pattern = implode('[\s._\/-]?', array_map(static fn (string $c): string => preg_quote($c, '/'), $chars));
            if (preg_match('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
