<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Marka po odsianiu wielkości liter, znaków i dopisków — pierwsze słowo nazwy („Bolle Safety” = „BOLLE”,
 * „JHK Polska” = „JHK”). Do rozpoznania, że źródło ceny albo opisu należy do producenta karty; do kluczy reguł
 * służy dokładniejszy PriceList::manufacturerKey.
 */
final class BrandKey
{
    public static function of(string $value): string
    {
        $value = trim(explode('(', explode('/', $value)[0])[0]);
        // bez transliteracji „Bollé” dawało „boll”, a polskie litery rwały słowo
        $value = mb_strtolower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(explode(' ', trim($value))[0]);
    }

    public static function same(string $a, string $b): bool
    {
        $key = self::of($a);

        return $key !== '' && $key === self::of($b);
    }
}
