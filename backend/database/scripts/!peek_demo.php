<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$p = __DIR__.'/../../../samples/cennik_demo.xlsx';
$s = PhpOffice\PhpSpreadsheet\IOFactory::load($p);
echo $s->getActiveSheet()->getTitle(), PHP_EOL;
foreach (array_slice($s->getActiveSheet()->toArray(null, true, true, false), 0, 6) as $i => $r) {
    echo $i.': '.json_encode($r, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
