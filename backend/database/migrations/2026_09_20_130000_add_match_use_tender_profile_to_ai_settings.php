<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Przełącznik: czy dopasowanie pozycji SIWZ ma jechać własnym trybem wyszukiwania (profil modelu
 * „Dopasowanie pozycji SIWZ” i jego reguły), czy — jak dotąd — trybem wyszukiwarki. Domyślnie
 * wyłączony, więc migracja niczego nie zmienia w działaniu.
 */
return new class extends Migration
{
    private const COLUMN = 'match_use_tender_profile';

    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', self::COLUMN)) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->boolean(self::COLUMN)->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', self::COLUMN)) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
