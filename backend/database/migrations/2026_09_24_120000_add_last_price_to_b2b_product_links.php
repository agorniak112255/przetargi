<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cena każdej pozycji konta B2B przy jej powiązaniu (plan łączenia kart, krok 3, 24.09.2026). Slot ceny konta jest jeden
 * na kartę, a na karcie modelu po połączeniu siedzi kilka pozycji tego konta (3M: rozmiary 6100/6200/6300 na jednej
 * karcie) — cenę zapisywała tylko pierwsza pozycja przebiegu, różnica pozostałych zostawała w dzienniku. Tu każda
 * pozycja ma ostatnią cenę zakupu, walutę i datę odczytu; null = pozycja jeszcze bez ceny w tym zapisie (np. łącznik
 * treści albo cena producenta wyłączona w oknie „Producenci”).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_product_links', function (Blueprint $table): void {
            $table->decimal('last_purchase_price', 12, 2)->nullable()->after('last_seen_at');
            $table->string('last_currency', 3)->nullable()->after('last_purchase_price');
            $table->timestamp('last_price_at')->nullable()->after('last_currency');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_product_links', function (Blueprint $table): void {
            $table->dropColumn(['last_purchase_price', 'last_currency', 'last_price_at']);
        });
    }
};
