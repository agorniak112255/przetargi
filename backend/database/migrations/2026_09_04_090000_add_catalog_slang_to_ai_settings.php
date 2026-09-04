<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', 'catalog_slang')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->json('catalog_slang')->nullable()->after('product_search_card_detail');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'catalog_slang')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('catalog_slang');
        });
    }
};
