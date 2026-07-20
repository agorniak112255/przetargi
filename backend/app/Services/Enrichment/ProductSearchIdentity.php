<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;

/**
 * Jedna tożsamość produktu do wyszukiwania i filtrowania wyników.
 * SKU w cenniku bywa „PROS-1001”, a w sieci „1001” / „101/001” / „model 1001”.
 */
final class ProductSearchIdentity
{
    /**
     * Tokeny do dopasowania w URL/tytule/snippecie (lowercase, unikalne).
     *
     * @return list<string>
     */
    public function matchTokens(Product $product): array
    {
        $sku = trim((string) $product->sku);
        $name = trim((string) $product->name);
        $brand = $this->shortBrand((string) $product->manufacturer);

        $raw = [];
        if ($sku !== '') {
            $raw[] = $sku;
            $raw[] = $this->stripBrandPrefix($sku, $brand);
        }
        if ($name !== '' && mb_strtolower($name) !== mb_strtolower($sku)) {
            $raw[] = $name;
            $raw[] = $this->stripBrandPrefix($name, $brand);
        }

        $out = [];
        foreach ($raw as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $low = mb_strtolower($value);
            $out[] = $low;
            $compact = preg_replace('/[^a-z0-9]+/iu', '', $low) ?? $low;
            if ($compact !== '' && $compact !== $low) {
                $out[] = $compact;
            }
            // 101/001 → 101-001
            $slashToDash = str_replace('/', '-', $low);
            if ($slashToDash !== $low) {
                $out[] = $slashToDash;
            }
            // sam rdzeń cyfrowy (≥3) z kodu typu PROS-1001 / 101/001
            if (preg_match_all('/\d{3,}/u', $low, $m)) {
                foreach ($m[0] as $digits) {
                    $out[] = $digits;
                }
            }
        }

        $out = array_values(array_unique(array_filter(
            $out,
            static fn (string $t): bool => $t !== '' && mb_strlen($t) >= 3
        )));

        // za krótkie same „100” itd. — zostaw tylko gdy nie ma lepszych
        return $out !== [] ? $out : array_values(array_filter([mb_strtolower($sku), mb_strtolower($name)]));
    }

    /**
     * Zapytania Tavily w kolejności priorytetu.
     *
     * @return list<string>
     */
    public function searchQueries(Product $product, string $phase): array
    {
        $brand = $this->shortBrand((string) $product->manufacturer);
        $sku = trim((string) $product->sku);
        $name = trim((string) $product->name);
        $bare = $this->stripBrandPrefix($sku !== '' ? $sku : $name, $brand);
        $hint = $this->productHint($product);
        $phaseHint = $phase === 'industry' ? 'karta produktu' : 'datasheet OR karta';

        $queries = [];

        // 1) Jak Google — najpierw czysty kod / marka+kod, BEZ sztucznego hintu kategorii
        if ($sku !== '') {
            $queries[] = $sku;
            $queries[] = '"'.$sku.'"';
        }
        if ($brand !== '' && $bare !== '') {
            $queries[] = trim($brand.' '.$bare);
            if ($bare !== $sku) {
                $queries[] = trim('"'.$bare.'" '.$brand);
            }
        }
        if ($name !== '' && mb_strtolower($name) !== mb_strtolower($sku)
            && mb_strtolower($name) !== mb_strtolower($bare)) {
            $queries[] = trim($brand.' '.$name);
        }

        // 2) Dopiero potem warianty z hintem (gdy nazwa sugeruje kategorię)
        if ($hint !== '') {
            if ($brand !== '' && $bare !== '') {
                $queries[] = trim($brand.' '.$bare.' '.$hint);
            }
            if ($sku !== '') {
                $queries[] = trim('"'.$sku.'" '.$brand.' '.$hint);
            }
        }

        // 3) Datasheet / karta
        if ($bare !== '') {
            $queries[] = trim($brand.' '.$bare.' '.$phaseHint);
            $queries[] = trim($brand.' model '.$bare);
        }

        return array_values(array_unique(array_filter(
            $queries,
            static fn (string $q): bool => trim($q) !== ''
        )));
    }

    /**
     * Czy tekst wyniku dotyczy tego produktu.
     */
    public function hayMentionsProduct(string $hay, Product $product): bool
    {
        $hay = mb_strtolower($hay);
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        $tokens = $this->matchTokens($product);
        $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
        $hayDigits = preg_replace('/\D+/u', '', $hay) ?? '';

        foreach ($tokens as $token) {
            if ($this->tokenInHay($hay, $hayCompact, $token)) {
                // krótki sam kod numeryczny → wymagaj marki w tekście (mniej false positive)
                if ($this->isShortNumericToken($token) && $brand !== '' && ! str_contains($hay, $brand)
                    && ! str_contains($hayCompact, preg_replace('/[^a-z0-9]+/iu', '', $brand) ?? $brand)) {
                    continue;
                }

                return true;
            }
        }

        // PROS-1001 vs „101/001”: cyfry z wyniku zawierają rdzeń ≥4 cyfr
        $digitCore = $this->primaryDigitCore($product);
        if ($digitCore !== null && mb_strlen($digitCore) >= 4 && $hayDigits !== ''
            && str_contains($hayDigits, $digitCore)) {
            if ($brand === '' || str_contains($hay, $brand)
                || str_contains($hayCompact, preg_replace('/[^a-z0-9]+/iu', '', $brand) ?? $brand)) {
                return true;
            }
        }

        return false;
    }

    public function coreInUrlOrTitle(string $url, string $title, Product $product): bool
    {
        return $this->hayMentionsProduct($url.' '.$title, $product);
    }

    public function shortBrand(string $manufacturer): string
    {
        $m = trim($manufacturer);
        if ($m === '') {
            return '';
        }
        $first = trim(explode('/', $m)[0] ?? $m);
        $first = trim(explode('(', $first)[0] ?? $first);

        return mb_substr($first, 0, 40);
    }

    public function stripBrandPrefix(string $code, string $brand): string
    {
        $code = trim($code);
        $brand = trim($brand);
        if ($code === '' || $brand === '') {
            return $code;
        }
        $pattern = '/^'.preg_quote($brand, '/').'[\s\-_\/]+/iu';
        $stripped = trim((string) preg_replace($pattern, '', $code));

        return $stripped !== '' ? $stripped : $code;
    }

    private function tokenInHay(string $hay, string $hayCompact, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        if (str_contains($hay, $token)) {
            return true;
        }
        $tokenCompact = preg_replace('/[^a-z0-9]+/iu', '', $token) ?? $token;
        if ($tokenCompact !== '' && str_contains($hayCompact, $tokenCompact)) {
            return true;
        }
        // granica dla samych cyfr (unikaj 1001 w 21001 bez sensu — lookahead)
        if (preg_match('/^\d{3,}$/', $token) === 1) {
            return preg_match('/(?<![0-9])'.preg_quote($token, '/').'(?![0-9])/u', $hay) === 1
                || str_contains($hayCompact, $token);
        }

        return false;
    }

    private function isShortNumericToken(string $token): bool
    {
        return preg_match('/^\d{3,6}$/', $token) === 1;
    }

    private function primaryDigitCore(Product $product): ?string
    {
        foreach ([(string) $product->sku, (string) $product->name] as $value) {
            $bare = $this->stripBrandPrefix($value, $this->shortBrand((string) $product->manufacturer));
            if (preg_match('/(\d{4,})/u', $bare, $m)) {
                return $m[1];
            }
            $digits = preg_replace('/\D+/u', '', $bare) ?? '';
            if (mb_strlen($digits) >= 4) {
                return mb_substr($digits, 0, 8);
            }
        }

        return null;
    }

    /**
     * Opcjonalny dopisek kategorii — TYLKO gdy nazwa/SKU na to wskazuje.
     * Sam producent (np. „PROS”) nie narzuca „ubranie wodoochronne”.
     */
    private function productHint(Product $product): string
    {
        $nameSku = mb_strtolower(trim((string) $product->name.' '.(string) $product->sku));
        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));

        if (preg_match(
            '#(trzewik|p[oó]łbut|polbut|\bbuty\b|obuwie|\bs1\b|\bs3\b|\bsrc\b|\bhro\b|demar|befado)#u',
            $nameSku
        ) || preg_match('#(demar|befado)#u', $brand)) {
            return 'buty ochronne';
        }

        if (preg_match('#(glove|r[eę]kaw|maxiflex|maxicut|maxidry)#u', $nameSku)
            || preg_match('#^(atg|ansell)$#u', $brand)) {
            return 'rękawice';
        }

        // Odzież wodoochronna — słowa z nazwy, NIE sama marka PROS
        if (preg_match(
            '#(wodoochron|plavitex|kurtka|spodnie|ubranie|odzież|odziez|płaszcz|plaszcz)#u',
            $nameSku
        )) {
            return 'ubranie wodoochronne';
        }

        return '';
    }
}
