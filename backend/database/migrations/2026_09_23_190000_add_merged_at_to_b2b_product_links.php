<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Znacznik scalenia rozmiarów (23.09.2026): merged_at — kiedy ProductSizeMergeService przeniósł powiązanie ze
 * scalanej karty na docelową. Karta z takim powiązaniem konta przyjmuje w synchronizacji B2B kolejne grupy rozmiarów
 * tego konta zamiast oddawać je na nowe karty. Powiązania przeniesione przed tą zmianą znacznika nie mają.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_product_links', function (Blueprint $table): void {
            $table->timestamp('merged_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_product_links', function (Blueprint $table): void {
            $table->dropColumn('merged_at');
        });
    }
};
