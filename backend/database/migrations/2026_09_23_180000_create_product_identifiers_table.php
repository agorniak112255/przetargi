<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identyfikatory wyrobu z każdego źródła ceny (decyzja użytkownika 23.09.2026): EAN, kod dostawcy, kod producenta,
 * kod modelu — z pochodzeniem, żeby później dało się połączyć kartę dystrybutora z kartą producenta. Dziś karta ma
 * jedno SKU i jeden EAN, a ten sam wyrób od producenta i od dystrybutora to dwie karty z różnymi kodami.
 *
 * Wiersz = jeden identyfikator jednej pozycji (rozmiaru, wersji) jednego źródła. product_id to bieżące wskazanie
 * pozycji — synchronizacja przepina je razem z powiązaniem. Wartość dosłownie ze źródła, `normalized` do wyszukiwania
 * (null, gdy wartości nie da się sprowadzić, np. EAN ze złą sumą kontrolną).
 *
 * source_key („b2b:{id}”, „file:{id}”) jest NOT NULL i to on jest w UNIQUE — konto albo cennik z NULL nie
 * blokowałby duplikatów w MySQL (jak w product_source_prices).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('source_key', 40);
            // jak product_shop_cards: bez konta albo cennika pochodzenie wiersza przepada, więc i wiersz
            $table->foreignId('b2b_account_id')->nullable()->constrained('b2b_accounts')->cascadeOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->cascadeOnDelete();
            $table->string('position_key', 64);
            $table->string('type', 20);
            $table->string('value', 64);
            $table->string('normalized', 64)->nullable();
            $table->string('source_field', 100)->nullable();
            $table->string('variant_label', 120)->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('brand_key', 100)->nullable();
            $table->foreignId('b2b_sync_run_id')->nullable()->constrained('b2b_sync_runs')->nullOnDelete();
            $table->foreignId('price_list_import_id')->nullable()->constrained('price_list_imports')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_key', 'position_key', 'type', 'value'], 'product_identifiers_source_unique');
            $table->index(['type', 'normalized']);
            $table->index(['brand_key', 'normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_identifiers');
    }
};
