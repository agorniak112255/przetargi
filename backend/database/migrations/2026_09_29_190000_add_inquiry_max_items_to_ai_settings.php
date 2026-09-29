<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Najwięcej pozycji w jednym zapytaniu klienta — dotąd stała 8 w ClientInquiryService. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_settings') || Schema::hasColumn('ai_settings', 'inquiry_max_items')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('inquiry_max_items')->default(50)->after('catalog_search_limit');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_settings') || ! Schema::hasColumn('ai_settings', 'inquiry_max_items')) {
            return;
        }

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('inquiry_max_items');
        });
    }
};
