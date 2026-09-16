<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Product;
use InvalidArgumentException;

/**
 * Zestaw odniesienia „tester 51”: pozycje z trzech cenników (MAPA, SECURA, AJ GROUP)
 * przejrzane ręcznie przez testerkę, stan wzbogacania „PRZED” wprost z bazy produkcyjnej
 * oraz prawdziwe migawki wszystkich stron, z których powstały opisy.
 *
 * Wszystkie trzy pliki są migawką — niczego w nich nie poprawiamy ani nie upiększamy.
 * `items.json` niesie ocenę testerki i ręczne etykiety źródeł (`expected`/`wrong`/`unknown`),
 * `products.json` to wynik produkcyjny, `pages/*.json` to tekst stron pobrany tą samą
 * drogą, którą widzi pipeline (`ProductPageFetcher::fetch()` bez filtra tożsamości).
 *
 * Szczegóły — patrz tests/Fixtures/enrichment/tester51/README.md.
 */
final class Tester51Fixture
{
    public const DIR = __DIR__.'/../Fixtures/enrichment/tester51';

    /** @var list<array<string, mixed>>|null */
    private static ?array $items = null;

    /** @var list<array<string, mixed>>|null */
    private static ?array $products = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $pages = null;

    /**
     * Pozycje z arkusza: sku, name, manufacturer, verdict, issues, note, sources.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(): array
    {
        return self::$items ??= self::readJson('items.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(string $sku): array
    {
        foreach (self::items() as $item) {
            if ((string) $item['sku'] === $sku) {
                return $item;
            }
        }

        throw new InvalidArgumentException("Fixture tester51 nie ma pozycji {$sku}.");
    }

    /**
     * Stan „PRZED” z bazy: sku, name, manufacturer, category, model_name,
     * enrichment_status, norms_column, shop_source_url, produced[...].
     *
     * @return list<array<string, mixed>>
     */
    public static function products(): array
    {
        return self::$products ??= self::readJson('products.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function product(string $sku): array
    {
        foreach (self::products() as $product) {
            if ((string) $product['sku'] === $sku) {
                return $product;
            }
        }

        throw new InvalidArgumentException("Fixture tester51 nie ma karty {$sku}.");
    }

    /**
     * Migawka strony spod adresu — `null`, gdy adresu nie ma w zestawie.
     *
     * @return array<string, mixed>|null
     */
    public static function page(string $url): ?array
    {
        return self::pages()[self::pageKey($url)] ?? null;
    }

    /**
     * Migawki stron, z których powstał opis pozycji — w kolejności z `produced.source_urls`.
     * Adresy bez zapisanej migawki są pomijane.
     *
     * @return list<array<string, mixed>>
     */
    public static function pagesFor(string $sku): array
    {
        $out = [];
        foreach (self::product($sku)['produced']['source_urls'] as $url) {
            $page = self::page((string) $url);
            if ($page !== null) {
                $out[] = $page;
            }
        }

        return $out;
    }

    /**
     * Niezapisany model produktu z kolumnami z `products.json`. Bramka tożsamości czyta
     * tylko kolumny cennika i opis, a `siblingModelCodes()` odpuszcza sobie bazę dla
     * modelu bez `exists`, więc taki produkt wystarcza do testów bez bazy.
     */
    public static function makeProduct(string $sku): Product
    {
        $card = self::product($sku);

        $product = new Product;
        $product->forceFill([
            'sku' => (string) $card['sku'],
            'name' => (string) $card['name'],
            'manufacturer' => (string) $card['manufacturer'],
            'category' => $card['category'] !== null ? (string) $card['category'] : null,
            'model_name' => $card['model_name'] !== null ? (string) $card['model_name'] : null,
            'enrichment_status' => (string) $card['enrichment_status'],
            'norms' => $card['norms_column'] !== null ? (string) $card['norms_column'] : null,
            'shop_source_url' => $card['shop_source_url'] !== null ? (string) $card['shop_source_url'] : null,
            'description' => (string) $card['produced']['description'],
        ]);

        return $product;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function pages(): array
    {
        if (self::$pages === null) {
            $pages = [];
            foreach ((array) glob(self::DIR.'/pages/*.json') as $path) {
                $decoded = json_decode((string) file_get_contents((string) $path), true);
                if (! is_array($decoded) || ! is_string($decoded['url'] ?? null)) {
                    throw new InvalidArgumentException("Fixture tester51: {$path} nie jest poprawną migawką.");
                }
                $pages[self::pageKey($decoded['url'])] = $decoded;
            }
            self::$pages = $pages;
        }

        return self::$pages;
    }

    /** Klucz migawki: ten sam schemat nazwy, którego użyto przy pobieraniu stron. */
    private static function pageKey(string $url): string
    {
        return str_replace('.', '', (string) parse_url($url, PHP_URL_HOST)).'-'.substr(sha1($url), 0, 10);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function readJson(string $file): array
    {
        $path = self::DIR.'/'.$file;
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Fixture tester51: {$path} nie jest poprawnym JSON-em.");
        }

        return array_values($decoded);
    }
}
