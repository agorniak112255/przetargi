<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waluta ceny w wierszu historii. Jedna karta ma wiersze z kilku źródeł (konto B2B w EUR, dystrybutor w PLN) —
 * bez waluty porównanie i opis ceny zgadywały ją z karty. Starsze wiersze zostają z null (waluta nieznana).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_history', function (Blueprint $table): void {
            $table->string('currency', 3)->nullable()->after('purchase_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_price_history', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
