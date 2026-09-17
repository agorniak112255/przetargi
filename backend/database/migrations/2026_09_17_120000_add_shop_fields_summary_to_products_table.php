<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tekstowe podsumowanie kart wyrobu u dostawców (product_shop_cards) na samej karcie — żeby dane ze sklepu
 * były widoczne dla wyszukiwania leksykalnego (products.search_blob) i wektorowego, mimo że nie są opisem.
 *
 * Powód: od etapu 2 łączniki nie wklejają już tabelek do products.description, a to właśnie stamtąd
 * wyszukiwarka, normalizator atrybutów BHP i reranker czerpały dziś normy i parametry (u Protektu opis to
 * praktycznie sama specyfikacja). Kolumna trzyma te same wiersze osobno, więc dopasowanie nic nie traci,
 * a opis pozostaje opisem.
 *
 * Kolumna jest wyliczana z product_shop_cards (B2bCatalogSync), nie podawana z zewnątrz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('shop_fields_summary')->nullable()->after('variant_summary');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('shop_fields_summary');
        });
    }
};
