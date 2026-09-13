<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Product;
use App\Support\CatalogManufacturerContext;
use App\Support\ProductSearchBlob;
use InvalidArgumentException;

/**
 * Zestaw regresyjny „przetarg opisowy 15”: 15 pozycji bez marki i kodu (opis napisany
 * z karty właściwego produktu) oraz 31 kart z produkcji — oczekiwane i te, które system
 * wybierał błędnie. Karty są migawką produkcji i niczego do nich nie dopisujemy (norm,
 * klas, materiałów): test ma sprawdzać to, co system naprawdę widzi na karcie.
 *
 * `ppe_family` i `search_blob` nie są fillable — liczy je hook `saving` przy zapisie,
 * więc fixture zawsze testuje aktualny klasyfikator, nie rodzinę zapamiętaną z produkcji.
 */
final class Opisowy15Fixture
{
    public const DIR = __DIR__.'/../Fixtures/catalog/opisowy15';

    /** Pola prototypu (źródło karty, lokalne id, role w pozycjach) — nie są kolumnami produktu. */
    private const META_KEY = '_meta';

    /**
     * Dwie karty z eksportu produkcyjnego nie mają ceny ani stanu. Zero jako cena zrobiłoby
     * z nich „najtańszego” kandydata i zafałszowało remisy po cenie, więc dostają wartość
     * neutralną — taką samą, jakiej użyła sonda audytu (AUDYT_D §4.2).
     */
    private const COMMERCIAL_DEFAULTS = [
        'catalog_price_net' => 10,
        'purchase_price' => 7,
        'stock' => 5,
    ];

    /** @var list<array<string, mixed>>|null */
    private static ?array $items = null;

    /** @var list<array<string, mixed>>|null */
    private static ?array $rawCards = null;

    /**
     * Pozycje przetargu: line_no, requirement, unit, expected_sku, forbidden_skus,
     * prod_search (intencja i wynik wyszukiwarki z produkcji — do replay w stubie),
     * prod_tender (wybór produkcji — dokumentacja, nie asercja), source_facts.
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
    public static function line(int $lineNo): array
    {
        foreach (self::items() as $item) {
            if ((int) $item['line_no'] === $lineNo) {
                return $item;
            }
        }

        throw new InvalidArgumentException("Fixture opisowy15 nie ma pozycji {$lineNo}.");
    }

    public static function requirement(int $lineNo): string
    {
        return (string) self::line($lineNo)['requirement'];
    }

    /**
     * Karty produktów bez `_meta`, gotowe do `Product::create()`.
     *
     * @return list<array<string, mixed>>
     */
    public static function cards(): array
    {
        $out = [];
        foreach (self::rawCards() as $card) {
            unset($card[self::META_KEY]);
            $out[] = $card;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function card(string $sku): array
    {
        foreach (self::cards() as $card) {
            if ((string) $card['sku'] === $sku) {
                return $card;
            }
        }

        throw new InvalidArgumentException("Fixture opisowy15 nie ma karty {$sku}.");
    }

    /**
     * Zapis kart do bazy testowej (sqlite :memory:). Zwraca mapę sku => id.
     *
     * @param  list<string>|null  $onlySkus
     * @return array<string, int>
     */
    public static function seed(?array $onlySkus = null): array
    {
        $ids = [];
        foreach (self::cards() as $card) {
            $sku = (string) $card['sku'];
            if ($onlySkus !== null && ! in_array($sku, $onlySkus, true)) {
                continue;
            }
            $product = Product::query()->create(self::attributes($card));
            $ids[$sku] = (int) $product->id;
        }
        // Lista producentów katalogu żyje w cache godzinę — po dosianiu kart musi być świeża.
        CatalogManufacturerContext::forgetCache();

        return $ids;
    }

    /**
     * Karta bez bazy (testy jednostkowe): `new Product` + `forceFill`, a rodzina i blob
     * policzone tak, jak zrobiłby to hook `saving` — żeby karta zachowywała się jak zapisana.
     */
    public static function product(string $sku): Product
    {
        $index = null;
        $card = null;
        foreach (self::cards() as $i => $candidate) {
            if ((string) $candidate['sku'] === $sku) {
                $index = $i;
                $card = $candidate;
                break;
            }
        }
        if ($card === null || $index === null) {
            throw new InvalidArgumentException("Fixture opisowy15 nie ma karty {$sku}.");
        }

        $product = new Product;
        $product->forceFill(['id' => 1000 + $index] + self::attributes($card));
        foreach (app(ProductSearchBlob::class)->build($product) as $column => $value) {
            $product->setAttribute($column, $value);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private static function attributes(array $card): array
    {
        foreach (self::COMMERCIAL_DEFAULTS as $column => $default) {
            if (! isset($card[$column])) {
                $card[$column] = $default;
            }
        }
        if (($card['enrichment_status'] ?? null) === Product::ENRICHMENT_DONE) {
            $card['enriched_at'] = now();
        }

        return $card;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rawCards(): array
    {
        if (self::$rawCards === null) {
            $cards = self::readJson('products.json');
            foreach ($cards as $card) {
                if (! is_string($card['sku'] ?? null) || $card['sku'] === '') {
                    throw new InvalidArgumentException('Fixture opisowy15: karta bez SKU w products.json.');
                }
            }
            self::$rawCards = $cards;
        }

        return self::$rawCards;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function readJson(string $file): array
    {
        $path = self::DIR.'/'.$file;
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Fixture opisowy15: {$path} nie jest poprawnym JSON-em.");
        }

        return array_values($decoded);
    }
}
