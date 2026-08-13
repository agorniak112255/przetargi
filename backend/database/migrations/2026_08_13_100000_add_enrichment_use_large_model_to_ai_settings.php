<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', 'enrichment_use_large_model')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->boolean('enrichment_use_large_model')
                ->default(false)
                ->after('enrichment_model');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'enrichment_use_large_model')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('enrichment_use_large_model');
        });
    }
};
