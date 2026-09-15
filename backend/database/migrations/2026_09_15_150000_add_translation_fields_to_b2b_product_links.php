<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tłumaczenie tekstów z importu B2B (15.09.2026):
 * - source_description_hash — sha1 opisu ze źródła, z którego powstało tłumaczenie na karcie; niepusty TYLKO wtedy,
 *   gdy opis karty jest tłumaczeniem (import zapisujący tekst źródła go zeruje);
 * - remote_name — nazwa produktu u dostawcy przy ostatnim przebiegu (oryginał przetłumaczonej nazwy, proweniencja).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_product_links', function (Blueprint $table): void {
            $table->char('source_description_hash', 40)->nullable()->after('description_hash');
            $table->string('remote_name', 1000)->nullable()->after('remote_sku');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_product_links', function (Blueprint $table): void {
            $table->dropColumn(['source_description_hash', 'remote_name']);
        });
    }
};
