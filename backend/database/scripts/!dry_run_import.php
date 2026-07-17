<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? null;
$sheetName = $argv[2] ?? null;
if ($path === null || ! is_file($path)) {
    fwrite(STDERR, "Usage: php !dry_run_import.php <xlsx> [sheet]\n");
    exit(1);
}

$ss = IOFactory::load($path);
$sheet = $sheetName !== null ? $ss->getSheetByName($sheetName) : $ss->getActiveSheet();
if ($sheet === null) {
    fwrite(STDERR, "Sheet not found\n");
    exit(1);
}

$rows = $sheet->toArray(null, true, true, false);

$resolve = static function (array $header): array {
    $header = array_map(static fn ($v) => mb_strtolower(trim((string) $v)), $header);
    $find = static function (array $aliases) use ($header): ?int {
        foreach ($header as $i => $col) {
            foreach ($aliases as $alias) {
                if ($col === $alias || str_contains($col, $alias)) {
                    return $i;
                }
            }
        }

        return null;
    };
    $map = [];
    $defs = [
        'sku' => ['sku', 'kod', 'code', 'indeks', 'ref', 'product reference'],
        'name' => ['nazwa', 'name', 'produkt', 'opis', 'description'],
        'catalog_price' => ['cena_kat', 'cena katalog', 'cena_netto', 'cena', 'price', 'netto', 'price list price', 'list price minus'],
        'discount' => ['rabat', 'discount', 'upust', 'pl discount'],
        'purchase' => ['zakup', 'cena_zakupu', 'purchase', 'koszt', 'final price'],
    ];
    foreach ($defs as $key => $aliases) {
        $idx = $find($aliases);
        if ($idx !== null) {
            $map[$key] = $idx;
        }
    }

    return ['map' => $map, 'header' => $header];
};

// A) jak dziś: active sheet, wiersz 0
$active = $ss->getActiveSheet()->getTitle();
$activeRows = $ss->getActiveSheet()->toArray(null, true, true, false);
$a = $resolve($activeRows[0] ?? []);

// B) właściwy arkusz + wiersz nagłówka (szukaj "Product Reference")
$headerIdx = null;
for ($i = 0; $i < min(15, count($rows)); $i++) {
    $joined = mb_strtolower(implode(' ', array_map(static fn ($v) => (string) $v, $rows[$i])));
    if (str_contains($joined, 'product reference') || str_contains($joined, 'description')) {
        $headerIdx = $i;
        break;
    }
}
$b = $headerIdx !== null ? $resolve($rows[$headerIdx]) : ['map' => [], 'header' => []];

$simulate = static function (array $dataRows, array $map): array {
    $ok = 0;
    $skip = 0;
    $err = 0;
    $samples = [];
    foreach ($dataRows as $index => $row) {
        $sku = trim((string) ($row[$map['sku'] ?? -1] ?? ''));
        $name = trim((string) ($row[$map['name'] ?? -1] ?? ''));
        $price = $row[$map['catalog_price'] ?? -1] ?? null;
        if ($sku === '' && $name === '') {
            $skip++;
            continue;
        }
        if ($sku === '' || $name === '' || $price === null || $price === '') {
            $err++;
            continue;
        }
        $ok++;
        if (count($samples) < 5) {
            $disc = isset($map['discount']) ? $row[$map['discount']] : null;
            $samples[] = [
                'sku' => $sku,
                'name' => $name,
                'catalog_price' => $price,
                'discount' => $disc,
            ];
        }
    }

    return compact('ok', 'skip', 'err', 'samples');
};

$resultA = isset($a['map']['sku'], $a['map']['name'], $a['map']['catalog_price'])
    ? $simulate(array_slice($activeRows, 1), $a['map'])
    : ['ok' => 0, 'skip' => 0, 'err' => 0, 'samples' => [], 'fail' => 'brak mapowania kolumn'];

$resultB = isset($b['map']['sku'], $b['map']['name'], $b['map']['catalog_price'])
    ? $simulate(array_slice($rows, $headerIdx + 1), $b['map'])
    : ['ok' => 0, 'skip' => 0, 'err' => 0, 'samples' => [], 'fail' => 'brak mapowania kolumn'];

$out = [
    'file' => basename($path),
    'active_sheet' => $active,
    'analyzed_sheet' => $sheet->getTitle(),
    'current_importer' => [
        'uses' => 'getActiveSheet() + row 0 as header',
        'header_row0' => array_slice($a['header'], 0, 15),
        'map' => $a['map'],
        'result' => $resultA,
    ],
    'improved_mapping' => [
        'header_row_index' => $headerIdx,
        'header' => array_slice($b['header'], 0, 15),
        'map' => $b['map'],
        'result' => $resultB,
        'total_data_rows' => max(0, count($rows) - ($headerIdx ?? 0) - 1),
    ],
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
