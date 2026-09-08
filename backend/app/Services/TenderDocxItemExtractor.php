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
 * Gdy nazwy i opisy są w osobnych tabelach (OPZ: NAZWA PRODUKTU + Opis wyrobu), scala je po indeksie/nazwie.
 */
final class TenderDocxItemExtractor
{
    public function __construct(
        private readonly TenderSpreadsheetItemExtractor $tableItems,
    ) {}

    /**
     * @return array{
     *     items: list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string, norms: ?string, description: ?string}>,
     *     column_map: array<string, int|null>,
     *     header_row: int,
     *     notes: string
     * }|null
     */
    public function extract(string $path, bool $useAiMapping = true): ?array
    {
        $tables = $this->readTables($path);
        $descs = $this->descriptionsFromTables($tables);

        $best = null;
        $bestCount = 0;
        $bestIsName = false;

        foreach ($tables as $rows) {
            $pack = $this->tableItems->extractFromMatrix($rows, $useAiMapping);
            if ($pack === null || $pack['items'] === []) {
                continue;
            }
            $isName = $this->isNamePack($pack);
            $count = count($pack['items']);
            if ($best === null
                || ($isName && ! $bestIsName)
                || ($isName === $bestIsName && $count > $bestCount)) {
                $bestCount = $count;
                $bestIsName = $isName;
                $best = $pack;
                $best['notes'] = 'Tabela DOCX. '.$pack['notes'];
            }
        }

        if ($best !== null) {
            return $this->attachDescriptions($best, $descs);
        }

        if ($descs !== []) {
            return $this->packFromDescriptions($descs);
        }

        return null;
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
                    if (! $tc instanceof DOMElement) {
                        continue;
                    }
                    $cells[] = $this->cellText($xp, $tc);
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

    private function cellText(DOMXPath $xp, DOMElement $tc): string
    {
        $lines = [];
        foreach ($xp->query('./w:p', $tc) ?: [] as $p) {
            $texts = [];
            foreach ($xp->query('.//w:t', $p) ?: [] as $t) {
                $texts[] = $t->textContent;
            }
            $line = trim(preg_replace('/[^\S\n]+/u', ' ', implode('', $texts)) ?? '');
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            $texts = [];
            foreach ($xp->query('.//w:t', $tc) ?: [] as $t) {
                $texts[] = $t->textContent;
            }
            $lines[] = trim(preg_replace('/\s+/u', ' ', implode('', $texts)) ?? '');
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param  list<list<list<string>>>  $tables
     * @return list<array{sku: ?string, name: string, description: string}>
     */
    private function descriptionsFromTables(array $tables): array
    {
        $out = [];
        foreach ($tables as $rows) {
            if ($rows === [] || ! $this->isDescriptionHeader($rows[0])) {
                continue;
            }
            $descCol = $this->descriptionColumnIndex($rows[0]);
            for ($i = 1; $i < count($rows); $i++) {
                $text = trim((string) ($rows[$i][$descCol] ?? ''));
                if (mb_strlen($text) < 20) {
                    continue;
                }
                $out[] = [
                    'sku' => $this->skuFromDescription($text),
                    'name' => $this->nameFromDescription($text),
                    'description' => $text,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $header
     */
    private function isDescriptionHeader(array $header): bool
    {
        $blob = mb_strtolower(preg_replace('/\s+/u', ' ', implode(' ', $header)) ?? '');
        if ($blob === '') {
            return false;
        }
        if (str_contains($blob, 'opis wyrobu')
            || str_contains($blob, 'opis przedmiotu')
            || str_contains($blob, 'szczegółowy opis')
            || str_contains($blob, 'szczegolowy opis')) {
            return true;
        }

        return str_contains($blob, 'opis')
            && ! str_contains($blob, 'nazwa')
            && count($header) <= 3;
    }

    /**
     * @param  list<string>  $header
     */
    private function descriptionColumnIndex(array $header): int
    {
        foreach ($header as $i => $label) {
            if (str_contains(mb_strtolower($label), 'opis')) {
                return $i;
            }
        }

        return max(0, count($header) - 1);
    }

    /**
     * @param  array{
     *     items: list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string, norms: ?string, description: ?string}>,
     *     column_map: array<string, int|null>,
     *     header_row: int,
     *     notes: string
     * }  $pack
     */
    private function isNamePack(array $pack): bool
    {
        $items = $pack['items'];
        if ($items === []) {
            return false;
        }
        $withSku = 0;
        $nameLen = 0;
        foreach ($items as $item) {
            if (($item['sku'] ?? null) !== null && $item['sku'] !== '') {
                $withSku++;
            }
            $nameLen += mb_strlen((string) ($item['name'] ?? ''));
        }
        $avg = $nameLen / count($items);

        return $withSku >= max(1, (int) ceil(count($items) * 0.5)) || $avg <= 160;
    }

    /**
     * @param  array{
     *     items: list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string, norms: ?string, description: ?string}>,
     *     column_map: array<string, int|null>,
     *     header_row: int,
     *     notes: string
     * }  $pack
     * @param  list<array{sku: ?string, name: string, description: string}>  $descs
     * @return array{
     *     items: list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string, norms: ?string, description: ?string}>,
     *     column_map: array<string, int|null>,
     *     header_row: int,
     *     notes: string
     * }
     */
    private function attachDescriptions(array $pack, array $descs): array
    {
        if ($descs === []) {
            return $pack;
        }
        $attached = 0;
        foreach ($pack['items'] as $i => $item) {
            if (($item['description'] ?? null) !== null && trim((string) $item['description']) !== '') {
                continue;
            }
            $match = $this->findDescription($item, $descs);
            if ($match === null) {
                continue;
            }
            $body = $this->descriptionBody($match['description'], (string) $item['name'], $item['sku'] ?? null);
            if ($body === '') {
                continue;
            }
            $item['description'] = $body;
            $item['requirement'] = implode(' · ', array_values(array_filter([
                $item['name'] !== '' ? $item['name'] : null,
                $body,
                ($item['norms'] ?? null) !== null && $item['norms'] !== '' ? $item['norms'] : null,
            ])));
            $pack['items'][$i] = $item;
            $attached++;
        }
        if ($attached > 0) {
            $pack['notes'] = trim($pack['notes'].' Dołączono opisy z osobnej tabeli.');
        }

        return $pack;
    }

    /**
     * @param  list<array{sku: ?string, name: string, description: string}>  $descs
     * @return array{
     *     items: list<array{sku: ?string, name: string, requirement: string, quantity: int, offer_price: ?float, currency: ?string, norms: ?string, description: ?string}>,
     *     column_map: array<string, int|null>,
     *     header_row: int,
     *     notes: string
     * }
     */
    private function packFromDescriptions(array $descs): array
    {
        $items = [];
        foreach ($descs as $row) {
            $name = $row['name'] !== '' ? $row['name'] : mb_substr($row['description'], 0, 120);
            $body = $this->descriptionBody($row['description'], $name, $row['sku']);
            $items[] = [
                'sku' => $row['sku'],
                'name' => $name,
                'requirement' => implode(' · ', array_values(array_filter([
                    $name,
                    $body !== '' ? $body : null,
                ]))),
                'quantity' => 1,
                'offer_price' => null,
                'currency' => null,
                'norms' => null,
                'description' => $body !== '' ? $body : null,
            ];
        }

        return [
            'items' => array_slice($items, 0, 800),
            'column_map' => [
                'sku' => null,
                'name' => null,
                'description' => 1,
                'norms' => null,
                'offer_price' => null,
                'quantity' => null,
                'project' => null,
                'client' => null,
                'currency' => null,
            ],
            'header_row' => 0,
            'notes' => 'Tabela DOCX. Pozycje z tabeli opisów.',
        ];
    }

    /**
     * @param  array{sku: ?string, name: string, requirement?: string}  $item
     * @param  list<array{sku: ?string, name: string, description: string}>  $descs
     * @return array{sku: ?string, name: string, description: string}|null
     */
    private function findDescription(array $item, array $descs): ?array
    {
        $sku = $this->normalizeSku((string) ($item['sku'] ?? ''));
        if ($sku !== '') {
            foreach ($descs as $desc) {
                if ($this->normalizeSku((string) ($desc['sku'] ?? '')) === $sku) {
                    return $desc;
                }
            }
        }
        $want = $this->normalizeMatch((string) ($item['name'] ?? ''));
        if ($want === '' || mb_strlen($want) < 8) {
            return null;
        }
        $starts = null;
        foreach ($descs as $desc) {
            $name = $this->normalizeMatch($desc['name']);
            if ($name === $want) {
                return $desc;
            }
            $hay = $this->normalizeMatch($desc['name'].' '.$desc['description']);
            if ($starts === null && str_starts_with($hay, $want)) {
                $starts = $desc;
            }
        }

        return $starts;
    }

    private function skuFromDescription(string $text): ?string
    {
        if (preg_match('/INDEKS:\s*([0-9A-Za-z][0-9A-Za-z.\-\/]{4,})/u', $text, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private function nameFromDescription(string $text): string
    {
        $first = trim(explode("\n", $text, 2)[0]);
        $first = preg_replace('/\s*\(\s*INDEKS:.*/iu', '', $first) ?? $first;
        $first = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $first) ?? $first;

        return trim(preg_replace('/\s+/u', ' ', $first) ?? $first);
    }

    private function descriptionBody(string $text, string $name, ?string $sku): string
    {
        $body = preg_replace('/\(\s*INDEKS:\s*[^)]+\)/iu', '', $text) ?? $text;
        $name = trim($name);
        if ($name !== '') {
            $quoted = preg_quote($name, '/');
            $body = preg_replace('/^'.$quoted.'\s*/iu', '', $body, 1) ?? $body;
        }
        $skuNorm = $this->normalizeSku((string) $sku);
        if ($skuNorm !== '') {
            $body = str_ireplace($skuNorm, '', $body);
        }
        $body = preg_replace('/^\s*(\([A-Z0-9\/,\s]{1,24}\)\s*)+/u', '', $body) ?? $body;
        $body = preg_replace('/^[A-ZĄĆĘŁŃÓŚŹŻ0-9][A-ZĄĆĘŁŃÓŚŹŻ0-9 \/,.\-]{8,80}\n/u', '', $body) ?? $body;
        $body = preg_replace("/[ \t]+\n/u", "\n", $body) ?? $body;
        $body = preg_replace("/\n{3,}/u", "\n\n", $body) ?? $body;

        return trim($body);
    }

    private function normalizeSku(string $sku): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $sku) ?? $sku);
    }

    private function normalizeMatch(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
