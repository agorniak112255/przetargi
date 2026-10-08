<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Support\ColourWords;

/**
 * Fakty o karcie z samego cennika (etap 2 opisów z cenników): członek modelu dostaje opis prozą lidera, ale jego
 * własne parametry — wymiary i grubość z nazwy („0.9m x 18.3m (9.5mm)” → „Wymiary: 0,9 × 18,3 m”, „Grubość: 9,5 mm”),
 * sprzedaż na metry bieżące („x mb.”), kolor z nazwy, atrybuty z kolumn cennika (price_list_attributes: rozmiar,
 * klasa ochrony, kolor, materiał) i EAN — idą do specs i do dowodów jako `explicit` ze źródłem `price_list`
 * (cytat = fragment nazwy albo wartość komórki, bez skrótu źródła). Bez ceny. Normy z cennika zostają listą norm
 * (jak u lidera), typ wyrobu to klasyfikacja, nie parametr — oba pominięte. Wiersz koloru jest dosłowny
 * („Kolor: Czarny/Niebieski”), a do doboru zdjęcia idzie zbiór kolorów kanonicznych — karta dwubarwna to inny kolor
 * niż jednobarwna.
 */
final class PriceListCardFacts
{
    /** etykiety atrybutów cennika (klucze z SpreadsheetColumnMapper::attributeFields bez „attr_”) */
    private const ATTRIBUTE_LABELS = [
        'rozmiar' => 'Rozmiar',
        'klasa_ochrony' => 'Klasa ochrony',
        'kolor' => 'Kolor',
        'material' => 'Materiał',
    ];

    /** @var list<string> */
    private const SKIPPED_ATTRIBUTES = ['normy', 'typ_wyrobu'];

    /**
     * @return array{specs: list<string>, evidence: list<array{field: string, value: string, quote: string, source_sha256: null, status: string, source: string}>, colour: ?string, colours: list<string>}
     *                                                                                                                                                                                                     colours — wszystkie kolory kanoniczne (ColourWords) z atrybutu koloru cennika, a bez niego
     *                                                                                                                                                                                                     z frazy koloru w nazwie, w kolejności („Czarny/Niebieski” → ['black', 'blue']) do doboru
     *                                                                                                                                                                                                     zdjęcia (ModelImagePicker porównuje zbiory); [] = cennik nie podaje. colour — pierwszy z nich
     */
    public function for(Product $p): array
    {
        $specs = [];
        $evidence = [];
        $add = static function (string $line, string $quote) use (&$specs, &$evidence): void {
            if (in_array($line, $specs, true)) {
                return;
            }
            $specs[] = $line;
            $evidence[] = [
                'field' => 'specs',
                'value' => $line,
                'quote' => $quote,
                'source_sha256' => null,
                'status' => 'explicit',
                'source' => 'price_list',
            ];
        };

        $name = trim((string) $p->name);
        if ($name !== '' && preg_match_all(ProductModelKey::DIMENSIONS, $name, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$raw, $offset]) {
                foreach ($this->dimensionLines($raw, self::inParentheses($name, (int) $offset, strlen($raw))) as $line) {
                    $add($line, trim($raw));
                }
            }
        }

        $colours = [];
        $colourFromAttribute = false;
        foreach ($this->attributes($p) as [$key, $label, $value]) {
            $add($label.': '.$value, $value);
            if ($key === 'kolor') {
                $colourFromAttribute = true;
                if ($colours === []) {
                    $colours = ColourWords::allInName($value);
                }
            }
        }
        $phrase = self::colourPhrase($name);
        if ($phrase !== null) {
            if (! $colourFromAttribute) {
                $add('Kolor: '.$phrase, $phrase);
            }
            if ($colours === []) {
                $colours = ColourWords::allInName($phrase);
            }
        }

        $ean = trim((string) $p->ean);
        if ($ean !== '') {
            $add('EAN: '.$ean, $ean);
        }

        return ['specs' => $specs, 'evidence' => $evidence, 'colour' => $colours[0] ?? null, 'colours' => $colours];
    }

    /**
     * Wiersze specs z jednego łańcucha wymiarów: „Wymiary: 0,9 × 18,3 m” (ta sama jednostka raz), „Wymiary: 85 mm × 1 m”,
     * „Grubość: 9,5 mm” (jedna wartość w nawiasie), „Długość maksymalna: 10 m” („maks. 10m”), „Sprzedaż: na metry
     * bieżące” (człon „mb.”). Liczby z przecinkiem dziesiętnym; cytat niesie zapis z nazwy.
     *
     * @return list<string>
     */
    private function dimensionLines(string $raw, bool $inParentheses): array
    {
        $text = trim($raw);
        $text = preg_replace('/^[x×]\s*/iu', '', $text) ?? $text;
        $maximum = preg_match('/^ma(?:ks|x)\.?\s*(?:d[łl]ugo[śs][ćc]\s*)?/iu', $text, $prefix) === 1;
        if ($maximum) {
            $text = trim(substr($text, strlen($prefix[0])));
        }

        $values = [];
        $perMetre = false;
        foreach (preg_split('/\s*[x×]\s*/iu', $text) ?: [] as $part) {
            $part = trim((string) $part);
            if (preg_match('/^mb\.?$/iu', $part) === 1) {
                $perMetre = true;
                $values[] = null;
            } elseif (preg_match('/^(~?)(\d+(?:[.,]\d+)?)\s*(\p{L}*)$/u', $part, $m) === 1) {
                // bez jednostki („0.9 x 1.5m”) — jednostka z sąsiedniego wymiaru
                $values[] = ['number' => $m[1].str_replace('.', ',', $m[2]), 'unit' => mb_strtolower($m[3])];
            }
        }

        $lines = [];
        if ($perMetre) {
            $lines[] = 'Sprzedaż: na metry bieżące';
        }
        $numbers = array_values(array_filter($values));
        $units = array_values(array_unique(array_filter(array_map(static fn (array $v): string => $v['unit'], $numbers))));
        if ($numbers === [] || $units === []) {
            return $lines;
        }
        if ($maximum && count($numbers) === 1) {
            $lines[] = 'Długość maksymalna: '.$numbers[0]['number'].' '.$units[0];

            return $lines;
        }
        if (count($values) === 1) {
            $thickness = $inParentheses && in_array($units[0], ['mm', 'cm'], true);
            $lines[] = ($thickness ? 'Grubość: ' : 'Wymiary: ').$numbers[0]['number'].' '.$units[0];

            return $lines;
        }
        if (count($units) === 1 && ! $perMetre) {
            $lines[] = 'Wymiary: '.implode(' × ', array_map(static fn (array $v): string => $v['number'], $numbers)).' '.$units[0];
        } else {
            $lines[] = 'Wymiary: '.implode(' × ', array_map(
                static fn (?array $v): string => $v === null ? 'mb.' : trim($v['number'].' '.$v['unit']),
                $values
            ));
        }

        return $lines;
    }

    /** Wymiar stoi sam w nawiasie: „(9.5mm)” — to grubość maty, nie kolejny wymiar. */
    private static function inParentheses(string $name, int $offset, int $length): bool
    {
        $before = rtrim(substr($name, 0, $offset));
        $after = ltrim(substr($name, $offset + $length));

        return str_ends_with($before, '(') && str_starts_with($after, ')');
    }

    /**
     * Pierwszy człon koloru z nazwy, dosłownie jak w cenniku: „Szary”, „Czarny/Żółte”, „Żółto/Czarna”, „Clear”.
     * Człon z ukośnikiem liczy się tylko, gdy każde słowo jest kolorem („Krawędź/narożnik” nie).
     */
    private static function colourPhrase(string $name): ?string
    {
        if (preg_match_all('/\p{L}+(?:\/\p{L}+)*/u', $name, $m) < 1) {
            return null;
        }
        foreach ($m[0] as $token) {
            $words = explode('/', $token);
            foreach ($words as $word) {
                if (! ColourWords::is($word)) {
                    continue 2;
                }
            }

            return $token;
        }

        return null;
    }

    /**
     * Atrybuty z kolumn cennika: `klucz => wartość` (PriceListImportService::attributesFromRow) albo wiersze
     * `{label, value}`; puste, nieskalarne i pominięte klucze odpadają.
     *
     * @return list<array{string, string, string}> [klucz, etykieta, wartość]
     */
    private function attributes(Product $p): array
    {
        $raw = $p->price_list_attributes;
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $row) {
            $label = null;
            if (is_array($row) && array_key_exists('label', $row)) {
                $label = trim((string) $row['label']);
                $row = $row['value'] ?? null;
            }
            if (! is_scalar($row)) {
                continue;
            }
            $value = trim((string) $row);
            $key = is_string($key) ? mb_strtolower(trim($key)) : '';
            if ($value === '' || in_array($key, self::SKIPPED_ATTRIBUTES, true)) {
                continue;
            }
            $label = $label !== null && $label !== ''
                ? $label
                : (self::ATTRIBUTE_LABELS[$key] ?? ($key !== '' ? mb_convert_case(str_replace('_', ' ', $key), MB_CASE_TITLE, 'UTF-8') : ''));
            if ($label === '') {
                continue;
            }
            $out[] = [$key, $label, mb_substr($value, 0, 190)];
        }

        return $out;
    }
}
