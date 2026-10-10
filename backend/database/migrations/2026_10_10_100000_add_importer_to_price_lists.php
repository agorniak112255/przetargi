<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cenniki z plików na wzór kont B2B (10.10.2026): cennik zakładany formularzem przed plikiem, importer per cennik
 * (klasa w kodzie, klucz z PriceListImporterRegistry — ten sam lokalnie i na produkcji) i polityka źródeł opisu.
 * source_policy null = dawny sposób (wszystkie stare cenniki bez zmian).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->string('importer_key', 60)->nullable()->after('enrichment_sites_updated_at');
            $table->string('source_policy', 16)->nullable()->after('importer_key');
            $table->text('importer_notes')->nullable()->after('source_policy');
            $table->index('importer_key', 'price_lists_importer_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropIndex('price_lists_importer_key_index');
            $table->dropColumn(['importer_key', 'source_policy', 'importer_notes']);
        });
    }
};
