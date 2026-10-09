<?php

declare(strict_types=1);

namespace App\Services\Enrichment\PartsTable;

use App\Models\ManufacturerPart;
use App\Models\Product;
use App\Services\Enrichment\ManufacturerProfile;

/**
 * Strona producenta z tabelą części (parser jednego szablonu) i przypięcie karty cennika do wiersza tej tabeli.
 * Klasa z klucza `resolver` profilu marki (config/manufacturer_profiles.php); rejestr — PartsTables.
 */
interface PartsTableResolver
{
    /**
     * Wiersze tabeli części ze strony: kod dosłownie z tabeli (part), opis wiersza nie wchodzi. style_image — zdjęcie
     * z sekcji stylów w kolorze wiersza, gdy dokładnie jedno pasuje; model_image — zdjęcie modelu z wiersza.
     *
     * @return array{title: ?string, rows: list<array{part: string, label: string, size: ?string, colour: ?string, weight_kg: ?float, model_image: ?string, style_image: ?string}>, has_styles: bool}
     */
    public function parse(string $html, string $url): array;

    /**
     * Przypięcie karty do wiersza; $rows = wszystkie wiersze marki (ManufacturerPart) z PartsTables::rowsFor().
     *
     * @param  list<ManufacturerPart>  $rows
     */
    public function pinFor(Product $product, ManufacturerProfile $profile, array $rows): PinResult;
}
