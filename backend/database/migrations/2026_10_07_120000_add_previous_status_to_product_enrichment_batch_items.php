<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stan karty sprzed dodania do partii — anulowanie partii przywraca go zamiast zapisywać na karcie „błąd: Anulowano”
 * (Coba 07.10.2026: 137 kart z gotowym albo żadnym opisem pokazywało się jako błąd po przerwanej partii).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_enrichment_batch_items', function (Blueprint $table): void {
            $table->string('previous_status', 20)->nullable()->after('message');
            $table->text('previous_error')->nullable()->after('previous_status');
        });
    }

    public function down(): void
    {
        Schema::table('product_enrichment_batch_items', function (Blueprint $table): void {
            $table->dropColumn(['previous_status', 'previous_error']);
        });
    }
};
