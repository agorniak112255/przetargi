<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Druga cena konta B2B — cena przy pełnym kartonie (decyzja właściciela 01.10.2026, BIG Arbeitsschutz: „ab 12 Paar
 * 1,15 €, ab 96 Paar 0,92 €”). Cena zakupu (purchase_price) zostaje ceną od minimum zamówienia, obowiązującą przy
 * każdej ilości; carton_price_net to niższa cena przy zakupie pełnego kartonu carton_qty sztuk/par. Tylko w slocie konta
 * i w wierszach rozmiarów — to cena jednego źródła. Osobno od price_note/price_carton_qty (Delta Plus: cena konta
 * obowiązuje WYŁĄCZNIE przy pełnym kartonie) i od pack_qty (karton z cennika plikowego, bez ceny).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->decimal('carton_price_net', 12, 2)->nullable()->after('price_carton_qty');
            $table->decimal('carton_qty', 12, 4)->nullable()->after('carton_price_net');
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('carton_price_net', 12, 2)->nullable()->after('list_price_net');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('carton_price_net');
        });
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->dropColumn(['carton_price_net', 'carton_qty']);
        });
    }
};
