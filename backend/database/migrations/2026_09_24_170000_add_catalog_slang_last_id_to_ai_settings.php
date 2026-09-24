<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Najwyższy numer wpisu żargonu, jaki słownik już nadał. Wpisy żyją w liście JSON
 * `catalog_slang`, więc bez licznika nowy wpis dostałby numer właśnie usuniętego.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', 'catalog_slang_last_id')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->unsignedInteger('catalog_slang_last_id')->default(0)->after('catalog_slang');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'catalog_slang_last_id')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('catalog_slang_last_id');
        });
    }
};
