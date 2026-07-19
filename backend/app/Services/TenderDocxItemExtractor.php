<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Pozycje z tabel formularza ofertowego DOCX (przez document.xml — bez PHPWord).
 */
final class TenderDocxItemExtractor
{
    public function __construct(
        private readonly TenderSpreadsheetItemExtractor $tableItems,
    ) {}

    /**
     * @return array{
     *     items: list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string}>,
     *     column_map: array<string, int|null>,
     *     header_row: int,
     *     notes: string
     * }|null
     */
    public function extract(string $path, bool $useAiMapping = true): ?array
    {
        $tables = $this->readTables($path);
        $best = null;
        $bestCount = 0;

        foreach ($tables as $rows) {
            $pack = $this->tableItems->extractFromMatrix($rows, $useAiMapping);
            if ($pack === null) {
                continue;
            }
            $count = count($pack['items']);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $pack;
                $best['notes'] = 'Tabela DOCX. '.$pack['notes'];
            }
        }

        return $best;
    }

    /**
     * @return list<list<list<string>>>
     */
    public function readTables(string $path): array
    {
        $xml = $this->documentXml($path);
        $dom = new DOMDocument;
        if (@$dom->loadXML($xml) === false) {
            throw new RuntimeException('Nieprawidłowy document.xml w DOCX.');
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $out = [];
        foreach ($xp->query('//w:tbl') ?: [] as $tbl) {
            if (! $tbl instanceof DOMElement) {
                continue;
            }
            $rows = [];
            foreach ($xp->query('./w:tr', $tbl) ?: [] as $tr) {
                if (! $tr instanceof DOMElement) {
                    continue;
                }
                $cells = [];
                foreach ($xp->query('./w:tc', $tr) ?: [] as $tc) {
                    $texts = [];
                    foreach ($xp->query('.//w:t', $tc) ?: [] as $t) {
                        $texts[] = $t->textContent;
                    }
                    $cells[] = trim(preg_replace('/\s+/u', ' ', implode('', $texts)) ?? '');
                }
                $rows[] = $cells;
            }
            if (count($rows) >= 2) {
                $out[] = $rows;
            }
        }

        return $out;
    }

    public function documentXml(string $path): string
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

        return $xml;
    }
}
