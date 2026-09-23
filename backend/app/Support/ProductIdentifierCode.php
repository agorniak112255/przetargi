<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProductIdentifier;
use Illuminate\Support\Str;

/**
 * Postać identyfikatora do wyszukiwania (product_identifiers.normalized). Wartość ze źródła zostaje obok dosłownie —
 * tu tylko klucz porównania.
 *
 * EAN/GTIN → 14 cyfr z zerami z lewej: EAN-13, UPC-12 i GTIN-14 z zerem na początku to ten sam wyrób, a GTIN-14
 * ze wskaźnikiem 1–8 (karton) zostaje innym kluczem. Zła suma kontrolna albo zapis wykładniczy z arkusza
 * („5.9E+12”) → null: taki kod nie może niczego połączyć.
 *
 * Kody → litery i cyfry wielkimi literami, bez spacji, kropek i myślników („3M-MAS-6000” = „3MMAS6000”,
 * „2111.237” = „2111 237”); zera z przodu zostają, bo bywają częścią kodu.
 */
final class ProductIdentifierCode
{
    public static function normalize(string $type, string $value): ?string
    {
        return in_array($type, [ProductIdentifier::TYPE_EAN, ProductIdentifier::TYPE_PACK_EAN], true)
            ? self::gtin($value)
            : self::code($value);
    }

    public static function gtin(string $value): ?string
    {
        $digits = preg_replace('/[\s\-]+/u', '', trim($value)) ?? '';
        if (! preg_match('/^\d+$/', $digits) || ! in_array(strlen($digits), [8, 12, 13, 14], true)) {
            return null;
        }
        if (trim($digits, '0') === '' || ! self::validCheckDigit($digits)) {
            return null;
        }

        return str_pad($digits, 14, '0', STR_PAD_LEFT);
    }

    public static function code(string $value): ?string
    {
        $code = preg_replace('/[^A-Z0-9]+/', '', strtoupper(Str::ascii($value))) ?? '';

        return $code !== '' ? $code : null;
    }

    /** Cyfra kontrolna GS1 (mod 10): wagi 3 i 1 na przemian od prawej, bez samej cyfry kontrolnej. */
    private static function validCheckDigit(string $digits): bool
    {
        $body = substr($digits, 0, -1);
        $sum = 0;
        $weight = 3;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }
}
