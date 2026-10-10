<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapa karty (10.10.2026): strona, z której importer cennika każe brać opis i zdjęcie karty — ustalona raz,
 * deterministycznie, przez kod importera (App\Services\PriceLists\Importers). Jeden wiersz na kartę (karta ma jeden
 * slot „file”). url null = importer nie przypiął (unresolved_reason). Decyzja człowieka NIE trafia tutaj — zostaje
 * w products.shop_source_url („Wskaż adres”) i zawsze wygrywa z mapą.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_source_pins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->string('importer_key', 60);
            $table->unsignedSmallInteger('importer_version');
            $table->string('url', 2000)->nullable();
            // manufacturer | supplier | shop
            $table->string('source_kind', 16)->nullable();
            $table->string('page_title', 255)->nullable();
            $table->string('image_url', 2000)->nullable();
            // exact_code | short_code | ean | model | parts_table
            $table->string('match_kind', 20)->nullable();
            $table->string('match_key', 120)->nullable();
            // linie „Etykieta: wartość” z pliku (rozmiar, kolor, EAN) — dane cennika tej karty
            $table->json('spec')->nullable();
            $table->json('evidence')->nullable();
            $table->string('unresolved_reason', 255)->nullable();
            $table->json('candidates')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index('price_list_id', 'product_source_pins_price_list_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_source_pins');
    }
};
