<?php

declare(strict_types=1);

namespace App\Support\RequirementCheck;

use App\Models\Product;
use App\Support\ManufacturerNormFacts;
use App\Support\ProductDescriptionText;

/**
 * Fragmenty karty w kolejności pokazywania: nazwa, kolumna norm, normy producenta, cennik, parametry ręczne,
 * tabelka dostawcy, specyfikacja, cechy, normy i materiały z opisu pobranego, na końcu opis.
 * `enrichment_payload.attributes` celowo pomijamy: to dane pochodne bez cytatu i z błędami (karta 11202000: „bez lateksu” zapisane jako rodzina
 * materiału „lateks”), a porównanie ma pokazywać tylko to, co karta mówi wprost.
 */
final class CardSources
{
    /**
     * @return list<CardSource>
     */
    public static function fromProduct(Product $product): array
    {
        $out = [];
        self::push($out, CardSource::NAME, (string) $product->name);
        self::push($out, CardSource::NORMS, (string) ($product->norms ?? ''));

        // Normy z karty u producenta wyrobu stoją najwyżej: pary są dosłowne, mają adres strony i datę
        // odczytu, a poziomy EN 388 z opisów sklepowych bywają cudzym wyrobem albo starym wydaniem normy.
        foreach (ManufacturerNormFacts::rows($product->manufacturer_norms) as $row) {
            self::push($out, CardSource::MANUFACTURER, $row['label'], $row['value'] === '' ? null : $row['value']);
        }

        // Cennik dostawcy jest dokumentem z datą obowiązywania, więc w odróżnieniu od attributes
        // wolno go cytować — i trzeba, bo to z niego bierze się klasa ochrony przy dopasowaniu.
        foreach (is_array($product->price_list_attributes) ? $product->price_list_attributes : [] as $label => $value) {
            if (is_string($value)) {
                self::push($out, CardSource::PRICE_LIST, self::PRICE_LIST_LABELS[$label] ?? (string) $label, $value);
            }
        }

        // Parametry wpisane ręcznie stoją zaraz za cennikiem: są wskazywalne co do wiersza
        // i nikt ich nie nadpisuje, więc przy wymaganiu przetargu są najpewniejszym cytatem.
        foreach (Product::manualSpecRows($product->manual_specs) as $row) {
            self::push($out, CardSource::MANUAL, $row['label'], $row['value']);
        }

        // Tabelka z karty wyrobu u dostawcy, wiersz po wierszu: to cytat ze sklepu dostawcy (norma z klasą
        // bywa tylko tam), ale nie rozstrzyga — ARTRA ma tabelki zamienione między wariantami, więc wiersz
        // „norma: EN ISO 20345:2011 S1 P SRC” przy nazwie „O1 FO” ma wyjść jako sprzeczność karty.
        foreach (self::shopFieldRows((string) ($product->shop_fields_summary ?? '')) as $line) {
            self::push($out, CardSource::SHOP_FIELDS, $line);
        }

        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        foreach ([
            CardSource::SPECS => 'specs',
            CardSource::FEATURES => 'features',
            CardSource::PAYLOAD_NORMS => 'norms',
            CardSource::MATERIALS => 'materials',
        ] as $source => $key) {
            foreach (is_array($payload[$key] ?? null) ? $payload[$key] : [] as $item) {
                if (is_string($item)) {
                    self::push($out, $source, $item);
                }
            }
        }

        self::push($out, CardSource::DESCRIPTION, ProductDescriptionText::plain($product->description));

        return $out;
    }

    /** @var array<string, string> */
    private const PRICE_LIST_LABELS = [
        'klasa_ochrony' => 'Klasa ochrony',
        'normy' => 'Normy',
        'rozmiar' => 'Rozmiar',
        'kolor' => 'Kolor',
        'material' => 'Materiał',
    ];

    /** Nagłówek sekcji tabeli rozmiarów ARTRY (ArtraB2bConnector::SECTION_SIZE_GUIDE). */
    private const SIZE_GUIDE_SECTION = 'przewodnik po rozmiarach';

    /**
     * Wiersze tabelki dostawcy, które coś mówią o wyrobie. Układ jest zamrożony (B2bCatalogSync::shopFieldsSummary):
     * nagłówek sekcji w osobnej linii bez „: ”, pod nim wiersze „nazwa: wartość”. Nagłówki odpadają (to nie
     * twierdzenie), a tabela rozmiarów razem z listą rozmiarów też — ARTRA ma ~15 wierszy „Rozmiar EU 35: 21,8”
     * na każdej karcie: w zapytaniu do modelu to szum, a liczby bez jednostki dawały fałszywe trafienia wymiarów.
     *
     * @return list<string>
     */
    private static function shopFieldRows(string $summary): array
    {
        $rows = [];
        $inSizeGuide = false;
        foreach (preg_split('/\R/u', $summary) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! str_contains($line, ': ')) {
                $inSizeGuide = mb_strtolower($line) === self::SIZE_GUIDE_SECTION;

                continue;
            }
            if ($inSizeGuide
                || preg_match('/^rozmiary\s*:/iu', $line) === 1
                // wiersz tabeli rozmiarów („Rozmiar EU 35: 21,8”); sam „Rozmiar: 9” to rozmiar wyrobu i zostaje
                || preg_match('/^rozmiar\h+[^:]+:\s*[\d.,\s\/–-]+$/iu', $line) === 1) {
                continue;
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /**
     * @param  list<CardSource>  $out
     */
    private static function push(array &$out, string $source, string $text, ?string $value = null): void
    {
        $text = $value === null ? trim($text) : trim($text).': '.trim($value);
        $text = trim($text, ': ');
        if ($text !== '') {
            $out[] = new CardSource($source, $text);
        }
    }
}
