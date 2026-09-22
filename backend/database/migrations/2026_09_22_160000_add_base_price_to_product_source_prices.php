<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cena z cennika bazowego dostawcy obok ceny konta (UVEX: xlsx „Cennik do pobrania”) — do wykrywania cen
 * specjalnych. catalog_price_net zostaje bez zmian: idzie do sklepu (Presta) i do wycen „katalogowa + marża”,
 * więc cena bazowa ma własne kolumny. Kategoria i kod wiersza cennika są zapisane, żeby zmiana progów rabatu
 * w panelu przeliczała standard_discount_percent od razu, bez czekania na synchronizację.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_source_prices', function (Blueprint $table): void {
            // cena netto z cennika bazowego dostawcy (null = brak wiersza w cenniku albo kategoria pominięta)
            $table->decimal('base_price_net', 12, 2)->nullable()->after('discount_percent');
            // kategoria wiersza cennika bazowego (UVEX: nazwa arkusza) — wejście do reguł rabatu konta
            $table->string('base_price_category', 100)->nullable()->after('base_price_net');
            // kod wiersza cennika bazowego, dosłownie z pliku
            $table->string('base_price_code', 64)->nullable()->after('base_price_category');
            // skąd cena bazowa: plik, arkusz, nazwa wiersza, data pobrania
            $table->string('base_price_source', 255)->nullable()->after('base_price_code');
            // rabat standardowy z reguły konta (null = żadna reguła nie pasuje — brak oceny ceny)
            $table->decimal('standard_discount_percent', 5, 2)->nullable()->after('base_price_source');
        });
    }

    public function down(): void
    {
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->dropColumn([
                'base_price_net',
                'base_price_category',
                'base_price_code',
                'base_price_source',
                'standard_discount_percent',
            ]);
        });
    }
};
