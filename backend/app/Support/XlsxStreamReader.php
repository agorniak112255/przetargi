<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Wiersze pierwszego arkusza pliku XLSX czytane strumieniowo (ZipArchive + XMLReader), bez PhpSpreadsheet.
 *
 * Powód: cennik konta BIG Arbeitsschutz (5,9 tys. wierszy × 48 kolumn, 11 MB XML) w PhpSpreadsheet zajmował 208 MB
 * i 26 s, a przebieg synchronizacji ma limit 512 MB (B2bAccountSyncRunner) i trzyma też resztę katalogu.
 *
 * Wartości komórek zwracamy jako napisy dosłownie z pliku: liczby tak, jak je zapisał Excel („0.12”, „1102”) — bez
 * rzutowania na float, które zależy od ustawień regionalnych serwera (pl_PL). Komórki puste i brakujące = ''. Formuły:
 * zapisana wartość wyniku. Daty zostają liczbą seryjną (formatowania stylu nie czytamy).
 */
final class XlsxStreamReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return \Generator<int, list<string>> numer wiersza arkusza (od 1) → komórki od kolumny A
     */
    public static function rows(string $path): \Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('plik nie jest archiwum XLSX');
        }
        try {
            $sheet = self::firstSheetPath($zip);
            $strings = self::sharedStrings($zip);
            $xml = $zip->getFromName($sheet);
            if ($xml === false) {
                throw new RuntimeException('w pliku XLSX brak arkusza '.$sheet);
            }
        } finally {
            $zip->close();
        }

        $reader = new XMLReader;
        if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('nieczytelny arkusz XLSX');
        }
        unset($xml);

        try {
            $rowNumber = 0;
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }
                $attr = $reader->getAttribute('r');
                $rowNumber = $attr !== null && ctype_digit($attr) ? (int) $attr : $rowNumber + 1;
                $cells = [];
                if (! $reader->isEmptyElement) {
                    $depth = $reader->depth;
                    $column = -1;
                    while ($reader->read() && ! ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth)) {
                        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                            continue;
                        }
                        $ref = (string) $reader->getAttribute('r');
                        $column = $ref !== '' ? self::columnIndex($ref) : $column + 1;
                        $cells[$column] = self::cellValue($reader, (string) $reader->getAttribute('t'), $strings);
                    }
                }
                if ($cells === []) {
                    yield $rowNumber => [];

                    continue;
                }
                $row = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $index => $value) {
                    $row[$index] = $value;
                }
                yield $rowNumber => $row;
            }
        } finally {
            $reader->close();
        }
    }

    /** „AV12” → 47 (kolumna od zera). */
    public static function columnIndex(string $ref): int
    {
        if (preg_match('/^([A-Z]{1,3})\d*$/', strtoupper($ref), $m) !== 1) {
            throw new RuntimeException('nieznany adres komórki XLSX: '.$ref);
        }
        $index = 0;
        foreach (str_split($m[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * Wartość komórki, na której stoi czytnik (element <c>): po wyjściu czytnik stoi na jej ostatnim węźle.
     *
     * @param  list<string>  $strings
     */
    private static function cellValue(XMLReader $reader, string $type, array $strings): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }
        $depth = $reader->depth;
        $value = '';
        $inline = '';
        $phonetic = false;
        while ($reader->read() && ! ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth)) {
            if ($reader->localName === 'rPh') {
                $phonetic = $reader->nodeType === XMLReader::ELEMENT && ! $reader->isEmptyElement;

                continue;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT || $phonetic) {
                continue;
            }
            if ($reader->localName === 'v') {
                $value = $reader->readString();
            } elseif ($reader->localName === 't') {
                // tekst wpisany w komórkę (t="inlineStr"), także z formatowaniem w kawałkach <r><t>
                $inline .= $reader->readString();
            }
        }

        return match ($type) {
            's' => $strings[(int) $value] ?? '',
            'inlineStr' => self::unescape($inline),
            'str' => self::unescape($value),
            'b' => $value === '1' ? 'TRUE' : ($value === '0' ? 'FALSE' : $value),
            default => $value,
        };
    }

    /**
     * Znaki zapisane przez Excel jako „_xHHHH_” (np. „_x000D_” = powrót karetki w cenniku BIG, kolumna Material);
     * „_x005F_” to sam podkreślnik przed takim zapisem (jak w PhpSpreadsheet).
     */
    public static function unescape(string $text): string
    {
        if (! str_contains($text, '_x')) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/_x([0-9A-Fa-f]{4})_/',
            static fn (array $m): string => mb_chr((int) hexdec($m[1]), 'UTF-8') ?: '',
            $text,
        );
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $reader = new XMLReader;
        if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('nieczytelne teksty XLSX (sharedStrings)');
        }
        $strings = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                    continue;
                }
                $text = '';
                if (! $reader->isEmptyElement) {
                    $depth = $reader->depth;
                    $phonetic = false;
                    while ($reader->read() && ! ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth)) {
                        // <rPh> to fonetyczny zapis tekstu japońskiego — nie część wartości (bez next(): ten pomijałby
                        // węzeł za </rPh>, a przy rPh na końcu <si> — samo </si> i sklejał dwa teksty)
                        if ($reader->localName === 'rPh') {
                            $phonetic = $reader->nodeType === XMLReader::ELEMENT && ! $reader->isEmptyElement;

                            continue;
                        }
                        if (! $phonetic && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                            $text .= $reader->readString();
                        }
                    }
                }
                $strings[] = self::unescape($text);
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    /** Ścieżka pierwszego arkusza z workbook.xml i jego relacji; bez nich — xl/worksheets/sheet1.xml. */
    private static function firstSheetPath(ZipArchive $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return $fallback;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $book = simplexml_load_string($workbook, options: LIBXML_NONET);
            $relations = simplexml_load_string($rels, options: LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($book === false || $relations === false) {
            return $fallback;
        }
        $sheet = $book->children(self::MAIN_NS)->sheets->sheet[0] ?? null;
        if ($sheet === null) {
            return $fallback;
        }
        $id = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        foreach ($relations->children() as $relation) {
            if ((string) $relation['Id'] !== $id) {
                continue;
            }
            $target = ltrim((string) $relation['Target'], '/');

            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return $fallback;
    }
}
