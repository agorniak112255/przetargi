<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rozmiary w różnych cenach jednej karty (decyzja użytkownika 28.09.2026, zamiast karty na każdą cenę):
        // wiersz „size” = rozmiar z ceną konta, karta ma cenę najniższego rozmiaru. „version” = wersje Sign Project
        // (karta z ceną 0). source rozmiaru = slot konta „b2b:{id}”, wersji — „b2b:{łącznik}”.
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('kind', 10)->default('version')->after('product_id');
            // kod rozmiaru u dostawcy dosłownie (jak b2b_product_links.remote_sku)
            $table->string('sku', 255)->nullable()->after('remote_id');
            // dostępność rozmiaru dosłownie ze źródła; null = źródło jej nie podaje
            $table->string('availability', 255)->nullable()->after('unit');
            $table->index(['product_id', 'kind', 'removed_at']);
        });

        // Najwyższa cena rozmiaru przy slocie z najniższą — idzie za wygrywającym slotem („cena od … do …”);
        // null = rozmiary w jednej cenie albo slot bez rozmiarów.
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->decimal('size_price_max', 12, 2)->nullable()->after('purchase_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_source_prices', function (Blueprint $table): void {
            $table->dropColumn('size_price_max');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'kind', 'removed_at']);
            $table->dropColumn(['kind', 'sku', 'availability']);
        });
    }
};
