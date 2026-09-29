<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ekran „Powiązania z ERP XL”: wynik ostatniego łączenia na towarze (dlaczego nie połączono — kod bez karty, brak kodu,
 * do decyzji) i ostatni dostawca z PZ do filtra. Wynik zapisuje erp:match i decyzje na ekranie; wcześniej był tylko
 * w raporcie CSV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            // auto | confirmed | suggested | ambiguous | name_suggested | no_match | family_conflict | no_code | rejected
            $table->string('match_outcome', 20)->nullable()->after('removed_at');
            // kod z XL, którym łączenie próbowało (także przy „kod bez karty”)
            $table->string('match_value', 150)->nullable()->after('match_outcome');
            $table->string('last_supplier', 100)->nullable()->after('last_sale_at');

            $table->index('match_outcome');
            $table->index('stock_trade');
            $table->index('last_sale_at');
        });
    }

    public function down(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->dropIndex(['match_outcome']);
            $table->dropIndex(['stock_trade']);
            $table->dropIndex(['last_sale_at']);
            $table->dropColumn(['match_outcome', 'match_value', 'last_supplier']);
        });
    }
};
