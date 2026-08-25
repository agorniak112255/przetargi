<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', 'model_profiles')) {
            return;
        }

        // Profile trzymają klucze API, więc cała tablica idzie do bazy zaszyfrowana
        // (cast encrypted:array) — stąd zwykły text zamiast kolumny json.
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->text('model_profiles')->nullable()->after('embedding_cloud_api_key');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'model_profiles')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('model_profiles');
        });
    }
};
