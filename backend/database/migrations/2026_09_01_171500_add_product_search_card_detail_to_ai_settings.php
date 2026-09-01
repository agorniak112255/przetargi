<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', 'product_search_card_detail')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->string('product_search_card_detail', 16)
                ->default('long')
                ->after('match_concurrency');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'product_search_card_detail')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('product_search_card_detail');
        });
    }
};
