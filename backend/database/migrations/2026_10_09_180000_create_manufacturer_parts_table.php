<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela części ze stron producenta (09.10.2026, Coba: `<table id="parts-table">` na coba.com/pl/produkt/<slug>) —
 * wiersz = numer części z rozmiarem, kolorem, wagą i zdjęciem. Przypięcie karty cennika do wiersza
 * (App\Services\Enrichment\PartsTable) zamiast wyszukiwarki; wiersze zapisuje tylko products:parts-table --refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturer_parts', function (Blueprint $table): void {
            $table->id();
            $table->string('brand_key', 40);
            $table->string('page_url', 500);
            // sha1 adresu małymi literami — adres do 500 znaków nie mieści się w indeksie unikalnym MariaDB
            $table->char('page_url_hash', 40);
            $table->string('page_title', 300)->nullable();
            // kod bez separatorów, wielkimi literami (AF010706)
            $table->string('part_code', 64);
            // kod dosłownie z tabeli
            $table->string('part_label', 64);
            $table->string('size_label', 120)->nullable();
            $table->string('colour_label', 120)->nullable();
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->string('model_image_url', 2000)->nullable();
            $table->string('style_image_url', 2000)->nullable();
            $table->boolean('has_styles')->default(false);
            $table->char('page_sha', 40);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_key', 'page_url_hash', 'part_code'], 'manufacturer_parts_brand_page_part_unique');
            $table->index(['brand_key', 'part_code'], 'manufacturer_parts_brand_part_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturer_parts');
    }
};
