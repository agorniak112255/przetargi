<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$s = new Spreadsheet();
$sh = $s->getActiveSheet();
$sh->fromArray([
    ['sku', 'nazwa', 'producent', 'kategoria', 'normy', 'cena', 'rabat', 'stan'],
    ['ARĘKGLOMJ713', 'Lebon POWERCUT/WH PU', 'Lebon', 'antyprzecieciowe', 'EN 388', 21.99, 38, 840],
    ['ARĘKPOWERFIT', 'Lebon POWERFIT PU', 'Lebon', 'antyprzecieciowe', 'EN 388', 19.99, 38, 1100],
    ['DEMO-LEBON-001', 'Lebon Demo Grip Light', 'Lebon', 'montazowe', 'EN 388', 8.50, 35, 500],
    ['DEMO-LEBON-002', 'Lebon Demo Cut Pro', 'Lebon', 'antyprzecieciowe', 'EN 388', 24.00, 40, 120],
], null, 'A1');

$paths = [
    __DIR__.'/../../storage/app/samples/cennik_demo.xlsx',
    __DIR__.'/../../../samples/cennik_demo.xlsx',
    __DIR__.'/../../../frontend/public/cennik_demo.xlsx',
];

foreach ($paths as $path) {
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    (new Xlsx($s))->save($path);
    echo "OK {$path}\n";
}
