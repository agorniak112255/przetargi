<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Producenci w cenniku B2B (23.09.2026): przy koncie dystrybutora wielu marek użytkownik decyduje, czy z tego
 * cennika brać cenę i opis wyrobów danego producenta. Brak wiersza = oba znaczniki włączone (zachowanie sprzed
 * zmiany); wiersz powstaje tylko dla wyłączeń.
 *
 * b2b_product_links.manufacturer — producent w brzmieniu tego konta. Karta wspólna dla dwóch kont trzyma nazwę
 * z ostatniego przebiegu, a reguła konta ma trafiać w nazwę, którą podaje ono samo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_account_manufacturer_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('b2b_account_id')->constrained('b2b_accounts')->cascadeOnDelete();
            $table->string('manufacturer', 100);
            // PriceList::manufacturerKey — wielkość liter, kropki i odstępy to ten sam producent
            $table->string('manufacturer_key', 100);
            $table->boolean('take_price')->default(true);
            $table->boolean('take_description')->default(true);
            $table->timestamps();

            $table->unique(['b2b_account_id', 'manufacturer_key'], 'b2b_mfr_rules_account_key_unique');
        });

        Schema::table('b2b_product_links', function (Blueprint $table): void {
            $table->string('manufacturer', 100)->nullable()->after('remote_name');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_product_links', function (Blueprint $table): void {
            $table->dropColumn('manufacturer');
        });
        Schema::dropIfExists('b2b_account_manufacturer_rules');
    }
};
