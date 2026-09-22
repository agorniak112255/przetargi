<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Wiersz cennika bazowego dostawcy dopasowany do karty (B2bStandardDiscountSite) i rabat standardowy z reguł
 * konta (B2bDiscountRuleResolver: numer katalogowy = kod karty, kategoria = $category, nazwa = nazwa karty).
 */
final readonly class B2bBasePrice
{
    /**
     * @param  float  $net  cena netto z cennika bazowego
     * @param  string  $category  kategoria wiersza (UVEX: nazwa arkusza), dosłownie z pliku
     * @param  string  $code  kod wiersza, dosłownie z pliku
     * @param  string  $source  skąd: plik, arkusz, nazwa wiersza, data pobrania (do 255 znaków)
     * @param  float|null  $standardDiscountPercent  rabat standardowy z reguły konta; null = żadna reguła nie pasuje
     */
    public function __construct(
        public float $net,
        public string $category,
        public string $code,
        public string $source,
        public ?float $standardDiscountPercent,
    ) {}
}
