<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Mapowanie kolumn XLSX bez AI — gdy AI nie znajdzie arkusza lub plik ma nietypowy układ
 * (nagłówek rabatowy u góry, brak kolumny SKU, nagłówki PL/CZ).
 */
final class SpreadsheetMappingHeuristic
{
    /**
     * @return array{
     *     manufacturer_detected: ?string,
     *     currency: ?string,
     *     notes: string,
     *     sheets: list<array<string, mixed>>
     * }|null
     */
    public function detect(string $path): ?array
    {
        $spreadsheet = IOFactory::load($path);
        $sheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $name = $sheet->getTitle();
            if ($this->skipSheetName($name)) {
                continue;
            }

            $maxCol = min(24, Coordinate::columnIndexFromString($sheet->getHighestDataColumn() ?: 'A'));
            $maxRow = min(80, max(1, (int) $sheet->getHighestDataRow()));
            $grid = [];
            for ($r = 1; $r <= $maxRow; $r++) {
                $row = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $coord = Coordinate::stringFromColumnIndex($c).$r;
                    $row[] = trim((string) $sheet->getCell($coord)->getFormattedValue());
                }
                $grid[$r] = $row;
            }

            $mapped = $this->mapSheet($name, $grid);
            if ($mapped !== null) {
                $sheets[] = $mapped;
            }
        }

        if ($sheets === []) {
            return null;
        }

        $currency = null;
        foreach ($sheets as $i => $s) {
            if (isset($s['_currency']) && is_string($s['_currency'])) {
                $currency = $s['_currency'];
            }
            unset($sheets[$i]['_currency']);
        }

        return [
            'manufacturer_detected' => null,
            'currency' => $currency,
            'notes' => 'Mapowanie heurystyczne (nagłówki PL/CZ, także bez kolumny kodu).',
            'sheets' => $sheets,
        ];
    }

    /**
     * @param  array<int, list<string>>  $grid  excelRow => cells 0-based
     * @return array<string, mixed>|null
     */
    private function mapSheet(string $sheetName, array $grid): ?array
    {
        $best = null;
        $bestScore = 0;

        foreach ($grid as $excelRow => $cells) {
            $labels = array_map(fn (string $v) => mb_strtolower($v), $cells);
            $blob = implode(' | ', $labels);
            if ($blob === '' || mb_strlen($blob) < 4) {
                continue;
            }

            $cols = [
                'sku' => $this->findCol($labels, [
                    'kod produktu', 'kod towaru', 'product code', 'sku', 'symbol', 'indeks',
                    'sap', 'ref', 'article', 'artikl', 'katalog',
                ]),
                'name' => $this->findCol($labels, [
                    'nazwa', 'name', 'opis', 'description', 'produkt', 'asortyment', 'model',
                ]),
                'catalog_price' => $this->findCol($labels, [
                    'cena cennik', 'cena katalog', 'cena sugerowana', 'cena netto', 'list price',
                    'price (eur)', 'price eur', 'cena - ', 'cena_',
                    'cena hurtowa', 'cena', 'price', 'cennik',
                ]),
                'purchase' => $this->findCol($labels, [
                    'cena po rabacie', 'po rabacie', 'purchase', 'zakup', 'netto po',
                ]) ?? $this->findCol($labels, ['cena hurtowa']),
                'discount' => $this->findCol($labels, [
                    'upust', 'rabat %', 'rabat', 'discount', 'marża', 'marza',
                ]),
                'pack_qty' => $this->findCol($labels, [
                    'ilość w kartonie', 'ilosc w kartonie', 'ilość w opak', 'ilosc w opak',
                    'carton', 'pack qty', 'množství', 'mnozstvi',
                ]),
                'packaging' => $this->findCol($labels, [
                    'opakowanie', 'packaging', 'jednostka',
                ]),
                'ean' => $this->findCol($labels, ['ean', 'barcode', 'kod kresk']),
                'category' => $this->findCol($labels, [
                    'kategoria', 'category', 'grupa asortymentowa', 'klasa asortymentowa',
                    'klasa', 'grupa', 'asortyment', 'skupina',
                ]),
                'currency' => $this->findCol($labels, ['waluta', 'currency']),
            ];

            // wariant PANTHER: wiersz "… | Cena sugerowana | Cena hurtowa | Cena po rabacie"
            if ($cols['name'] === null && $this->looksLikePriceHeaderRow($labels)) {
                $cols['name'] = 0;
                if ($cols['catalog_price'] === null) {
                    $cols['catalog_price'] = $this->firstPriceCol($labels) ?? 1;
                }
                if ($cols['purchase'] === null && isset($labels[3]) && str_contains($labels[3], 'rabat')) {
                    $cols['purchase'] = 3;
                }
            }

            // sam nagłówek sekcji bez cen — pomiń
            $score = 0;
            if ($cols['name'] !== null) {
                $score += 2;
            }
            if ($cols['catalog_price'] !== null) {
                $score += 3;
            }
            if ($cols['sku'] !== null) {
                $score += 2;
            }
            if ($cols['purchase'] !== null) {
                $score += 1;
            }
            if ($cols['ean'] !== null) {
                $score += 1;
            }

            // potwierdź danymi poniżej
            $dataHits = $this->countDataHits($grid, $excelRow, $cols);
            $score += min(5, $dataHits);

            if ($score < 5 || $cols['catalog_price'] === null) {
                continue;
            }
            // nazwa: jeśli brak kolumny, a jest SKU — użyj następnej; jeśli brak SKU — kolumna 0 z danymi
            if ($cols['name'] === null) {
                $cols['name'] = $cols['sku'] !== null ? $cols['sku'] + 1 : 0;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $currency = null;
                if (str_contains($blob, '€') || str_contains($blob, 'eur')) {
                    $currency = 'EUR';
                } elseif (str_contains($blob, 'pln') || str_contains($blob, 'zł')) {
                    $currency = 'PLN';
                }
                $best = [
                    'sheet' => $sheetName,
                    'include' => true,
                    'header_excel_row' => $excelRow,
                    'columns' => [
                        'sku' => $cols['sku'],
                        'name' => $cols['name'],
                        'catalog_price' => $cols['catalog_price'],
                        'discount' => $cols['discount'],
                        'purchase' => $cols['purchase'],
                        'pack_qty' => $cols['pack_qty'],
                        'packaging' => $cols['packaging'],
                        'currency' => $cols['currency'],
                        'ean' => $cols['ean'],
                        'category' => $cols['category'],
                    ],
                    'repeating_headers' => false,
                    'confidence' => min(0.95, 0.45 + $score * 0.05),
                    '_currency' => $currency,
                ];
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $labels
     * @param  list<string>  $needles
     */
    private function findCol(array $labels, array $needles): ?int
    {
        foreach ($labels as $i => $label) {
            if ($label === '') {
                continue;
            }
            $compact = preg_replace('/\s+/', ' ', $label) ?? $label;
            foreach ($needles as $needle) {
                if ($compact === $needle || str_contains($compact, $needle)) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $labels
     */
    private function looksLikePriceHeaderRow(array $labels): bool
    {
        $blob = implode(' ', $labels);

        return str_contains($blob, 'cena')
            && (str_contains($blob, 'hurt') || str_contains($blob, 'rabat') || str_contains($blob, 'suger'));
    }

    /**
     * @param  list<string>  $labels
     */
    private function firstPriceCol(array $labels): ?int
    {
        foreach ($labels as $i => $label) {
            if ($i === 0) {
                continue;
            }
            if (str_contains($label, 'cena') || str_contains($label, 'price')) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<int, list<string>>  $grid
     * @param  array<string, int|null>  $cols
     */
    private function countDataHits(array $grid, int $headerRow, array $cols): int
    {
        $hits = 0;
        $priceIdx = $cols['catalog_price'];
        $nameIdx = $cols['name'] ?? 0;
        if ($priceIdx === null) {
            return 0;
        }
        foreach ($grid as $r => $cells) {
            if ($r <= $headerRow) {
                continue;
            }
            $name = trim((string) ($cells[$nameIdx] ?? ''));
            $price = trim((string) ($cells[$priceIdx] ?? ''));
            if ($name === '' || mb_strlen($name) < 3) {
                continue;
            }
            // pomiń same nagłówki sekcji bez ceny
            if ($price === '' || ! preg_match('/\d/', $price)) {
                continue;
            }
            $hits++;
            if ($hits >= 5) {
                break;
            }
        }

        return $hits;
    }

    private function skipSheetName(string $name): bool
    {
        $nl = mb_strtolower($name);
        foreach (['okładka', 'okladka', 'disclaimer', 'kontakt', 'spis', 'cover', 'readme'] as $hint) {
            if (str_contains($nl, $hint)) {
                return true;
            }
        }

        return false;
    }
}
