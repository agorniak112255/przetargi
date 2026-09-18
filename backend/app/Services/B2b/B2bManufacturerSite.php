<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik witryny producenta wyrobów — nie dystrybutora. Opis stamtąd pochodzi od autora wyrobu,
 * więc w hierarchii źródeł opisu stoi najwyżej: zastępuje opis już zapisany na karcie (z AI,
 * z Presty, z witryny dystrybutora). Poza producentem nic opisu nie nadpisuje — reguła
 * `B2bCatalogSync::mayWriteDescription` zostaje dla wszystkich pozostałych łączników bez zmian.
 *
 * Marker to za mało: łącznik producenta bywa też sklepem cudzych marek (uvex sprzedaje HECKEL,
 * HexArmor), dlatego nadpisanie wymaga dodatkowo zgodności marki konta z marką karty.
 */
interface B2bManufacturerSite
{
    /**
     * Marka, do której należy ta witryna. Tylko karty tej marki wolno nadpisać: uvex prowadzi
     * sklep także dla HECKEL i HexArmor, a dla tych marek jest dystrybutorem, nie autorem wyrobu.
     */
    public static function ownBrand(): string;
}
