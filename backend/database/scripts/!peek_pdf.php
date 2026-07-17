<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use App\Services\PriceListPdfTextExtractor;

$path = $argv[1] ?? 'c:/xampp/htdocs/Przetargi/Cenniki/ACO-TEC/cennik Kleenvox 2020.pdf';
$text = (new PriceListPdfTextExtractor)->extract($path, 2000);
echo 'chars='.mb_strlen($text).PHP_EOL;
echo mb_substr($text, 0, 800), PHP_EOL;
