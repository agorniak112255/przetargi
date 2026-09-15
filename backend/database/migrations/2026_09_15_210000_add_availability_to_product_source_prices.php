<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dostępność u dostawcy w slocie ceny konta B2B (15.09.2026, np. UVEX „Dostępny” / „Na zamówienie”) — tekst
 * dosłownie ze źródła, bez normalizacji. Tylko w slocie konta, nie na karcie: to informacja o jednym źródle.
 * null = źródło jej nie podaje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->text('availability')->nullable()->after('pack_qty');
        });
    }

    public function down(): void
    {
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->dropColumn('availability');
        });
    }
};
