<?php

declare(strict_types=1);

/**
 * Jednorazowy transfer danych: SQLite → MySQL (po migrate na MySQL).
 * php database/scripts/!migrate_sqlite_to_mysql.php
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sqlitePath = database_path('database.sqlite');
if (! is_file($sqlitePath)) {
    fwrite(STDERR, "Brak pliku SQLite: {$sqlitePath}\n");
    exit(1);
}

$sqlite = new PDO('sqlite:'.$sqlitePath);
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mysql = DB::connection()->getPdo();
if ($mysql->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    fwrite(STDERR, "Aktualne połączenie Laravel nie jest MySQL. Sprawdź .env\n");
    exit(1);
}

$tables = [
    'users',
    'clients',
    'products',
    'product_substitutes',
    'tenders',
    'tender_items',
    'tender_status_histories',
    'price_lists',
    'ai_settings',
    'personal_access_tokens',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'failed_jobs',
    'sessions',
];

DB::statement('SET FOREIGN_KEY_CHECKS=0');

foreach ($tables as $table) {
    $exists = $sqlite->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name=".$sqlite->quote($table)
    )->fetchColumn();
    if (! $exists) {
        echo "skip (brak w sqlite): {$table}\n";
        continue;
    }

    $rows = $sqlite->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        echo "ok (pusta): {$table}\n";
        continue;
    }

    DB::table($table)->delete();

    $mysqlCols = [];
    try {
        $mysqlCols = array_map(
            static fn ($c) => $c->Field,
            DB::select('SHOW COLUMNS FROM `'.$table.'`')
        );
    } catch (Throwable) {
        echo "skip (brak w mysql): {$table}\n";
        continue;
    }

    $columns = array_values(array_intersect(array_keys($rows[0]), $mysqlCols));
    $chunkSize = 100;
    $inserted = 0;
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $payload = [];
        foreach ($chunk as $row) {
            $item = [];
            foreach ($columns as $col) {
                $val = $row[$col];
                // SQLite bool/int jako string — JSON kolumny zostawiamy
                if (is_string($val) && ($val === '' && in_array($col, ['errors'], true) === false)) {
                    // empty string ok
                }
                $item[$col] = $val;
            }
            if ($table === 'products' && in_array('currency', $mysqlCols, true) && ! isset($item['currency'])) {
                $item['currency'] = 'PLN';
            }
            $payload[] = $item;
        }
        try {
            DB::table($table)->insert($payload);
            $inserted += count($payload);
        } catch (Throwable $e) {
            // wstawianie wiersz po wierszu przy kolizjach schematu
            foreach ($payload as $item) {
                try {
                    DB::table($table)->insert($item);
                    $inserted++;
                } catch (Throwable $e2) {
                    echo "  warn {$table} id=".($item['id'] ?? '?').': '.$e2->getMessage()."\n";
                }
            }
        }
    }

    echo "ok: {$table} → {$inserted} wierszy\n";
}

DB::statement('SET FOREIGN_KEY_CHECKS=1');

echo "\nGotowe. products=".DB::table('products')->count().", users=".DB::table('users')->count()."\n";
