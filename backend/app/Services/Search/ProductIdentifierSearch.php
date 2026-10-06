<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\ProductIdentifier;
use App\Models\ProductVariant;
use App\Support\ProductIdentifierCode;
use Illuminate\Support\Str;

/**
 * Wyszukiwarka listy produktów po numerach zapisanych przy karcie ze źródeł ceny — bez numerów zdjętych ze źródła
 * (removed_at):
 *  - product_identifiers: numer artykułu, kod producenta, kod modelu, stary i zamienny kod, EAN;
 *  - kody rozmiarów (product_variants kind=size, sku) — dla kart, których numer nie trafił: łącznik zapisuje numer
 *    koloru, a kod rozmiaru („1010-001-410-46” Canis, „100-10” Demar) zna tylko wiersz rozmiaru.
 *
 * Karta łączy kolory i rozmiary spod kilku numerów (ELTEN MAVERICK red + black, Atlas tęgości, rozmiary Portwest), a jej
 * SKU i nazwa niosą tylko numer wiodący — numer drugiego koloru (0723381-0 na karcie 0723341-0) trafia kartę tylko tu.
 *
 * Numery: postać ProductIdentifierCode::code (wielkie litery i cyfry), zawieranie — „723381” trafia „0723381-0”, a pełny
 * kod rozmiaru „S503NVRM” kartę „S503”; kody rozmiarów (bez postaci porównawczej w bazie) — zawieranie wpisanego
 * ciągu. Fraza od 5 znaków z cyfrą (jak początek kodu w ErpCodeSearch): słowa bez cyfr i krótkie liczby wciągałyby
 * całe grupy asortymentu. Wieloczłonowa fraza („elten 723381”) — całość i każde słowo osobno; trafienie samym słowem
 * liczy się tylko wtedy, gdy pozostałe słowa bez cyfr są w SKU, nazwie albo producencie karty („uvex 723381” nie pokaże
 * karty ELTEN). EAN — tylko cały (GTIN-14), bo kawałek EAN-u to prefiks firmy i trafia tysiące kart.
 */
final class ProductIdentifierSearch
{
    private const MIN_LENGTH = 5;

    /** Górna granica wierszy z jednej frazy (osobno numery i rozmiary) — więcej to już nie „szukam numeru”. */
    private const MAX_ROWS = 500;

    /** Najwyżej tyle trafionych numerów przy jednej karcie (kody rozmiarów jednego koloru). */
    private const MAX_CODES_PER_CARD = 5;

    private const EAN_TYPES = [ProductIdentifier::TYPE_EAN, ProductIdentifier::TYPE_PACK_EAN];

    /**
     * @return array<int, array{codes: list<string>, exact: bool}> id karty → trafione numery („0723381-0 (black)”)
     *                                                             i czy któryś równa się całej frazie
     */
    public function productCodes(string $term): array
    {
        $term = trim($term);
        if ($term === '' || mb_strlen($term) > 100) {
            return [];
        }
        $whole = ProductIdentifierCode::code($term);
        $needles = [];
        $rawNeedles = [];
        foreach ([$term, ...(preg_split('/\s+/u', $term) ?: [])] as $part) {
            $code = ProductIdentifierCode::code($part);
            if ($code !== null && strlen($code) >= self::MIN_LENGTH && preg_match('/\d/', $code) === 1) {
                $needles[$code] = true;
                $rawNeedles[$part] = true;
            }
        }
        $gtin = ProductIdentifierCode::gtin($term);
        if ($needles === [] && $gtin === null) {
            return [];
        }
        $plainWords = array_values(array_filter(
            array_map(static fn (string $word): string => self::plain($word), ProductListTextSearch::phraseWords($term)),
            static fn (string $word): bool => preg_match('/\d/', $word) !== 1,
        ));
        $accepts = static function (object $row, string $normalized) use ($whole, $needles, $plainWords): bool {
            if ($whole !== null && isset($needles[$whole]) && str_contains($normalized, $whole)) {
                return true;
            }
            // trafione samym słowem frazy — reszta słów musi pasować do karty
            $card = self::plain($row->card_sku.' '.$row->card_name.' '.$row->card_manufacturer);
            foreach ($plainWords as $word) {
                if (! str_contains($card, $word)) {
                    return false;
                }
            }

            return true;
        };

        $out = [];
        foreach ($this->identifierRows(array_map('strval', array_keys($needles)), $gtin) as $row) {
            $normalized = (string) $row->normalized;
            $isEan = in_array($row->type, self::EAN_TYPES, true);
            if (! $isEan && ! $accepts($row, $normalized)) {
                continue;
            }
            self::add($out, (int) $row->product_id, $isEan ? $normalized === $gtin : $normalized === $whole,
                $isEan ? null : $normalized, (string) $row->card_sku, (string) $row->value, (string) $row->variant_label);
        }
        foreach ($this->sizeRows(array_map('strval', array_keys($rawNeedles))) as $row) {
            $id = (int) $row->product_id;
            $normalized = (string) ProductIdentifierCode::code((string) $row->sku);
            // karta trafiona numerem — jej kody rozmiarów niczego nie dodają
            if ((isset($out[$id]) && ! $out[$id]['by_size']) || ! $accepts($row, $normalized)) {
                continue;
            }
            $label = trim((string) $row->label);
            self::add($out, $id, $normalized === $whole, $normalized, (string) $row->card_sku, (string) $row->sku,
                $label !== trim((string) $row->sku) ? $label : '', true);
        }

        return array_map(static fn (array $hit): array => ['codes' => $hit['codes'], 'exact' => $hit['exact']], $out);
    }

    /**
     * @param  array<int, array{codes: list<string>, exact: bool}>  $hits
     * @return list<int> karty, w których numer równa się całej frazie — w kolejności stoją przy dokładnym SKU
     */
    public static function exactIds(array $hits): array
    {
        return array_keys(array_filter($hits, static fn (array $hit): bool => $hit['exact']));
    }

    /**
     * @param  list<string>  $needles
     * @return iterable<object>
     */
    private function identifierRows(array $needles, ?string $gtin): iterable
    {
        return ProductIdentifier::query()
            ->toBase()
            ->join('products', 'products.id', '=', 'product_identifiers.product_id')
            ->whereNull('product_identifiers.removed_at')
            ->where(function ($q) use ($needles, $gtin): void {
                foreach ($needles as $needle) {
                    $q->orWhere(fn ($code) => $code
                        ->whereNotIn('product_identifiers.type', self::EAN_TYPES)
                        ->where('product_identifiers.normalized', 'like', '%'.$needle.'%'));
                }
                if ($gtin !== null) {
                    $q->orWhere(fn ($ean) => $ean
                        ->whereIn('product_identifiers.type', self::EAN_TYPES)
                        ->where('product_identifiers.normalized', $gtin));
                }
            })
            ->orderBy('product_identifiers.product_id')
            ->orderBy('product_identifiers.id')
            ->limit(self::MAX_ROWS)
            ->get([
                'product_identifiers.product_id',
                'product_identifiers.type',
                'product_identifiers.value',
                'product_identifiers.normalized',
                'product_identifiers.variant_label',
                'products.sku as card_sku',
                'products.name as card_name',
                'products.manufacturer as card_manufacturer',
            ]);
    }

    /**
     * Aktywne wiersze rozmiarów, których kod zawiera wpisany ciąg.
     *
     * @param  list<string>  $needles
     * @return iterable<object>
     */
    private function sizeRows(array $needles): iterable
    {
        if ($needles === []) {
            return [];
        }

        return ProductVariant::query()
            ->toBase()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('product_variants.kind', ProductVariant::KIND_SIZE)
            ->whereNull('product_variants.removed_at')
            ->where(function ($q) use ($needles): void {
                foreach ($needles as $needle) {
                    $q->orWhere('product_variants.sku', 'like', '%'.addcslashes($needle, '%_\\').'%');
                }
            })
            ->orderBy('product_variants.product_id')
            ->orderBy('product_variants.id')
            ->limit(self::MAX_ROWS)
            ->get([
                'product_variants.product_id',
                'product_variants.sku',
                'product_variants.label',
                'products.sku as card_sku',
                'products.name as card_name',
                'products.manufacturer as card_manufacturer',
            ]);
    }

    /**
     * @param  array<int, array{codes: list<string>, exact: bool, by_size: bool}>  $out
     * @param  string|null  $normalized  null = EAN (nie porównujemy z SKU karty)
     */
    private static function add(
        array &$out,
        int $id,
        bool $exact,
        ?string $normalized,
        string $cardSku,
        string $value,
        string $label,
        bool $bySize = false,
    ): void {
        $out[$id] ??= ['codes' => [], 'exact' => false, 'by_size' => $bySize];
        $out[$id]['exact'] = $out[$id]['exact'] || $exact;
        // numer równy SKU karty widać już w kolumnie SKU
        if ($normalized !== null && $normalized === ProductIdentifierCode::code($cardSku)) {
            return;
        }
        $label = trim($label);
        $code = trim($value).($label !== '' ? ' ('.$label.')' : '');
        if (! in_array($code, $out[$id]['codes'], true) && count($out[$id]['codes']) < self::MAX_CODES_PER_CARD) {
            $out[$id]['codes'][] = $code;
        }
    }

    private static function plain(string $text): string
    {
        return strtolower(Str::ascii($text));
    }
}
