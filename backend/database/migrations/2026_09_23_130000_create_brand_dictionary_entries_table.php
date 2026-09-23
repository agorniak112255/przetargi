<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Słownik producentów i marek — tylko nadpisania. Producenci z katalogu (products.manufacturer) nie są tu
 * przepisywani: liczymy ich na bieżąco, więc import cennika nie wymaga synchronizacji, a słownik nie
 * rozjeżdża się z katalogiem. Wiersz powstaje wyłącznie wtedy, gdy ktoś podjął decyzję: marka należy do
 * producenta („Peltor” → 3M), słowo nigdy nie jest marką („wersja”), producenta nie wolno wyłuskiwać
 * z tekstu zapytania („BHP” — 3 karty producenta, słowo w nazwach 2273 kart).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_dictionary_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('term', 120);
            // compact(term): małe litery, polskie znaki zdjęte, tylko [a-z0-9] — jedno słowo, jeden wpis
            $table->string('term_key', 120)->unique();
            $table->string('kind', 20);
            // dla marki: kanoniczna wartość products.manufacturer, do której marka należy
            $table->string('manufacturer', 120)->nullable();
            $table->boolean('detect_in_query')->default(true);
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_dictionary_entries');
    }
};
