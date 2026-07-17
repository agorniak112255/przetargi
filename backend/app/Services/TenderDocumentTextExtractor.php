<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIO;
use PhpOffice\PhpWord\IOFactory as WordIO;
use RuntimeException;
use ZipArchive;

final class TenderDocumentTextExtractor
{
    public function __construct(
        private readonly PriceListPdfTextExtractor $pdfExtractor,
    ) {}

    public function extract(string $path, string $extension): string
    {
        $ext = mb_strtolower($extension);
        $text = match ($ext) {
            'pdf' => $this->pdfExtractor->extract($path, 500_000),
            'xlsx', 'xls', 'csv' => $this->extractSpreadsheet($path),
            'docx' => $this->extractDocx($path),
            'doc' => $this->extractDoc($path),
            default => throw new RuntimeException("Nieobsługiwany format: .{$ext}"),
        };

        $text = trim($this->normalize($text));
        if (mb_strlen($text) < 20) {
            throw new RuntimeException('Plik nie zawiera wystarczającej ilości tekstu do analizy.');
        }

        return $text;
    }

    private function extractSpreadsheet(string $path): string
    {
        $book = SpreadsheetIO::load($path);
        $parts = [];
        foreach ($book->getAllSheets() as $sheet) {
            $title = trim((string) $sheet->getTitle());
            if ($title !== '') {
                $parts[] = "=== {$title} ===";
            }
            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $cells = array_map(
                    static fn ($v) => trim((string) ($v ?? '')),
                    is_array($row) ? $row : []
                );
                $line = trim(implode("\t", array_filter($cells, static fn ($c) => $c !== '')));
                if ($line !== '') {
                    $parts[] = $line;
                }
            }
        }

        return implode("\n", $parts);
    }

    private function extractDocx(string $path): string
    {
        try {
            return $this->extractViaPhpWord($path);
        } catch (\Throwable) {
            return $this->extractDocxZip($path);
        }
    }

    private function extractDoc(string $path): string
    {
        try {
            return $this->extractViaPhpWord($path);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Nie udało się odczytać pliku .doc. Zapisz jako .docx i spróbuj ponownie. '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    private function extractViaPhpWord(string $path): string
    {
        $phpWord = WordIO::load($path);
        $parts = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $chunk = $this->elementText($element);
                if ($chunk !== '') {
                    $parts[] = $chunk;
                }
            }
        }

        return implode("\n", $parts);
    }

    private function extractDocxZip(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Nie można otworzyć pliku DOCX.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xml) || $xml === '') {
            throw new RuntimeException('Brak word/document.xml w DOCX.');
        }
        $xml = str_replace(['</w:p>', '</w:tr>', '<w:br/>', '<w:tab/>'], ["\n", "\n", "\n", "\t"], $xml);
        $text = strip_tags($xml);

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function elementText(mixed $element): string
    {
        if (method_exists($element, 'getText')) {
            $t = $element->getText();
            if (is_string($t)) {
                return trim($t);
            }
            if (is_array($t)) {
                return trim(implode(' ', array_map('strval', $t)));
            }
        }
        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $chunk = $this->elementText($child);
                if ($chunk !== '') {
                    $parts[] = $chunk;
                }
            }

            return trim(implode(' ', $parts));
        }

        return '';
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return $text;
    }
}
