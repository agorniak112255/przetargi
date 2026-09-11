<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * catalog_pages ma STATS_AUTO_RECALC=0 od czasu, gdy była pusta — optymalizator wciąż
 * widział 0 wierszy i czytał karty po id pełnym skanem (1,3 s zamiast 13 ms przy
 * 950 tys. wierszy, kilka razy na produkt). Jednorazowe przeliczenie; dalej statystyki
 * odświeża indeksowanie (CatalogSitemapIndexer::refreshTableStatistics).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ANALYZE TABLE catalog_pages');
        DB::statement('ANALYZE TABLE catalog_page_tokens');
    }

    public function down(): void
    {
        // przeliczenie statystyk nie ma czego cofać
    }
};
