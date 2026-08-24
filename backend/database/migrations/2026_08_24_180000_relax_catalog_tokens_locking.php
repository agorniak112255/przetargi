<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Masowe wstawianie tokenów zawieszało całą MariaDB: automatyczne przeliczanie
 * statystyk trzymało mutex DICT_SYS, a klucz obcy dokładał operacje na słowniku.
 * Indeks tokenów jest odtwarzalny, więc rezygnujemy z obu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE catalog_page_tokens DROP FOREIGN KEY IF EXISTS catalog_page_tokens_catalog_page_id_foreign');
        DB::statement('ALTER TABLE catalog_page_tokens STATS_PERSISTENT=0, STATS_AUTO_RECALC=0');
        DB::statement('ALTER TABLE catalog_pages STATS_AUTO_RECALC=0');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE catalog_page_tokens STATS_PERSISTENT=DEFAULT, STATS_AUTO_RECALC=DEFAULT');
        DB::statement('ALTER TABLE catalog_pages STATS_AUTO_RECALC=DEFAULT');
    }
};
