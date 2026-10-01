<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Witryna producenta kilku własnych marek (Hultafors Group: Snickers Workwear, Hellberg Safety, Solid Gear…) — obok
 * marki głównej (B2bManufacturerSite::ownBrand) podaje pozostałe marki, których jest autorem. Karta każdej z nich
 * należy do tej witryny tak samo jak karta marki głównej (opis producenta, zdjęcia, normy, pierwszeństwo ceny).
 */
interface B2bManufacturerBrands
{
    /**
     * Pozostałe marki witryny, dosłownie jak producent na kartach (bez marki głównej).
     *
     * @return list<string>
     */
    public static function ownBrands(): array;
}
