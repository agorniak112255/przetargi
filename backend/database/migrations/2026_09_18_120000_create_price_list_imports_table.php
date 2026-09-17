<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dziennik aktualizacji cennika — jeden wiersz na przebieg, wzorem b2b_sync_runs.
 *
 * Wpis w Cennikach ma być jeden na producenta, niezależnie od liczby aktualizacji i od tego, czy dane
 * przyszły z pliku, czy z konta B2B. Raport pojedynczego przebiegu (co doszło, co się zmieniło, co
 * pominięto, jakich kart dotyczył) nie może się przez to zgubić — i to jest właśnie ta tabela.
 *
 * product_ids trzyma zakres przebiegu: bez tego „cofnij tę aktualizację” nie miałoby czego cofać,
 * a dotąd tę rolę pełniło to, że każdy import zakładał osobny wpis cennika.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->string('source', 20)->default('file');
            $table->string('version', 100)->nullable();
            $table->string('original_filename')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('products_created')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('prices_changed')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->json('errors')->nullable();
            $table->json('price_changes')->nullable();
            $table->json('updated_products')->nullable();
            $table->json('skipped_details')->nullable();
            $table->json('product_ids')->nullable();
            // wpis cennika, z którego ten przebieg powstał przy zwijaniu — ślad do odtworzenia stanu sprzed
            $table->unsignedBigInteger('legacy_price_list_id')->nullable();
            $table->timestamps();

            $table->index(['price_list_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_imports');
    }
};
