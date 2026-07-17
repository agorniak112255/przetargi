<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$s = new Spreadsheet();
$sh = $s->getActiveSheet();
$sh->fromArray([
    ['wymaganie', 'ilosc', 'sku', 'cena'],
    ['Antyprzecięciowe PU EN 388', 100, 'ARĘKGLOMJ713', 16.90],
    ['Chemoodporne EN 374', 50, 'ARĘK53001', 46],
    ['Nowa pozycja bez SKU — ręczne dopasowanie', 20, '', ''],
], null, 'A1');

$dir = __DIR__.'/../../storage/app/samples';
if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}

(new Xlsx($s))->save($dir.'/import_pozycje_demo.xlsx');
echo "OK: {$dir}/import_pozycje_demo.xlsx\n";
