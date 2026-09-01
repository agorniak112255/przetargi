<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class PriceListStructureSampler
{
    private const SKIP_SHEET_HINTS = [
        'okładka', 'okladka', 'disclaimer', 'ważne', 'wazne', 'kontakt',
        'spis', 'cover', 'info', 'instructions', 'readme',
        'languages', 'gtcs', 'warunki', 'kalkulator', 'overview',
        'tarifs', 'wygaszane', 'trad. ', 'configurable',
    ];

    /**
     * @return array{
     *     sheets: list<array{
     *         name: string,
     *         rows_total: int,
     *         likely_product_sheet: bool,
     *         sample_rows: list<array{excel_row: int, cells: list<string>}>
     *     }>
     * }
     */
    public function sample(string $path, int $maxSheets = 8, int $rowsPerSheet = 35, int $cols = 28): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheets = [];
        $count = 0;

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if ($count >= $maxSheets) {
                break;
            }
            $name = $sheet->getTitle();
            $rows = $this->readSampleRows($sheet, $rowsPerSheet, $cols);
            $sheets[] = [
                'name' => $name,
                'rows_total' => $sheet->getHighestDataRow(),
                'likely_product_sheet' => $this->looksLikeProductSheet($name, $rows),
                'sample_rows' => $rows,
            ];
            $count++;
        }

        return ['sheets' => $sheets];
    }

    /**
     * @return list<array{excel_row: int, cells: list<string>}>
     */
    private function readSampleRows(Worksheet $sheet, int $limit, int $cols): array
    {
        $highest = min($sheet->getHighestDataRow(), max($limit * 3, 80));
        $out = [];
        for ($r = 1; $r <= $highest && count($out) < $limit; $r++) {
            $cells = [];
            $empty = true;
            for ($c = 1; $c <= $cols; $c++) {
                $coord = Coordinate::stringFromColumnIndex($c).$r;
                $val = trim((string) $sheet->getCell($coord)->getFormattedValue());
                if ($val !== '') {
                    $empty = false;
                }
                $cells[] = mb_substr($val, 0, 120);
            }
            if (! $empty) {
                $out[] = [
                    'excel_row' => $r,
                    'cells' => $cells,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{excel_row: int, cells: list<string>}>  $rows
     */
    private function looksLikeProductSheet(string $name, array $rows): bool
    {
        $nl = mb_strtolower($name);
        foreach (self::SKIP_SHEET_HINTS as $hint) {
            if (str_contains($nl, $hint)) {
                return false;
            }
        }

        $blob = mb_strtolower(implode(' ', array_map(
            static fn (array $r) => implode(' ', $r['cells']),
            array_slice($rows, 0, 12)
        )));

        $hits = 0;
        foreach (['sku', 'kod', 'cena', 'price', 'nazwa', 'description', 'product', 'sap', 'hurtow', 'rabat', 'ean'] as $token) {
            if (str_contains($blob, $token)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }
}
