<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use Smalot\PdfParser\Parser;

$path = $argv[1] ?? 'c:/xampp/htdocs/Przetargi/Cenniki/AJGROUP/2026/pros-cennik-zloty-2026-32_ od 20 kwietnia 2026.pdf';
$pdf = (new Parser)->parseFile($path);
$pages = $pdf->getPages();
echo 'pages='.count($pages).PHP_EOL;

$page = $pages[0];
$details = $page->getDataTm();
// each: [transform, text]
$rows = [];
foreach ($details as $item) {
    if (! is_array($item) || count($item) < 2) {
        continue;
    }
    $tm = $item[0];
    $text = trim((string) $item[1]);
    if ($text === '') {
        continue;
    }
    $x = (float) ($tm[4] ?? 0);
    $y = (float) ($tm[5] ?? 0);
    $rows[] = ['x' => $x, 'y' => round($y, 1), 't' => $text];
}

usort($rows, static function ($a, $b) {
    if (abs($a['y'] - $b['y']) > 2) {
        return $b['y'] <=> $a['y']; // top first
    }

    return $a['x'] <=> $b['x'];
});

$lines = [];
$curY = null;
$buf = [];
foreach ($rows as $r) {
    if ($curY === null || abs($r['y'] - $curY) > 2) {
        if ($buf !== []) {
            $lines[] = $buf;
        }
        $buf = [$r];
        $curY = $r['y'];
    } else {
        $buf[] = $r;
    }
}
if ($buf !== []) {
    $lines[] = $buf;
}

$out = [];
foreach (array_slice($lines, 0, 40) as $line) {
    $out[] = implode(' | ', array_map(static fn ($c) => $c['t'].'@'.round($c['x']), $line));
}
echo implode(PHP_EOL, $out), PHP_EOL;
