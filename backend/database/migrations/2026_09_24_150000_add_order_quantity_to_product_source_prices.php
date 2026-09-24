<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warunek zamawiania u dostawcy w slocie ceny konta B2B (24.09.2026, UVEX: pole ilości koszyka min="10" step="10"
 * = zamówienie tylko po 10 szt.). Osobno od pack_qty — ta to liczba sztuk w kartonie z cennika i nie mówi, ile
 * wolno zamówić. Tylko w slocie konta: to warunek jednego źródła. order_step_qty null przy podanym minimum = sklep
 * nie ogranicza kroku (step="any"); wszystkie trzy null = źródło warunku nie podaje. order_varies = rozmiary jednej
 * karty mają różne warunki (min i step null, szczegóły na karcie dostawcy) — widoki mówią „zależy od rozmiaru”.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->decimal('order_min_qty', 12, 4)->nullable()->after('availability');
            $table->decimal('order_step_qty', 12, 4)->nullable()->after('order_min_qty');
            $table->string('order_unit', 20)->nullable()->after('order_step_qty');
            $table->boolean('order_varies')->default(false)->after('order_unit');
        });
    }

    public function down(): void
    {
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->dropColumn(['order_min_qty', 'order_step_qty', 'order_unit', 'order_varies']);
        });
    }
};
