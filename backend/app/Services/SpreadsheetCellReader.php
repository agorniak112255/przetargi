<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Odczyt arkusza bez formatowania — toArray(..., formatData=true) pada
 * na zdjęciach w komórkach (Drawing) komunikatem „Unable to convert to string”.
 */
final class SpreadsheetCellReader
{
    /**
     * @return list<list<string>>
     */
    public function toRows(Worksheet $sheet): array
    {
        $raw = $sheet->toArray(null, false, false, false);
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = array_map($this->stringify(...), $row);
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
