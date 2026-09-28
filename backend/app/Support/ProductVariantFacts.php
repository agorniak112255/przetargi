<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\RequirementCheck\ColorChecker;

/**
 * Warianty karty (aktywne wiersze product_variants) w postaci potrzebnej dopasowaniu przetargu: kody i etykiety
 * do bramki „inny wariant”, barwy do porównania koloru, krótka lista dla oceny AI i wybór wariantu do oferty.
 * Czytane w chwili dopasowania (nie kopią w products) — wiersze wariantów zmienia wiele ścieżek zapisem masowym
 * bez zdarzeń modelu, kopia by się rozjeżdżała. Jedna instancja na żądanie/zadanie (scoped), pamięć po id karty.
 */
final class ProductVariantFacts
{
    private const MEMO_LIMIT = 2000;

    private const SUMMARY_MAX_CHARS = 200;

    private const SUMMARY_MAX_CODES = 8;

    /** @var array<int, list<ProductVariant>> */
    private array $memo = [];

    /** @var array<int, list<string>> barwy wariantu po jego id */
    private array $colourMemo = [];

    /**
     * @return list<array{id: int, kind: string, sku: ?string, label: string, colours: list<string>, purchase_price: ?string, currency: ?string, availability: ?string}>
     */
    public function activeRows(Product $product): array
    {
        return array_map(fn (ProductVariant $v): array => [
            'id' => (int) $v->id,
            'kind' => (string) $v->kind,
            'sku' => $v->sku !== null ? (string) $v->sku : null,
            'label' => (string) $v->label,
            'colours' => $this->variantColours($v),
            'purchase_price' => $v->purchase_price !== null ? (string) $v->purchase_price : null,
            'currency' => $v->currency !== null ? (string) $v->currency : null,
            'availability' => $v->availability !== null ? (string) $v->availability : null,
        ], $this->variants($product));
    }

    /**
     * Wczytuje warianty wielu kart jednym zapytaniem (porcjami) — przed pętlą po kandydatach.
     *
     * @param  iterable<Product>  $products
     */
    public function prime(iterable $products): void
    {
        $ids = [];
        foreach ($products as $product) {
            $id = $product->id;
            if ($id !== null && ! array_key_exists((int) $id, $this->memo)) {
                $ids[(int) $id] = true;
            }
        }
        if ($ids === []) {
            return;
        }
        $this->trimMemo(count($ids));
        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            foreach ($chunk as $id) {
                $this->memo[$id] = [];
            }
            $rows = ProductVariant::query()
                ->whereIn('product_id', $chunk)
                ->whereNull('removed_at')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            foreach ($rows as $row) {
                $this->memo[(int) $row->product_id][] = $row;
            }
        }
    }

    /**
     * Barwy wszystkich aktywnych wariantów (klucze ColorChecker), bez powtórzeń.
     *
     * @return list<string>
     */
    public function colours(Product $product): array
    {
        $out = [];
        foreach ($this->variants($product) as $variant) {
            foreach ($this->variantColours($variant) as $colour) {
                if (! in_array($colour, $out, true)) {
                    $out[] = $colour;
                }
            }
        }

        return $out;
    }

    /**
     * Krótka lista wariantów dla oceny AI — tylko karta z co najmniej dwoma wariantami w co najmniej dwóch
     * barwach (same rozmiary nie zmieniają oceny: rozmiar dobiera handlowiec). Null = karta bez listy barw.
     */
    public function rankSummary(Product $product): ?string
    {
        $variants = $this->variants($product);
        if (count($variants) < 2) {
            return null;
        }
        $colours = $this->colours($product);
        if (count($colours) < 2) {
            return null;
        }
        $summary = 'kolory: '.implode(', ', $colours);
        $skus = [];
        foreach ($variants as $variant) {
            $sku = trim((string) $variant->sku);
            if ($sku !== '' && ! in_array($sku, $skus, true)) {
                $skus[] = $sku;
            }
        }
        if ($skus !== [] && count($skus) <= self::SUMMARY_MAX_CODES) {
            $summary .= '; kody: '.implode(', ', $skus);
        }

        return mb_substr($summary, 0, self::SUMMARY_MAX_CHARS);
    }

    /**
     * Wariant wskazany w wymaganiu, gdy da się go wskazać jednoznacznie:
     * 1) wiersz, którego kod albo etykieta zawiera każdy kod wariantu z wymagania (jako całą liczbę), albo którego kod (bez
     *    separatorów) równa się kodowi z wymagania — dokładnie jeden taki wiersz;
     * 2) gdy kody nic nie wskazały — dokładnie jeden wiersz o tym samym zestawie barw co wymaganie.
     * Kilka pasujących (ten sam kolor w wielu rozmiarach) = null: rozmiar dobiera handlowiec.
     *
     * @param  list<string>  $codes  kody wariantu z wymagania (ProductModelFuzzy::variantCodes, 4 cyfry)
     * @param  list<string>  $skuNeedles  kody z wymagania bez separatorów (co najmniej 5 znaków, z cyfrą)
     * @param  list<string>  $colours  barwy wymagane (ColorChecker::requiredColours)
     */
    public function pickRequested(Product $product, array $codes, array $skuNeedles, array $colours): ?ProductVariant
    {
        $variants = $this->variants($product);
        if ($variants === []) {
            return null;
        }

        $needles = array_values(array_filter(array_map(self::compact(...), $skuNeedles), static fn (string $n): bool => $n !== ''));
        $codes = array_values(array_filter($codes, static fn (string $c): bool => $c !== ''));
        if ($codes !== [] || $needles !== []) {
            $byCode = array_values(array_filter($variants, static function (ProductVariant $v) use ($codes, $needles): bool {
                $sku = self::compact((string) $v->sku);
                if ($sku !== '' && in_array($sku, $needles, true)) {
                    return true;
                }
                if ($codes === []) {
                    return false;
                }
                // kod jako cała liczba w surowym kodzie albo etykiecie („ARMEN-9007-1010-42”), nie cyfry w środku
                // dłuższego numeru („7000101012”)
                foreach ($codes as $code) {
                    $pattern = '/(?<!\d)'.preg_quote($code, '/').'(?!\d)/u';
                    if (preg_match($pattern, (string) $v->sku) !== 1 && preg_match($pattern, (string) $v->label) !== 1) {
                        return false;
                    }
                }

                return true;
            }));
            if (count($byCode) === 1) {
                return $byCode[0];
            }
            if ($byCode !== []) {
                return null;
            }
        }

        if ($colours === []) {
            return null;
        }
        $wanted = array_values(array_unique($colours));
        sort($wanted);
        $byColour = array_values(array_filter($variants, function (ProductVariant $v) use ($wanted): bool {
            $have = $this->variantColours($v);
            sort($have);

            return $have === $wanted;
        }));

        return count($byColour) === 1 ? $byColour[0] : null;
    }

    /**
     * Karty, których aktywny wariant ma kod zaczynający się od podanego (kody od 5 znaków — krótsze trafiają
     * przypadkiem).
     *
     * @param  list<string>  $codes
     * @return list<int>
     */
    public function productIdsBySku(array $codes, int $cap): array
    {
        $long = array_values(array_filter($codes, static fn (string $c): bool => mb_strlen($c) >= 5));
        if ($long === [] || $cap < 1) {
            return [];
        }
        $compactSku = "replace(replace(replace(replace(lower(sku), '/', ''), '-', ''), '.', ''), ' ', '')";

        return ProductVariant::query()
            ->whereNull('removed_at')
            ->where(function ($outer) use ($long, $compactSku): void {
                foreach ($long as $code) {
                    $like = addcslashes($code, '%_\\');
                    $outer->orWhere('sku', 'like', $like.'%')
                        ->orWhereRaw($compactSku.' like ?', [mb_strtolower($like).'%']);
                }
            })
            ->distinct()
            ->limit($cap)
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function forget(): void
    {
        $this->memo = [];
        $this->colourMemo = [];
    }

    /**
     * @return list<ProductVariant>
     */
    private function variants(Product $product): array
    {
        $id = $product->id;
        if ($id === null) {
            return [];
        }
        $id = (int) $id;
        if (! array_key_exists($id, $this->memo)) {
            $this->trimMemo(1);
            $this->memo[$id] = ProductVariant::query()
                ->where('product_id', $id)
                ->whereNull('removed_at')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->all();
        }

        return $this->memo[$id];
    }

    /**
     * Barwy z etykiety i wartości atrybutów wariantu („czerwony 42”, {"kolor": "żółty"}).
     *
     * @return list<string>
     */
    private function variantColours(ProductVariant $variant): array
    {
        $key = (int) $variant->id;
        if (! array_key_exists($key, $this->colourMemo)) {
            $text = (string) $variant->label;
            $attributes = $variant->getAttribute('attributes');
            if (is_array($attributes)) {
                array_walk_recursive($attributes, static function ($value) use (&$text): void {
                    if (is_string($value)) {
                        $text .= ' | '.$value;
                    }
                });
            }
            $this->colourMemo[$key] = ColorChecker::colorsIn($text);
        }

        return $this->colourMemo[$key];
    }

    private function trimMemo(int $incoming): void
    {
        if (count($this->memo) + $incoming > self::MEMO_LIMIT) {
            $this->memo = [];
            $this->colourMemo = [];
        }
    }

    /** Jak ProductModelFuzzy::compact — małe litery bez polskich znaków, same litery i cyfry. */
    private static function compact(string $s): string
    {
        $s = mb_strtolower($s);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return preg_replace('/[^a-z0-9]/', '', strtr($s, $map)) ?? '';
    }
}
