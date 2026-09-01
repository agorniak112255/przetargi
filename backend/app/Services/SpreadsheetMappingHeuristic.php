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
    public function __construct(
        private readonly SpreadsheetColumnMapper $columns = new SpreadsheetColumnMapper,
    ) {}

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
            $kind = $this->columns->classifySheet($name);
            if ($kind === 'skip') {
                continue;
            }

            $maxCol = min(28, Coordinate::columnIndexFromString($sheet->getHighestDataColumn() ?: 'A'));
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

            $mapped = $this->mapSheet($name, $grid, $kind);
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
    private function mapSheet(string $sheetName, array $grid, string $kind = 'catalog'): ?array
    {
        if ($kind === 'special') {
            return $this->mapSpecialSheet($sheetName, $grid);
        }

        $best = null;
        $bestScore = 0;

        foreach ($grid as $excelRow => $cells) {
            $labels = array_map(fn (string $v) => mb_strtolower($v), $cells);
            $blob = implode(' | ', $labels);
            if ($blob === '' || mb_strlen($blob) < 4) {
                continue;
            }

            $mapped = $this->columns->mapLabels($cells);
            $skuCol = $mapped['sku'] ?? $this->findBestSkuCol($labels, $grid, $excelRow, $mapped['catalog_price']);
            $cols = [
                'sku' => $skuCol,
                'sku_alt' => $mapped['sku_alt'] ?? null,
                'model_key' => $mapped['model_key'],
                'name' => $mapped['name'],
                'catalog_price' => $mapped['catalog_price'],
                'purchase' => $mapped['purchase'],
                'discount' => $mapped['discount'],
                'pack_qty' => $mapped['pack_qty'],
                'packaging' => $mapped['packaging'],
                'ean' => $mapped['ean'],
                'category' => $mapped['category'],
                'currency' => $mapped['currency'],
            ];
            if ($cols['sku'] === null && $mapped['catalog_price'] !== null) {
                $cols['sku'] = $this->inferSkuFromData($grid, $excelRow, $mapped['catalog_price'], $cols);
            }

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
            if ($cols['model_key'] === null) {
                $cols['model_key'] = $this->inferModelKeyFromData($grid, $excelRow, $cols);
            }
            if ($cols['packaging'] === null) {
                $cols['packaging'] = $this->inferSizeFromData($grid, $excelRow, $cols);
            }
            if ($cols['pack_qty'] === null) {
                $cols['pack_qty'] = $this->inferPackQtyFromData($grid, $excelRow, $cols);
            }
            if ($cols['name'] === null || $cols['name'] === $cols['catalog_price']) {
                $cols['name'] = $this->resolveNameCol($labels, $cols);
            }
            if ($cols['name'] === $cols['sku'] || $cols['name'] === $cols['catalog_price']) {
                $inferredName = $this->inferNameFromData($grid, $excelRow, $cols);
                if ($inferredName !== null) {
                    $cols['name'] = $inferredName;
                }
            }
            if ($cols['name'] === $cols['sku']
                || $this->nameColUnusable($grid, $excelRow, $cols['name'], $cols['catalog_price'])
            ) {
                $cols['name'] = $cols['model_key'] ?? $cols['sku'];
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
                        'sku_alt' => $cols['sku_alt'],
                        'model_key' => $cols['model_key'],
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
                    'role' => 'catalog',
                    '_currency' => $currency,
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array<int, list<string>>  $grid
     * @return array<string, mixed>|null
     */
    private function mapSpecialSheet(string $sheetName, array $grid): ?array
    {
        foreach ($grid as $excelRow => $cells) {
            $cols = $this->columns->mapSpecialLabels($cells);
            if ($cols['purchase'] === null || ($cols['sku'] === null && $cols['ean'] === null)) {
                continue;
            }

            return [
                'sheet' => $sheetName,
                'include' => false,
                'header_excel_row' => $excelRow,
                'columns' => $cols,
                'repeating_headers' => false,
                'confidence' => 0.9,
                'role' => 'special',
                '_currency' => null,
            ];
        }

        return null;
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
     * Wybór kolumny SKU: preferuj unikalny kod pozycji (Article Number)
     * nad kodem modelu (Reference), który bywa pusty w wariantach rozmiarów.
     *
     * @param  list<string>  $labels
     * @param  array<int, list<string>>  $grid
     */
    private function findBestSkuCol(array $labels, array $grid, int $headerRow, ?int $priceIdx): ?int
    {
        /** @var list<array{0: list<string>, 1: int}> $tiers */
        $tiers = [
            [['article number', 'artikelnummer', 'kod produktu', 'kod towaru', 'product code', 'sku', 'sap id', 'new code', 'long base style'], 100],
            [['art. nr', 'art nr', 'art.-nr', 'artikel', 'symbol', 'indeks', 'katalogové', 'katalogove', 'kod ref', 'numer produktu'], 70],
            [['article', 'sap', 'artykuł', 'artykul'], 55],
            [['product reference', 'reference', 'ref', 'code', 'kod'], 40],
        ];

        $bestIdx = null;
        $bestScore = 0.0;

        foreach ($labels as $i => $label) {
            if ($label === '') {
                continue;
            }
            $compact = preg_replace('/\s+/', ' ', $label) ?? $label;
            $aliasScore = 0;
            foreach ($tiers as [$needles, $score]) {
                foreach ($needles as $needle) {
                    if ($compact === $needle || str_contains($compact, $needle)) {
                        $aliasScore = $score;
                        break 2;
                    }
                }
            }
            if ($aliasScore === 0) {
                continue;
            }

            $stats = $this->skuColumnStats($grid, $headerRow, $i, $priceIdx);
            // niski fill rate (typowy „Reference” tylko w 1. wierszu modelu) mocno obniża score
            $score = $aliasScore + ($stats['fill_rate'] * 40) + ($stats['unique_rate'] * 25);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx = $i;
            }
        }

        return $bestIdx;
    }

    /**
     * @param  array<int, list<string>>  $grid
     * @return array{fill_rate: float, unique_rate: float}
     */
    private function skuColumnStats(array $grid, int $headerRow, int $skuIdx, ?int $priceIdx): array
    {
        $priced = 0;
        $filled = 0;
        $values = [];
        foreach ($grid as $r => $cells) {
            if ($r <= $headerRow) {
                continue;
            }
            if ($priceIdx !== null) {
                $price = trim((string) ($cells[$priceIdx] ?? ''));
                if ($price === '' || ! preg_match('/\d/', $price)) {
                    continue;
                }
            }
            $priced++;
            $sku = trim((string) ($cells[$skuIdx] ?? ''));
            if ($sku === '' || strtoupper($sku) === '#N/A') {
                continue;
            }
            $filled++;
            $values[$sku] = true;
            if ($priced >= 40) {
                break;
            }
        }

        if ($priced === 0) {
            return ['fill_rate' => 0.0, 'unique_rate' => 0.0];
        }

        return [
            'fill_rate' => $filled / $priced,
            'unique_rate' => $filled > 0 ? count($values) / $filled : 0.0,
        ];
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
            if (! $this->looksLikeMoney($price)) {
                continue;
            }
            $hits++;
            if ($hits >= 5) {
                break;
            }
        }

        return $hits;
    }

    /**
     * @param  list<string>  $labels
     * @param  array<string, int|null>  $cols
     */
    private function resolveNameCol(array $labels, array $cols): int
    {
        $sku = $cols['sku'];
        $price = $cols['catalog_price'];
        if ($sku !== null) {
            $next = $sku + 1;
            $nextLabel = mb_strtolower(trim((string) ($labels[$next] ?? '')));
            if (in_array($nextLabel, ['#ref!', '#n/a', '#value!'], true)) {
                $nextLabel = '';
            }
            if ($next !== $price && $nextLabel !== '' && ! $this->isNonNameLabel($nextLabel)) {
                return $next;
            }

            return $sku;
        }

        foreach ($labels as $i => $label) {
            if ($i === $price || $this->isNonNameLabel(mb_strtolower(trim($label)))) {
                continue;
            }
            if (trim($label) !== '') {
                return $i;
            }
        }

        return 0;
    }

    private function isNonNameLabel(string $label): bool
    {
        return $label === 'page'
            || $label === 'strana'
            || $label === 'lp'
            || $label === 'lp.'
            || str_contains($label, 'cena')
            || str_contains($label, 'price')
            || str_contains($label, 'vat')
            || str_contains($label, 'ean')
            || str_contains($label, 'zdję')
            || str_contains($label, 'zdjec')
            || str_contains($label, 'image')
            || str_contains($label, 'photo')
            || $label === 'size'
            || str_contains($label, 'rozmiar')
            || $label === 'mj'
            || $label === 'unit'
            || preg_match('/^column\s*\d+$/', $label) === 1
            || str_contains($label, '€/pc')
            || str_contains($label, '£/pc')
            || str_contains($label, 'price lists');
    }

    /**
     * @param  array<int, list<string>>  $grid
     * @param  array<string, int|null>  $cols
     */
    private function inferSkuFromData(array $grid, int $headerRow, int $priceIdx, array $cols): ?int
    {
        $skip = [];
        foreach (['name', 'ean', 'purchase', 'discount'] as $key) {
            if (($cols[$key] ?? null) !== null) {
                $skip[] = $cols[$key];
            }
        }
        $skip[] = $priceIdx;

        $width = 0;
        foreach ($grid as $cells) {
            $width = max($width, count($cells));
        }

        $best = null;
        $bestScore = 0.0;
        for ($i = 0; $i < $width; $i++) {
            if (in_array($i, $skip, true)) {
                continue;
            }
            $priced = 0;
            $filled = 0;
            $uniq = [];
            foreach ($grid as $r => $cells) {
                if ($r <= $headerRow) {
                    continue;
                }
                if (! $this->looksLikeMoney(trim((string) ($cells[$priceIdx] ?? '')))) {
                    continue;
                }
                $priced++;
                $raw = trim((string) ($cells[$i] ?? ''));
                if (! $this->looksLikeSkuValue($raw)) {
                    continue;
                }
                $filled++;
                $uniq[$raw] = true;
                if ($priced >= 40) {
                    break;
                }
            }
            if ($priced < 2 || $filled < 2) {
                continue;
            }
            $score = ($filled / $priced) * 40 + (count($uniq) / max(1, $filled)) * 25;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
        }

        return $bestScore >= 30.0 ? $best : null;
    }

    /**
     * @param  array<int, list<string>>  $grid
     * @param  array<string, int|null>  $cols
     */
    private function inferNameFromData(array $grid, int $headerRow, array $cols): ?int
    {
        $priceIdx = $cols['catalog_price'];
        if ($priceIdx === null) {
            return null;
        }
        $skip = array_filter([$priceIdx, $cols['sku'] ?? null, $cols['ean'] ?? null, $cols['purchase'] ?? null], fn ($v) => $v !== null);
        $width = 0;
        foreach ($grid as $cells) {
            $width = max($width, count($cells));
        }

        $best = null;
        $bestAvg = 0.0;
        for ($i = 0; $i < $width; $i++) {
            if (in_array($i, $skip, true)) {
                continue;
            }
            $lens = [];
            foreach ($grid as $r => $cells) {
                if ($r <= $headerRow) {
                    continue;
                }
                if (! $this->looksLikeMoney(trim((string) ($cells[$priceIdx] ?? '')))) {
                    continue;
                }
                $raw = trim((string) ($cells[$i] ?? ''));
                if (mb_strlen($raw) < 8 || $this->looksLikeMoney($raw) || $this->looksLikePriceHeaderText($raw)) {
                    continue;
                }
                if ($this->looksLikeCategoryValue($raw)) {
                    continue;
                }
                $lens[] = mb_strlen($raw);
                if (count($lens) >= 20) {
                    break;
                }
            }
            if (count($lens) < 2) {
                continue;
            }
            $avg = array_sum($lens) / count($lens);
            if ($avg > $bestAvg) {
                $bestAvg = $avg;
                $best = $i;
            }
        }

        return $bestAvg >= 12.0 ? $best : null;
    }

    /**
     * @param  array<int, list<string>>  $grid
     * @param  array<string, int|null>  $cols
     */
    private function inferModelKeyFromData(array $grid, int $headerRow, array $cols): ?int
    {
        $skip = $this->skipCols($cols);
        $width = $this->gridWidth($grid);
        $best = null;
        $bestScore = 0.0;
        for ($i = 0; $i < $width; $i++) {
            if (in_array($i, $skip, true)) {
                continue;
            }
            $priced = 0;
            $filled = 0;
            $uniq = [];
            foreach ($grid as $r => $cells) {
                if ($r <= $headerRow) {
                    continue;
                }
                $priceIdx = $cols['catalog_price'];
                if ($priceIdx !== null && ! $this->looksLikeMoney(trim((string) ($cells[$priceIdx] ?? '')))) {
                    continue;
                }
                $priced++;
                $raw = trim((string) ($cells[$i] ?? ''));
                if (! $this->looksLikeModelCode($raw)) {
                    continue;
                }
                $filled++;
                $uniq[$raw] = true;
                if ($priced >= 40) {
                    break;
                }
            }
            if ($filled < 2) {
                continue;
            }
            // Reference bywa tylko w 1. wierszu modelu (kolejne rozmiary puste)
            $score = $filled + (count($uniq) * 3) + min(20.0, ($filled / $priced) * 20);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
        }

        return $bestScore >= 5.0 ? $best : null;
    }

    /**
     * @param  array<int, list<string>>  $grid
     * @param  array<string, int|null>  $cols
     */
    private function inferSizeFromData(array $grid, int $headerRow, array $cols): ?int
    {
        $skip = $this->skipCols($cols);
        $width = $this->gridWidth($grid);
        $best = null;
        $bestRate = 0.0;
        for ($i = 0; $i < $width; $i++) {
            if (in_array($i, $skip, true)) {
                continue;
            }
            $priced = 0;
            $sizes = 0;
            foreach ($grid as $r => $cells) {
                if ($r <= $headerRow) {
                    continue;
                }
                $priceIdx = $cols['catalog_price'];
                if ($priceIdx !== null && ! $this->looksLikeMoney(trim((string) ($cells[$priceIdx] ?? '')))) {
                    continue;
                }
                $priced++;
                if ($this->looksLikeSizeToken(trim((string) ($cells[$i] ?? '')))) {
                    $sizes++;
                }
                if ($priced >= 40) {
                    break;
                }
            }
            if ($priced < 2) {
                continue;
            }
            $rate = $sizes / $priced;
            if ($rate > $bestRate) {
                $bestRate = $rate;
                $best = $i;
            }
        }

        return $bestRate >= 0.5 ? $best : null;
    }

    /**
     * @param  array<int, list<string>>  $grid
     * @param  array<string, int|null>  $cols
     */
    private function inferPackQtyFromData(array $grid, int $headerRow, array $cols): ?int
    {
        $skip = $this->skipCols($cols);
        $width = $this->gridWidth($grid);
        $best = null;
        $bestRate = 0.0;
        for ($i = 0; $i < $width; $i++) {
            if (in_array($i, $skip, true)) {
                continue;
            }
            $priced = 0;
            $qty = 0;
            foreach ($grid as $r => $cells) {
                if ($r <= $headerRow) {
                    continue;
                }
                $priceIdx = $cols['catalog_price'];
                if ($priceIdx !== null && ! $this->looksLikeMoney(trim((string) ($cells[$priceIdx] ?? '')))) {
                    continue;
                }
                $priced++;
                $raw = trim((string) ($cells[$i] ?? ''));
                if (preg_match('/^\d{1,4}$/', $raw) === 1) {
                    $n = (int) $raw;
                    if ($n >= 1 && $n <= 2000) {
                        $qty++;
                    }
                }
                if ($priced >= 40) {
                    break;
                }
            }
            if ($priced < 2) {
                continue;
            }
            $rate = $qty / $priced;
            if ($rate > $bestRate) {
                $bestRate = $rate;
                $best = $i;
            }
        }

        return $bestRate >= 0.6 ? $best : null;
    }

    /**
     * @param  array<int, list<string>>  $grid
     */
    private function nameColUnusable(array $grid, int $headerRow, ?int $nameIdx, ?int $priceIdx): bool
    {
        if ($nameIdx === null) {
            return true;
        }
        $ok = 0;
        $bad = 0;
        foreach ($grid as $r => $cells) {
            if ($r <= $headerRow) {
                continue;
            }
            if ($priceIdx !== null && ! $this->looksLikeMoney(trim((string) ($cells[$priceIdx] ?? '')))) {
                continue;
            }
            $raw = trim((string) ($cells[$nameIdx] ?? ''));
            if ($raw === '' || $this->looksLikeMoney($raw) || $this->looksLikePriceHeaderText($raw) || $this->looksLikeCategoryValue($raw)) {
                $bad++;
            } else {
                $ok++;
            }
            if (($ok + $bad) >= 20) {
                break;
            }
        }

        return $ok === 0 || $bad > $ok;
    }

    /**
     * @param  array<string, int|null>  $cols
     * @return list<int>
     */
    private function skipCols(array $cols): array
    {
        $skip = [];
        foreach (['sku', 'sku_alt', 'name', 'ean', 'purchase', 'discount', 'catalog_price', 'model_key', 'packaging', 'pack_qty', 'currency', 'category'] as $key) {
            if (($cols[$key] ?? null) !== null) {
                $skip[] = $cols[$key];
            }
        }

        return $skip;
    }

    /**
     * @param  array<int, list<string>>  $grid
     */
    private function gridWidth(array $grid): int
    {
        $width = 0;
        foreach ($grid as $cells) {
            $width = max($width, count($cells));
        }

        return $width;
    }

    private function looksLikeModelCode(string $value): bool
    {
        if (mb_strlen($value) < 6 || mb_strlen($value) > 48) {
            return false;
        }
        if ($this->looksLikeMoney($value) || $this->looksLikePriceHeaderText($value)) {
            return false;
        }
        if (preg_match('/^D\d{6,}\*?$/i', $value) === 1) {
            return false;
        }
        if (preg_match('/[A-Za-z]/', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
            return false;
        }

        return preg_match('/^[A-Z0-9][A-Z0-9 .\-\/]{4,}$/i', $value) === 1;
    }

    private function looksLikeSizeToken(string $value): bool
    {
        $pack = strtoupper(trim($value));

        return preg_match('/^(XXS|XS|S|M|L|XL|XXL|XXXL|XXXXL|[2-6]XL|ONE\s*SIZE|ONESIZE)$/', $pack) === 1;
    }

    private function looksLikePriceHeaderText(string $value): bool
    {
        $l = mb_strtolower(trim($value));
        if ($l === '') {
            return false;
        }

        return preg_match('/^column\s*\d+$/', $l) === 1
            || str_contains($l, '€/pc')
            || str_contains($l, '£/pc')
            || str_contains($l, 'price lists')
            || (str_contains($l, 'price') && (str_contains($l, 'min.') || str_contains($l, 'min ')))
            || str_contains($l, 'superpartner')
            || str_contains($l, 'coregeneralist')
            || str_contains($l, 'corespecialist');
    }

    private function looksLikeCategoryValue(string $value): bool
    {
        $l = mb_strtolower(trim($value));

        return preg_match('/^cat\.?\s*(iii|ii|i|\d)/i', $l) === 1
            || preg_match('/^type\s*\d/i', $l) === 1
            || str_starts_with($l, 'cat. iii')
            || str_starts_with($l, 'cat iii');
    }

    private function looksLikeMoney(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return false;
        }
        if (substr_count($value, '-') >= 2 || mb_strlen($value) > 24) {
            return false;
        }
        if (preg_match('/[A-Za-zĄĆĘŁŃÓŚŹŻąćęłńóśźż]{4,}/u', $value) === 1) {
            return false;
        }
        $s = str_replace(["\xc2\xa0", "\xe2\x80\xaf"], '', $value);
        $s = preg_replace('/\s+/u', '', $s) ?? $s;
        $s = str_replace(['zł.', 'zł', 'pln', '€', 'eur', '$', '£'], '', mb_strtolower($s));
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d{1,4})?$/', $s) === 1) {
            return true;
        }
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d{1,4})?$/', $s) === 1) {
            return true;
        }

        return preg_match('/^-?\d+([.,]\d{1,4})?$/', $s) === 1;
    }

    private function looksLikeSkuValue(string $value): bool
    {
        $len = mb_strlen($value);
        if ($len < 3 || $len > 40) {
            return false;
        }
        if ($this->looksLikeMoney($value)) {
            return false;
        }
        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) > 6) {
            return false;
        }

        return preg_match('/[A-Za-z0-9]/', $value) === 1;
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
