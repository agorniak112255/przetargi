<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use RuntimeException;
use ZipArchive;

/**
 * Tekst zapytania klienta z pliku (Excel, PDF, Word) — do tego samego pola, do którego handlowiec wkleja mail.
 *
 * Czytnik dokumentów przetargu skleja kolumny tabel (tabulator → spacja, komórki DOCX bez odstępu), a w zapytaniu
 * „1 Rękawice MAPA 332 9 4 para” nie da się już odróżnić numeru pozycji, rozmiaru i ilości. Tu wiersz tabeli zostaje
 * jednym wierszem tekstu, a komórki dzieli „ | ”. Treść idzie bez zmian — nic nie jest poprawiane ani dopisywane.
 */
final class InquiryFileText
{
    public const EXTENSIONS = ['pdf', 'xlsx', 'xls', 'csv', 'docx', 'doc'];

    public const FORMATS_LABEL = 'Excel (xlsx, xls, csv), PDF albo Word (docx, doc)';

    private const CELL_SEPARATOR = ' | ';

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function __construct(
        private readonly PriceListPdfTextExtractor $pdf,
        private readonly TenderDocumentTextExtractor $documents,
        private readonly SpreadsheetCellReader $cells,
    ) {}

    public function extract(string $path, string $extension): string
    {
        $ext = mb_strtolower($extension);
        $text = match ($ext) {
            // -layout trzyma wiersz tabeli w jednej linii; -raw (extract) rozbija go na komórki
            'pdf' => $this->pdf->extractLayout($path) ?? $this->pdf->extract($path),
            'xlsx', 'xls', 'csv' => $this->spreadsheet($path, $ext),
            'docx' => $this->docx($path),
            'doc' => $this->documents->extract($path, 'doc'),
            default => throw new RuntimeException('Nieobsługiwany format pliku. Wgraj '.self::FORMATS_LABEL.'.'),
        };

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+$/mu', '', $text) ?? $text;
        $text = trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);
        if (mb_strlen($text) < 20) {
            throw new RuntimeException('Plik nie zawiera tekstu do analizy (pusty arkusz albo skan bez warstwy tekstowej).');
        }

        return $text;
    }

    private function spreadsheet(string $path, string $ext): string
    {
        if ($ext === 'csv') {
            // CSV z polskiego Excela bywa w Windows-1250 — bez zgadywania kodowania znikają polskie litery
            $reader = new Csv;
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
            $reader->setFallbackEncoding('CP1250');
            $book = $reader->load($path);
        } else {
            $book = IOFactory::load($path);
        }

        $sheets = $book->getAllSheets();
        $parts = [];
        foreach ($sheets as $sheet) {
            $lines = [];
            foreach ($this->cells->toRows($sheet) as $row) {
                $cells = array_values(array_filter(
                    array_map(static fn (string $cell): string => trim(preg_replace('/\s+/u', ' ', $cell) ?? $cell), $row),
                    static fn (string $cell): bool => $cell !== '',
                ));
                if ($cells !== []) {
                    $lines[] = implode(self::CELL_SEPARATOR, $cells);
                }
            }
            if ($lines === []) {
                continue;
            }
            if (count($sheets) > 1) {
                array_unshift($lines, 'Arkusz: '.trim((string) $sheet->getTitle()));
            }
            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Nie można otworzyć pliku DOCX.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xml) || $xml === '') {
            throw new RuntimeException('Plik DOCX nie ma treści dokumentu (word/document.xml).');
        }

        $dom = new DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Nie udało się odczytać treści pliku DOCX.');
        }
        $body = $dom->getElementsByTagNameNS(self::WORD_NS, 'body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }
        $lines = [];
        $this->docxBlocks($body, $lines);

        return implode("\n", $lines);
    }

    /**
     * Akapit = linia, wiersz tabeli = linia z komórkami po „ | ”. Inne kontenery (pola formularza, bloki
     * treści) przechodzimy w głąb, żeby nie zgubić akapitów, które w nich siedzą.
     *
     * @param  list<string>  $lines
     */
    private function docxBlocks(DOMNode $parent, array &$lines): void
    {
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement || $node->namespaceURI !== self::WORD_NS) {
                continue;
            }
            if ($node->localName === 'p') {
                $lines[] = $this->docxRunText($node);
            } elseif ($node->localName === 'tbl') {
                foreach ($node->childNodes as $row) {
                    if (! $row instanceof DOMElement || $row->localName !== 'tr') {
                        continue;
                    }
                    $cells = [];
                    foreach ($row->childNodes as $cell) {
                        if (! $cell instanceof DOMElement || $cell->localName !== 'tc') {
                            continue;
                        }
                        $cellLines = [];
                        $this->docxBlocks($cell, $cellLines);
                        $text = trim(implode(' ', array_filter(array_map('trim', $cellLines), static fn (string $l): bool => $l !== '')));
                        if ($text !== '') {
                            $cells[] = $text;
                        }
                    }
                    if ($cells !== []) {
                        $lines[] = implode(self::CELL_SEPARATOR, $cells);
                    }
                }
                $lines[] = '';
            } elseif ($node->localName !== 'sectPr') {
                $this->docxBlocks($node, $lines);
            }
        }
    }

    private function docxRunText(DOMElement $paragraph): string
    {
        $text = '';
        foreach ($paragraph->getElementsByTagNameNS(self::WORD_NS, '*') as $el) {
            $text .= match ($el->localName) {
                't' => $el->textContent,
                'tab' => ' ',
                'br', 'cr' => "\n",
                default => '',
            };
        }

        return trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
    }
}
