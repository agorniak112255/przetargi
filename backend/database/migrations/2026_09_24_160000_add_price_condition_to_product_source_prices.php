<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warunek ceny konta B2B (24.09.2026, Delta Plus: „*Cena jednostkowa za pełny karton tego samego rozmiaru i koloru”
 * przy kolumnie „Cena jeśli cały karton*”) — cena obowiązuje tylko przy zakupie pełnych kartonów. price_note
 * dosłownie ze źródła; price_carton_qty = ilość w kartonie, dla której obowiązuje cena (null przy przypisie =
 * rozmiary karty mają różne kartony). Tylko w slocie konta: to warunek jednego źródła. Osobno od order_* (ile wolno
 * zamówić) i od pack_qty (karton z cennika plikowego, bez warunku ceny).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->string('price_note', 255)->nullable()->after('order_varies');
            $table->decimal('price_carton_qty', 12, 4)->nullable()->after('price_note');
        });
    }

    public function down(): void
    {
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->dropColumn(['price_note', 'price_carton_qty']);
        });
    }
};
