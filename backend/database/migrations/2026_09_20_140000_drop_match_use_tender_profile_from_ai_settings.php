<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Przełącznik osobnego trybu dopasowania SIWZ został wycofany razem z tym trybem: wyszukiwanie AI
 * ma działać tak samo wszędzie. Migracja dodająca pole zostaje w repozytorium, a ta je usuwa —
 * wynik jest ten sam na bazie, która zdążyła dostać pole, i na takiej, która go nie widziała.
 */
return new class extends Migration
{
    private const COLUMN = 'match_use_tender_profile';

    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', self::COLUMN)) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', self::COLUMN)) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->boolean(self::COLUMN)->default(false);
        });
    }
};
