<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stany z ERP XL odświeżane w ciągu dnia (erp:stock co 2 h w dni robocze, decyzja użytkownika 29.09.2026) niezależnie
 * od nocnej kopii towaru — karta pokazuje czas odczytu stanu, nie całej kopii.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->timestamp('stock_synced_at')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->dropColumn('stock_synced_at');
        });
    }
};
