<?php

declare(strict_types=1);

namespace App\Services\PriceLists\Importers;

use App\Models\Product;

/**
 * Importer cennika z pliku (10.10.2026) — klasa per cennik pisana przez programistę, jak łącznik B2B
 * (B2bConnectorRegistry). Czyta plik deterministycznie (bez LLM, bez zgadywania kolumn) i ustala źródło opisu
 * każdej karty (mapa karty → strona producenta / dostawcy / sklepu). Nic nie zapisuje — zapis robi
 * PriceListImportService::importCollected (karty) i MapPriceListSourcesJob (mapa).
 *
 * Nowy importer = nowa klasa dopisana do PriceListImporterRegistry::IMPORTERS + testy na prawdziwym pliku
 * i prawdziwych stronach producenta (fixture'y). Zmiana read() albo mapSource() = podbicie version().
 */
interface PriceListImporter
{
    /** Stały klucz zapisany w price_lists.importer_key — nigdy nie zmieniany. */
    public static function key(): string;

    public static function label(): string;

    /** Wersja logiki — podbić przy każdej zmianie read() lub mapSource() (mapa liczona starszą wersją jest przeliczana). */
    public static function version(): int;

    /**
     * manufacturer_key cenników, dla których importer powstał — tylko podpowiedź przy wiązaniu
     * (price-lists:bind), bez automatycznego przypisania.
     *
     * @return list<string>
     */
    public static function manufacturerKeys(): array;

    /**
     * Deterministyczny odczyt pliku. Bez zapisu i bez LLM.
     *
     * @throws PriceListFormatChanged gdy nagłówki/układ pliku nie odpowiadają temu, pod który napisano importer
     */
    public function read(string $path, string $originalName, ReadContext $ctx): ReadResult;

    /**
     * Źródło opisu karty. Karta może być niezapisana (podgląd importu). Bez zapisu w bazie.
     *
     * @param  list<ImportedRow>  $rows  wiersze pliku, które trafiły na tę kartę (zwykle jeden)
     */
    public function mapSource(Product $card, array $rows, MapContext $ctx): SourceDecision;
}
