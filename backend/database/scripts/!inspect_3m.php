<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = 'c:/xampp/htdocs/Przetargi/Cenniki/3M/3M_Cennik_od_01.07.2021-2.xlsx';
$ss = IOFactory::load($path);

$summary = ['sheets' => []];
foreach ($ss->getWorksheetIterator() as $sheet) {
    $title = $sheet->getTitle();
    $rows = $sheet->toArray(null, true, true, false);
    $hits = [];
    for ($i = 0; $i < min(40, count($rows)); $i++) {
        $cells = array_map(static fn ($v) => trim((string) ($v ?? '')), $rows[$i]);
        $joined = mb_strtolower(implode(' | ', array_filter($cells)));
        if (
            str_contains($joined, 'kod')
            || str_contains($joined, 'cena')
            || str_contains($joined, 'nazwa')
            || str_contains($joined, 'sku')
            || str_contains($joined, 'indeks')
            || str_contains($joined, 'opis')
            || str_contains($joined, 'produkt')
            || str_contains($joined, 'eur')
            || str_contains($joined, 'pln')
        ) {
            $hits[] = ['row' => $i, 'cells' => array_values(array_slice($cells, 0, 14))];
        }
    }
    $summary['sheets'][] = [
        'title' => $title,
        'rows' => count($rows),
        'header_hits' => $hits,
    ];
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
