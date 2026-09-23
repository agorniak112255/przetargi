<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Marka sprowadzona do producenta: najpierw słownik marek z administracji (BrandDictionary — „PELTOR” to marka 3M),
 * potem pierwsze słowo nazwy jak w BrandKey („Bolle Safety” = „BOLLE”, „ANRO” = „Anro”). Do rozpoznania, że konto B2B
 * albo cennik z pliku należy do producenta karty (CardOwnership) — dystrybutor podaje producenta w swoim brzmieniu
 * (P4S: „PELTOR”, karta konta 3M: „3M”), a to wciąż ten sam właściciel.
 */
final class CanonicalBrand
{
    /** Klucz marki producenta; '' dla pustej nazwy. */
    public static function key(?string $manufacturer): string
    {
        $manufacturer = trim((string) $manufacturer);
        if ($manufacturer === '') {
            return '';
        }
        $producer = app(BrandDictionary::class)->producerFor(BrandDictionary::key($manufacturer));

        return BrandKey::of($producer ?? $manufacturer);
    }

    /** Ta sama marka producenta — obie nazwy niepuste. */
    public static function same(?string $a, ?string $b): bool
    {
        $key = self::key($a);

        return $key !== '' && $key === self::key($b);
    }
}
