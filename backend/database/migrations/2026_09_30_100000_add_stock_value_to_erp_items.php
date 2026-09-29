<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zapasy: wartość księgowa netto partii (CDN.TwrZasoby.TwZ_KsiegowaNetto, suma wszystkich magazynów, PLN) i dzień
 * przyjęcia najstarszej partii leżącej na stanie. null = jeszcze nie pobrane (do pierwszej nocnej kopii po wdrożeniu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->decimal('stock_value', 14, 2)->nullable()->after('stock_total');
            $table->date('oldest_lot_at')->nullable()->after('stock_value');
            $table->index(['stock_total', 'last_sale_at']);
        });
    }

    public function down(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->dropIndex(['stock_total', 'last_sale_at']);
            $table->dropColumn(['stock_value', 'oldest_lot_at']);
        });
    }
};
