<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', 'searxng_url')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->string('searxng_url', 255)
                ->nullable()
                ->after('search_engine');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'searxng_url')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('searxng_url');
        });
    }
};
