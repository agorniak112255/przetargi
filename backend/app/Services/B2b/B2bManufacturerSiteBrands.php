<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Support\BrandKey;

/**
 * Marki witryny producenta: marka główna (B2bManufacturerSite::ownBrand) i pozostałe marki tej samej witryny
 * (B2bManufacturerBrands — Hultafors Group). Łącznik bez drugiego znacznika ma dokładnie [ownBrand] — dla niego nic
 * się nie zmienia (UVEX nadal nie jest producentem HECKEL ani HexArmor).
 *
 * Marki porównujemy jak dotąd przez BrandKey (pierwsze słowo nazwy). Marka dodatkowa z kluczem krótszym niż 3 znaki
 * („W.steps” → „w”) pasowałaby do każdej marki zaczynającej się od tej litery — taka marka nie należy do witryny
 * (wyroby zostają z producentem dosłownie z witryny, bez pierwszeństwa producenta).
 */
final class B2bManufacturerSiteBrands
{
    private const MIN_EXTRA_KEY_LENGTH = 3;

    /**
     * @param  class-string  $class  klasa łącznika (także anonimowego z testów)
     * @return list<string>  [] = łącznik nie jest witryną producenta
     */
    public static function all(string $class): array
    {
        if (! is_a($class, B2bManufacturerSite::class, true)) {
            return [];
        }
        $brands = [$class::ownBrand()];
        if (is_a($class, B2bManufacturerBrands::class, true)) {
            foreach ($class::ownBrands() as $brand) {
                if (mb_strlen(BrandKey::of($brand)) >= self::MIN_EXTRA_KEY_LENGTH) {
                    $brands[] = $brand;
                }
            }
        }

        return array_values(array_unique($brands));
    }

    /**
     * Marka witryny, do której należy karta tego producenta; null = karta innej marki (albo łącznik nie jest witryną
     * producenta).
     *
     * @param  class-string  $class
     */
    public static function matching(string $class, string $manufacturer): ?string
    {
        foreach (self::all($class) as $brand) {
            if (BrandKey::same($brand, $manufacturer)) {
                return $brand;
            }
        }

        return null;
    }
}
