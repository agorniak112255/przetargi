<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Punktowane mapowanie kolumn cennika — pierwsze trafienie w „cena”/„kod”
 * myliło m.in. 3M (data zamiast ceny, EAN zamiast SKU, wskaźnik zakupów).
 */
final class SpreadsheetColumnMapper
{
    /**
     * @param  list<string>  $labels  już dowolna wielkość liter
     * @return array<string, int|null>
     */
    public function mapLabels(array $labels): array
    {
        $norm = array_map(fn (string $v) => $this->norm($v), $labels);

        $cols = [
            'sku' => $this->best($norm, $this->skuScores(...)),
            'model_key' => $this->best($norm, $this->modelKeyScores(...)),
            'name' => $this->best($norm, $this->nameScores(...)),
            'catalog_price' => $this->best($norm, $this->catalogScores(...)),
            'purchase' => $this->best($norm, $this->purchaseScores(...)),
            'discount' => $this->best($norm, $this->discountScores(...)),
            'ean' => $this->best($norm, $this->eanScores(...)),
            'category' => $this->best($norm, $this->categoryScores(...)),
            'currency' => $this->best($norm, $this->currencyScores(...)),
            'pack_qty' => $this->best($norm, $this->packQtyScores(...)),
            'packaging' => $this->best($norm, $this->packagingScores(...)),
        ];

        if ($cols['model_key'] !== null && $cols['model_key'] === $cols['sku']) {
            $cols['model_key'] = null;
        }
        if ($cols['purchase'] !== null && $cols['purchase'] === $cols['catalog_price']) {
            $cols['purchase'] = null;
        }
        if ($cols['discount'] !== null && in_array($cols['discount'], [$cols['catalog_price'], $cols['purchase']], true)) {
            $cols['discount'] = null;
        }
        if ($cols['ean'] !== null && $cols['ean'] === $cols['sku']) {
            $cols['ean'] = $this->best($norm, $this->eanScores(...), $cols['sku']);
        }

        return $cols;
    }

    public function classifySheet(string $name, string $blob = ''): string
    {
        $n = $this->norm($name);
        $b = $this->norm($blob);
        if (
            str_contains($n, 'special pricing')
            || str_contains($n, 'eua price')
            || str_contains($n, 'eua prices')
            || (str_contains($n, 'special') && str_contains($n, 'pric'))
        ) {
            return 'special';
        }
        if (
            (str_contains($b, 'cena kontraktowa') && str_contains($b, 'nazwa klienta'))
            || (str_contains($b, 'eua price') && str_contains($b, 'end-user'))
        ) {
            return 'special';
        }
        foreach ([
            'okładka', 'okladka', 'disclaimer', 'kontakt', 'spis', 'cover', 'readme',
            'languages', 'gtcs', 'warunki handlowe', 'kalkulator', 'logo',
            'tabulka velik', 'overview', 'trad. ', 'trad articles', 'trad gammes',
            'tabelle1', 'folio2', 'foglio2', 'tarifs', 'conditions commerciales',
            'wygaszane',
        ] as $hint) {
            if (str_contains($n, $hint)) {
                return 'skip';
            }
        }
        if (preg_match('/\btransport\b/u', $n) === 1 && ! str_contains($n, 'cena') && ! str_contains($n, 'price')) {
            return 'skip';
        }

        return 'catalog';
    }

    /**
     * @param  array{sheets?: list<array<string, mixed>>, currency?: ?string}  $mapping
     * @return array{sheets: list<array<string, mixed>>, currency?: ?string}
     */
    public function refineMapping(string $path, array $mapping): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheets = [];
        foreach ($mapping['sheets'] ?? [] as $sheetMap) {
            if (! is_array($sheetMap)) {
                continue;
            }
            $name = (string) ($sheetMap['sheet'] ?? '');
            $sheet = $spreadsheet->getSheetByName($name);
            if ($sheet === null) {
                $sheets[] = $sheetMap;

                continue;
            }
            $headerExcel = max(1, (int) ($sheetMap['header_excel_row'] ?? 1));
            $maxC = min(28, Coordinate::columnIndexFromString($sheet->getHighestDataColumn() ?: 'A'));
            $labels = [];
            for ($c = 1; $c <= $maxC; $c++) {
                $coord = Coordinate::stringFromColumnIndex($c).$headerExcel;
                $labels[] = trim((string) $sheet->getCell($coord)->getFormattedValue());
            }
            $kind = $this->classifySheet($name, implode(' ', $labels));
            $scored = $this->mapLabels($labels);
            $cols = is_array($sheetMap['columns'] ?? null) ? $sheetMap['columns'] : [];

            if ($kind === 'skip') {
                $sheetMap['include'] = false;
                $sheetMap['role'] = 'skip';
                $sheets[] = $sheetMap;

                continue;
            }
            if ($kind === 'special') {
                $sheetMap['include'] = false;
                $sheetMap['role'] = 'special';
                $sheetMap['columns'] = array_merge($cols, $this->mapSpecialLabels($labels));
                $sheets[] = $sheetMap;

                continue;
            }

            foreach (['catalog_price', 'purchase', 'discount', 'sku', 'name', 'ean', 'currency', 'pack_qty', 'packaging', 'category', 'model_key'] as $field) {
                if ($scored[$field] !== null) {
                    $cols[$field] = $scored[$field];
                }
            }
            $sheetMap['columns'] = $cols;
            $sheetMap['role'] = 'catalog';
            $sheetMap['include'] = (bool) ($sheetMap['include'] ?? false)
                && ($cols['catalog_price'] ?? null) !== null
                && ($cols['name'] ?? null) !== null;
            $sheets[] = $sheetMap;
        }
        $spreadsheet->disconnectWorksheets();
        $mapping['sheets'] = $sheets;

        return $mapping;
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, int|null>
     */
    public function mapSpecialLabels(array $labels): array
    {
        $norm = array_map(fn (string $v) => $this->norm($v), $labels);

        return [
            'sku' => $this->best($norm, function (string $l): int {
                if (str_contains($l, 'klienta') || str_contains($l, 'customer')) {
                    return 0;
                }
                if (str_contains($l, 'magazynowy (stary)') || str_contains($l, 'stary')) {
                    return 70;
                }
                if (str_contains($l, 'magazynowy') || str_contains($l, 'product reference')) {
                    return 100;
                }
                if (str_contains($l, 'article') || str_contains($l, 'sku')) {
                    return 80;
                }

                return 0;
            }),
            'sku_alt' => $this->best($norm, function (string $l): int {
                if (str_contains($l, 'stary') || str_contains($l, 'old')) {
                    return 90;
                }
                if (str_contains($l, 'style code')) {
                    return 70;
                }

                return 0;
            }),
            'ean' => $this->best($norm, $this->eanScores(...)),
            'name' => $this->best($norm, $this->nameScores(...)),
            'purchase' => $this->best($norm, function (string $l): int {
                if (str_contains($l, 'kontraktowa') || str_contains($l, 'eua price')) {
                    return 110;
                }
                if (str_contains($l, 'special price') || str_contains($l, 'cena specjal')) {
                    return 90;
                }

                return 0;
            }),
            'currency' => $this->best($norm, $this->currencyScores(...)),
            'client_name' => $this->best($norm, function (string $l): int {
                if (str_contains($l, 'end-user name') || str_contains($l, 'end user name')) {
                    return 110;
                }
                if (str_contains($l, 'nazwa klienta') || str_contains($l, 'client name')) {
                    return 100;
                }

                return 0;
            }),
            'valid_from' => $this->best($norm, function (string $l): int {
                if (str_contains($l, 'data rozpoczęcia') || str_contains($l, 'valid from')) {
                    return 90;
                }

                return 0;
            }),
            'contract_ref' => $this->best($norm, function (string $l): int {
                if (str_contains($l, 'numer kontraktu') || str_contains($l, 'eua reference')) {
                    return 100;
                }

                return 0;
            }),
        ];
    }

    /**
     * @param  list<string>  $norm
     * @param  callable(string): int  $scoreFn
     */
    private function best(array $norm, callable $scoreFn, ?int $except = null): ?int
    {
        $bestIdx = null;
        $best = 0;
        foreach ($norm as $i => $label) {
            if ($except !== null && $i === $except) {
                continue;
            }
            if ($label === '') {
                continue;
            }
            $score = $scoreFn($label);
            if ($score > $best) {
                $best = $score;
                $bestIdx = $i;
            }
        }

        return $best >= 40 ? $bestIdx : null;
    }

    private function catalogScores(string $l): int
    {
        if ($this->isDateLikePriceLabel($l) || $this->isChangeLabel($l) || $this->isTransportLabel($l)) {
            return 0;
        }
        if (str_contains($l, 'po upust') || str_contains($l, 'po rabat') || str_contains($l, 'minus discount')) {
            return 0;
        }
        if (str_contains($l, 'dealer') || str_contains($l, 'brutto') || str_contains($l, 'surcharge')) {
            return 0;
        }
        if (str_contains($l, 'box price') || str_contains($l, 'pack price') || str_contains($l, 'cena za')) {
            return 0;
        }
        if (str_contains($l, 'ncd') && str_contains($l, 'vat')) {
            return 0;
        }
        if (preg_match('/^\d{4}\s+price list$/', $l) === 1 || $l === 'price list') {
            return 0;
        }
        if (str_contains($l, 'sales unit of measure') || str_contains($l, 'purchasing unit')) {
            return 0;
        }
        if (str_contains($l, 'sugerowana minimalna') || str_contains($l, 'suggested')) {
            return 0;
        }

        $score = 0;
        if (str_contains($l, 'nowa cena cennik')) {
            return 130;
        }
        if (str_contains($l, 'cena katalog')) {
            $score = 120;
        } elseif (str_contains($l, 'aktualna cena cennik') || str_contains($l, 'cena cennik')) {
            $score = 85;
        } elseif (str_contains($l, 'price list price')) {
            $score = 100;
        } elseif (str_contains($l, 'list price')) {
            $score = 90;
        } elseif (str_contains($l, 'msrp')) {
            $score = 100;
        } elseif (str_contains($l, 'price(€/pc') || str_contains($l, 'price (€/pc') || str_contains($l, 'price(€')) {
            $score = 115;
        } elseif (str_contains($l, 'price per unit')) {
            $score = 105;
        } elseif (str_contains($l, 'base price')) {
            $score = 100;
        } elseif (str_contains($l, 'bez vat')) {
            $score = 100;
        } elseif (str_contains($l, 'emea price') || str_contains($l, 'retail price')) {
            $score = 90;
        } elseif (str_contains($l, 'cena podstawowa') || str_contains($l, 'cena fabryczna')) {
            $score = 95;
        } elseif (str_contains($l, 'exworks') || str_contains($l, 'ex works')) {
            $score = 90;
        } elseif (str_contains($l, 'net price ea') || str_contains($l, 'preis-netto') || str_contains($l, 'preis netto')) {
            $score = 90;
        } elseif (str_contains($l, 'price (eur)') || str_contains($l, 'price(eur)')) {
            $score = 88;
        } elseif (str_contains($l, 'price pln')) {
            $score = 85;
        } elseif (str_contains($l, 'price eur')) {
            $score = 75;
        } elseif (str_contains($l, 'cena netto') && ! str_contains($l, 'po ')) {
            $score = 70;
        } elseif ($l === 'cena' || $l === 'price' || $l === 'netto') {
            $score = 55;
        } elseif (str_contains($l, 'price list') || str_starts_with($l, 'price ')) {
            $score = 45;
        } elseif (str_contains($l, 'cena') && ! str_contains($l, 'jednostk')) {
            $score = 35;
        }

        return $score;
    }

    private function purchaseScores(string $l): int
    {
        if (str_contains($l, 'wskaźnik zakup') || str_contains($l, 'wskaznik zakup') || str_contains($l, 'zakupów w')) {
            return 0;
        }
        if ($this->isTransportLabel($l) || str_contains($l, 'brutto')) {
            return 0;
        }
        if (str_contains($l, 'nowa cena po upust')) {
            return 130;
        }
        if (str_contains($l, 'aktualna cena po upust') || str_contains($l, 'cena po upust')) {
            return 110;
        }
        if (str_contains($l, 'po upuście') || str_contains($l, 'po upust') || str_contains($l, 'po rabat')) {
            return 100;
        }
        if (str_contains($l, 'list price minus')) {
            return 110;
        }
        if ($l === 'dealer' || str_starts_with($l, 'dealer')) {
            return 100;
        }
        if (str_contains($l, 'final price without')) {
            return 90;
        }
        if (str_contains($l, 'cena netto po')) {
            return 100;
        }
        if (str_contains($l, 'discounted price') || str_contains($l, 'discounted')) {
            return 90;
        }
        if (str_contains($l, 'net prices')) {
            return 75;
        }
        if (preg_match('/\bprice 26\b/', $l) === 1) {
            return 80;
        }
        if (str_contains($l, 'cena zakupu') || $l === 'purchase' || $l === 'zakup') {
            return 60;
        }

        return 0;
    }

    private function discountScores(string $l): int
    {
        if (str_contains($l, 'cena po') || str_contains($l, 'po upust') || str_contains($l, 'po rabat')) {
            return 0;
        }
        if ($this->isChangeLabel($l)) {
            return 0;
        }
        if (str_contains($l, 'pl discount')) {
            return 110;
        }
        if (str_contains($l, 'rabat %') || str_contains($l, 'discount %') || str_contains($l, 'upust %')) {
            return 100;
        }
        if (str_contains($l, 'discount rate')) {
            return 90;
        }
        if (($l === 'rabat' || $l === 'upust' || $l === 'discount' || str_contains($l, 'marża') || str_contains($l, 'marza'))
            && ! str_contains($l, 'cena')) {
            return 75;
        }

        return 0;
    }

    private function skuScores(string $l): int
    {
        if (str_contains($l, 'klienta') || str_contains($l, 'customer product')) {
            return 0;
        }
        if (str_contains($l, 'ean') || str_contains($l, 'barcode') || str_contains($l, 'kod kresk')) {
            return 0;
        }
        if (str_contains($l, 'article number') || str_contains($l, 'artikelnummer') || str_contains($l, 'art.-nr')) {
            return 115;
        }
        if (str_contains($l, 'numer katalogowy produktu') || str_contains($l, 'kod produktu') || str_contains($l, 'kod towaru')) {
            return 110;
        }
        if (str_contains($l, 'long base style')) {
            return 108;
        }
        if (str_contains($l, 'new code')) {
            return 100;
        }
        if (str_contains($l, 'numer produktu') || str_contains($l, 'indeks nadrzędny') || str_contains($l, 'indeks nadrzedny')) {
            return 100;
        }
        if (str_contains($l, 'product code') || $l === 'sku' || str_contains($l, 'part num') || str_contains($l, 'item code')) {
            return 100;
        }
        if (str_contains($l, 'kod ref') || $l === 'kod' || $l === 'code') {
            return 90;
        }
        if (str_contains($l, 'pomlčkový') || str_contains($l, 'pomlckovy') || str_contains($l, 'pomlčkovy')) {
            return 95;
        }
        if ($l === 'artykuł' || $l === 'artykul' || str_starts_with($l, 'artykuł ') || str_starts_with($l, 'artykul ')) {
            return 90;
        }
        if (str_contains($l, 'erp code') || str_contains($l, 'marketing code')) {
            return 70;
        }
        if (str_contains($l, 'numer katalogowy') && ! str_contains($l, 'klient')) {
            return 85;
        }
        if (str_contains($l, 'nr artykułu') || str_contains($l, 'nr artykulu')) {
            return 90;
        }
        if (str_contains($l, 'numer magazynowy') || str_contains($l, '3m numer')) {
            return 55;
        }
        if (str_contains($l, 'sap id')) {
            return 80;
        }
        if ($l === 'style' || str_starts_with($l, 'style ')) {
            return 50;
        }
        if (str_contains($l, 'product reference')) {
            return 42;
        }
        if ($l === 'reference' || $l === 'ref') {
            return 50;
        }

        return 0;
    }

    private function modelKeyScores(string $l): int
    {
        if (str_contains($l, 'short base style')) {
            return 95;
        }
        if (str_contains($l, 'product reference') || str_contains($l, 'base style')) {
            return 90;
        }
        if ($l === 'reference' || str_starts_with($l, 'reference ')) {
            return 80;
        }
        if (str_contains($l, 'model code') || str_contains($l, 'short base style')) {
            return 70;
        }

        return 0;
    }

    private function nameScores(string $l): int
    {
        if (str_contains($l, 'numer katalogowy') || str_contains($l, 'article number')) {
            return 0;
        }
        if ($l === 'page' || str_contains($l, 'catalogue page') || $l === 'strana') {
            return 0;
        }
        if (str_contains($l, 'zdjęc') || str_contains($l, 'zdjec') || str_contains($l, 'product image') || $l === 'images') {
            return 0;
        }
        if (str_contains($l, 'nazwa produktu') || str_contains($l, 'nazwa towaru') || str_contains($l, 'nazwa handlowa')) {
            return 110;
        }
        if (str_contains($l, 'product name') || str_contains($l, 'produktname') || str_contains($l, 'wyszczególnienie') || str_contains($l, 'wyszczegolnienie')) {
            return 100;
        }
        if (str_contains($l, 'model name')) {
            return 100;
        }
        if (str_contains($l, 'new description')) {
            return 90;
        }
        if (str_contains($l, 'part description') || str_contains($l, 'opis produktu')) {
            return 85;
        }
        if (str_contains($l, 'commercial naming')) {
            return 80;
        }
        if ($l === 'nazwa' || str_starts_with($l, 'nazwa') || $l === 'název' || $l === 'nazev') {
            return 75;
        }
        if (str_contains($l, 'description') && ! str_contains($l, 'unit')) {
            return 65;
        }
        if ($l === 'opis' || str_starts_with($l, 'opis ')) {
            return 55;
        }

        return 0;
    }

    private function eanScores(string $l): int
    {
        if (str_contains($l, 'ean') || str_contains($l, 'barcode') || str_contains($l, 'kod kresk') || str_contains($l, 'gtin')) {
            return 100;
        }

        return 0;
    }

    private function categoryScores(string $l): int
    {
        if (str_contains($l, 'kategoria produktu poziom 1') || str_contains($l, 'category/type')) {
            return 90;
        }
        if (str_contains($l, 'grupa asortymentowa') || str_contains($l, 'klasa asortymentowa')) {
            return 80;
        }
        if ($l === 'kategoria' || $l === 'category' || $l === 'grupa') {
            return 70;
        }

        return 0;
    }

    private function currencyScores(string $l): int
    {
        if ($l === 'waluta' || $l === 'currency' || $l === 'curr') {
            return 90;
        }

        return 0;
    }

    private function packQtyScores(string $l): int
    {
        if (str_contains($l, 'minimum') || str_contains($l, 'moq') || str_contains($l, 'min.')) {
            return 0;
        }
        if (str_contains($l, 'quantity per box') || str_contains($l, 'qty per box') || str_contains($l, 'qty / box')) {
            return 100;
        }
        if (str_contains($l, 'ilość w opak') || str_contains($l, 'ilosc w opak') || str_contains($l, 'ilość w kartonie')) {
            return 90;
        }
        if (str_contains($l, 'case qty') || str_contains($l, 'carton quantity')) {
            return 80;
        }

        return 0;
    }

    private function packagingScores(string $l): int
    {
        if (str_contains($l, 'opis jednostki sprzedaży') || str_contains($l, 'jednostka sprzedaży')) {
            return 80;
        }
        if (str_contains($l, 'opakowanie') || str_contains($l, 'packaging') || $l === 'size' || $l === 'rozmiar') {
            return 70;
        }

        return 0;
    }

    private function isDateLikePriceLabel(string $l): bool
    {
        return str_contains($l, 'na dzień')
            || str_contains($l, 'na dzien')
            || str_contains($l, 'obowiązywania')
            || str_contains($l, 'obowiazywania')
            || str_contains($l, 'valid from')
            || str_contains($l, 'valid to')
            || str_contains($l, 'start date')
            || str_contains($l, 'pricing start');
    }

    private function isChangeLabel(string $l): bool
    {
        return str_contains($l, 'zmiana')
            || str_contains($l, 'increase')
            || str_contains($l, 'change')
            || str_contains($l, 'procentowa');
    }

    private function isTransportLabel(string $l): bool
    {
        return str_contains($l, 'transport');
    }

    private function norm(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '#ref!' || $value === '#n/a' || $value === '#value!' || str_starts_with($value, '---')) {
            return '';
        }
        $value = str_replace(["\r", "\n"], ' ', $value);

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
}
