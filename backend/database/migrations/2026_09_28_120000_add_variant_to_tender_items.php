<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wariant karty w ofercie (kolor/rozmiar/kod z product_variants): karta z listą wariantów ma cenę
        // najniższą, oferta — kod, nazwę i cenę wybranego wariantu. Etykieta i kod to kopia z chwili wyboru,
        // żeby oferta przetrwała usunięcie wiersza wariantu; źródło „auto” (dopasowanie) albo „manual”.
        Schema::table('tender_items', function (Blueprint $table): void {
            $table->foreignId('main_variant_id')
                ->nullable()
                ->after('main_product_id')
                ->constrained('product_variants')
                ->nullOnDelete();
            $table->string('main_variant_label', 255)->nullable()->after('main_variant_id');
            $table->string('main_variant_sku', 255)->nullable()->after('main_variant_label');
            $table->string('main_variant_source', 16)->nullable()->after('main_variant_sku');
        });

        // Szukanie karty po kodzie wariantu („ARMEN-9007-1010-42” tylko w wierszu rozmiaru).
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex(['sku']);
        });

        Schema::table('tender_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('main_variant_id');
            $table->dropColumn(['main_variant_label', 'main_variant_sku', 'main_variant_source']);
        });
    }
};
