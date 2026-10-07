<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Powód, dla którego opis karty czeka na przegląd handlowca (Product::REVIEW_*), i od kiedy czeka. Kolumna poza
 * ProductSearchBlob::SOURCE_COLUMNS — jej zmiana nie przelicza indeksu ani wektora. Historia decyzji
 * w product_description_versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // jak przy indeksie statusu (2026_09_25_160000): ALTER czeka na blokadę tabeli najwyżej 10 s, potem błąd
        // i wdrożenie wystarczy powtórzyć — bez tego trwający import cennika wstrzymałby całą aplikację
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        if ($mysql) {
            DB::statement('SET SESSION lock_wait_timeout = 10');
        }
        try {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('review_reason', 32)->nullable()->index();
                $table->timestamp('review_since')->nullable();
            });
        } finally {
            if ($mysql) {
                DB::statement('SET SESSION lock_wait_timeout = DEFAULT');
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['review_reason']);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['review_reason', 'review_since']);
        });
    }
};
