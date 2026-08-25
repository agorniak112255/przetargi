<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->mediumText('search_blob')->nullable()->after('enrichment_payload');
            $table->string('search_blob_hash', 64)->nullable()->after('search_blob');
            $table->string('ppe_family', 24)->nullable()->after('search_blob_hash');
            $table->index('ppe_family');
        });

        // SQLite (testy) nie zna FULLTEXT — tam to samo zapytanie schodzi na LIKE
        // po tej samej kolumnie, więc wyniki różni tylko ranking, nie zakres.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products ADD FULLTEXT products_search_blob_fulltext (search_blob)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products DROP INDEX products_search_blob_fulltext');
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['ppe_family']);
            $table->dropColumn(['search_blob', 'search_blob_hash', 'ppe_family']);
        });
    }
};
