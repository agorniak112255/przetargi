<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Odczyt arkusza: wynik formuły (cache Excela, potem wyliczenie), bez formatowania —
 * formatData=true pada na zdjęciach w komórkach (Drawing).
 */
final class SpreadsheetCellReader
{
    /**
     * @return list<list<string>>
     */
    public function toRows(Worksheet $sheet): array
    {
        $maxRow = max(1, (int) $sheet->getHighestDataRow());
        $maxCol = max(1, Coordinate::columnIndexFromString($sheet->getHighestDataColumn() ?: 'A'));
        $out = [];
        for ($r = 1; $r <= $maxRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $coord = Coordinate::stringFromColumnIndex($c).$r;
                $row[] = $this->readCell($sheet->getCell($coord));
            }
            $out[] = $row;
        }

        return $out;
    }

    public function stringify(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof RichText) {
            return $this->cleanExcelError(trim($value->getPlainText()));
        }
        if (is_array($value)) {
            while (is_array($value)) {
                $value = array_shift($value);
            }

            return $this->stringify($value);
        }
        if (is_object($value)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return $this->cleanExcelError(trim((string) $value));
    }

    private function readCell(Cell $cell): string
    {
        $text = $this->stringify($this->resolvedValue($cell));
        if ($this->cellIsFormula($cell) && $this->looksLikeFormulaText($text)) {
            return '';
        }

        return $text;
    }

    private function resolvedValue(Cell $cell): mixed
    {
        $value = $cell->getValue();
        if ($value === null || $value === '') {
            return '';
        }
        if (is_object($value) && ! $value instanceof RichText) {
            return '';
        }
        if (! $this->cellIsFormula($cell)) {
            return $value;
        }

        $cached = $this->cachedFormulaValue($cell);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $calculated = $cell->getCalculatedValue();
        } catch (Throwable) {
            return '';
        }

        if (is_object($calculated) && ! $calculated instanceof RichText) {
            return '';
        }
        if ($this->looksLikeFormulaText($this->stringify($calculated))) {
            return '';
        }

        return $calculated;
    }

    private function cachedFormulaValue(Cell $cell): mixed
    {
        try {
            $cached = $cell->getOldCalculatedValue();
        } catch (Throwable) {
            return null;
        }
        if ($cached === null || $cached === '') {
            return null;
        }
        if (is_object($cached) && ! $cached instanceof RichText) {
            return null;
        }
        if (is_string($cached) && $this->looksLikeFormulaText($cached)) {
            return null;
        }

        return $cached;
    }

    private function cellIsFormula(Cell $cell): bool
    {
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            return true;
        }
        $value = $cell->getValue();

        return is_string($value) && str_starts_with(ltrim($value), '=');
    }

    private function looksLikeFormulaText(string $value): bool
    {
        $trim = ltrim($value);
        if ($trim === '') {
            return false;
        }
        if (str_starts_with($trim, '=')) {
            return true;
        }

        return preg_match("/^'[^']+'![A-Za-z]{1,3}\\d+$/", $trim) === 1;
    }

    private function cleanExcelError(string $value): string
    {
        $plain = ltrim($value, '=');
        $upper = strtoupper($plain);
        if (in_array($upper, ['#N/A', '#REF!', '#VALUE!', '#NAME?', '#DIV/0!', '#NULL!', '#NUM!', '#SPILL!'], true)) {
            return '';
        }

        return $value;
    }
}
