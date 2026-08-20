<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', 'match_concurrency')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('match_concurrency')
                ->default(4)
                ->after('enrichment_batch_limit');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'match_concurrency')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('match_concurrency');
        });
    }
};
