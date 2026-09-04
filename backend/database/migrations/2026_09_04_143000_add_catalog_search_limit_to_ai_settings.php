<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', 'catalog_search_limit')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('catalog_search_limit')
                ->default(40)
                ->after('match_concurrency');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'catalog_search_limit')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('catalog_search_limit');
        });
    }
};
