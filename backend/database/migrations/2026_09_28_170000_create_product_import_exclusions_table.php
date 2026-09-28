<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pozycje źródeł usuniętych kart, których import nie ma zakładać od nowa (decyzja użytkownika 28.09.2026: „Usuń
 * i pomijaj przy imporcie”). Wiersz = pozycja jednego źródła: konto B2B + remote_id powiązania albo cennik z pliku +
 * kod wiersza (match_kind „sku” — karta sprzed zapisu kodów wierszy, dopasowanie po SKU karty).
 *
 * Zakres dopasowania (scope_key): „b2b:{konto}” albo „file:{manufacturer_key}” — cennik z pliku po producencie, nie po
 * numerze wpisu: usunięcie wpisu cennika i ponowny import zakłada nowy wpis z nowym numerem, a blokada ma działać dalej
 * (dlatego price_list_id nullOnDelete). match_key = sha1(zakres + rodzaj + pozycja bez wielkości liter i akcentów) —
 * UNIQUE jednakowy w SQLite (testy) i MySQL. Przywrócenie ustawia restored_at (wiersz zostaje jako ślad decyzji).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_import_exclusions', function (Blueprint $table): void {
            $table->id();
            // jedno usunięcie karty = jedna grupa w podglądzie
            $table->uuid('deletion_id')->index();
            // źródło w zapisie jak card_redirects / product_identifiers („b2b:{id}”, „file:{cennik}”)
            $table->string('source_key', 40);
            $table->string('scope_key', 120);
            $table->string('match_kind', 10);
            $table->string('position_key', 64);
            $table->char('match_key', 40)->unique();
            $table->foreignId('b2b_account_id')->nullable()->constrained('b2b_accounts')->cascadeOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->string('manufacturer_key', 100)->nullable();
            // usunięta karta (bez klucza obcego — karty już nie ma)
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_sku');
            $table->string('product_name', 1000);
            $table->string('product_manufacturer', 100)->nullable();
            $table->json('product_snapshot')->nullable();
            // kod i nazwa pozycji u źródła (powiązanie B2B) albo etykieta rozmiaru wiersza pliku
            $table->string('remote_sku')->nullable();
            $table->string('position_label')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            // ile razy import pominął pozycję (przebiegi pełne, bez próbnych)
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();

            $table->index(['scope_key', 'restored_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_exclusions');
    }
};
