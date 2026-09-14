<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wersje jednej karty (np. znak w formacie × podłożu) z ceną konta u dostawcy. Karta z wersjami ma cenę 0
        // („brak ceny”) — ceny są tylko tutaj, żeby dopasowanie i oferta nie brały ceny najtańszej naklejki.
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('b2b_account_id')->nullable()->constrained('b2b_accounts')->nullOnDelete();
            $table->string('source', 40);
            $table->string('remote_id', 64);
            $table->string('label', 255);
            $table->json('attributes')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('list_price_net', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('source_url', 2000)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('price_checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'remote_id']);
            $table->index(['product_id', 'removed_at']);
            $table->index(['source', 'price_checked_at']);
        });

        // Osobno od product_price_history: tamta tabela opisuje cenę karty i czyta ją wiele miejsc bez filtra.
        Schema::create('product_variant_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('b2b_sync_run_id')->nullable()->constrained('b2b_sync_runs')->nullOnDelete();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('list_price_net', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('source', 64);
            $table->timestamps();

            // jawna nazwa: domyślna (66 znaków) przekracza limit 64 w MySQL
            $table->index(['product_variant_id', 'created_at'], 'pv_price_history_variant_created_idx');
        });

        // Formaty/podłoża wersji do wyszukiwania (indeks tekstowy i wektor), bez mieszania z opisem źródła.
        Schema::table('products', function (Blueprint $table): void {
            $table->text('variant_summary')->nullable()->after('description');
        });

        Schema::table('b2b_sync_runs', function (Blueprint $table): void {
            $table->string('progress_unit', 10)->default('products')->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('progress_unit');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('variant_summary');
        });

        Schema::dropIfExists('product_variant_price_history');
        Schema::dropIfExists('product_variants');
    }
};
