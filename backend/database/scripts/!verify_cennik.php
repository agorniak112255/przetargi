<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? null;
if ($path === null || ! is_file($path)) {
    fwrite(STDERR, "Usage: php !verify_cennik.php <path-to-xlsx>\n");
    exit(1);
}

$spreadsheet = IOFactory::load($path);
$report = [
    'file' => basename($path),
    'path' => $path,
    'sheets' => [],
];

foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
    $title = $sheet->getTitle();
    $rows = $sheet->toArray(null, true, true, false);
    $nonEmpty = array_values(array_filter($rows, static function ($r) {
        foreach ($r as $c) {
            if ($c !== null && trim((string) $c) !== '') {
                return true;
            }
        }

        return false;
    }));

    $sampleHeaders = [];
    $headerRowIdx = null;
    for ($i = 0; $i < min(25, count($nonEmpty)); $i++) {
        $cells = array_map(static fn ($v) => trim((string) ($v ?? '')), $nonEmpty[$i]);
        $filled = array_filter($cells, static fn ($v) => $v !== '');
        if (count($filled) >= 3) {
            $joined = mb_strtolower(implode(' ', $filled));
            if (
                str_contains($joined, 'sku')
                || str_contains($joined, 'code')
                || str_contains($joined, 'kod')
                || str_contains($joined, 'price')
                || str_contains($joined, 'cena')
                || str_contains($joined, 'product')
                || str_contains($joined, 'nazwa')
                || str_contains($joined, 'article')
                || str_contains($joined, 'ref')
            ) {
                $headerRowIdx = $i;
                $sampleHeaders = array_values(array_slice($cells, 0, 20));
                break;
            }
        }
    }

    if ($headerRowIdx === null && isset($nonEmpty[0])) {
        $sampleHeaders = array_values(array_slice(
            array_map(static fn ($v) => trim((string) ($v ?? '')), $nonEmpty[0]),
            0,
            20
        ));
        $headerRowIdx = 0;
    }

    $dataPreview = [];
    if ($headerRowIdx !== null) {
        for ($i = $headerRowIdx + 1; $i < min($headerRowIdx + 6, count($nonEmpty)); $i++) {
            $dataPreview[] = array_values(array_slice(
                array_map(static fn ($v) => trim((string) ($v ?? '')), $nonEmpty[$i]),
                0,
                12
            ));
        }
    }

    // Heuristic field detection on header
    $detected = [];
    foreach ($sampleHeaders as $idx => $h) {
        $hl = mb_strtolower($h);
        foreach (
            [
                'sku' => ['sku', 'code', 'kod', 'article', 'ref', ' Sap', 'material'],
                'name' => ['name', 'nazwa', 'description', 'opis', 'product'],
                'price' => ['price', 'cena', 'list', 'netto', 'eur', 'pln'],
                'discount' => ['discount', 'rabat', 'upust', '%'],
                'ean' => ['ean', 'barcode'],
            ] as $field => $aliases
        ) {
            foreach ($aliases as $a) {
                if ($hl !== '' && str_contains($hl, mb_strtolower(trim($a)))) {
                    $detected[$field][] = ['col' => $idx, 'header' => $h];
                    break;
                }
            }
        }
    }

    $report['sheets'][] = [
        'title' => $title,
        'rows_raw' => count($rows),
        'rows_non_empty' => count($nonEmpty),
        'likely_header_row' => $headerRowIdx,
        'headers_sample' => $sampleHeaders,
        'detected_fields' => $detected,
        'data_preview' => $dataPreview,
        'import_ready' => isset($detected['sku'], $detected['name'], $detected['price'])
            || (isset($detected['sku']) && isset($detected['price'])),
    ];
}

$out = __DIR__.'/../../storage/app/samples/verify_cennik_report.json';
file_put_contents($out, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
echo "\nSaved: {$out}\n";
