<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pochodzenie pliku przetargu: source = 'ezamowienia' (pobrany z platformy e-Zamówienia z ogłoszenia) albo null
 * (wgrany ręcznie — jak wszystkie dotychczasowe), source_url = adres, z którego pobrano plik, source_ref = identyfikator
 * dokumentu w źródle (objectId e-Zamówień). Kolumny tylko dopisywane, istniejące wiersze bez zmian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_documents', function (Blueprint $table): void {
            $table->string('source', 32)->nullable()->after('extension');
            $table->string('source_ref', 191)->nullable()->after('source');
            $table->string('source_url', 500)->nullable()->after('source_ref');
        });
    }

    public function down(): void
    {
        Schema::table('tender_documents', function (Blueprint $table): void {
            $table->dropColumn(['source', 'source_ref', 'source_url']);
        });
    }
};
