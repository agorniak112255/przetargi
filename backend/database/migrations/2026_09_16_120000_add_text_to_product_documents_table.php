<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tekst odczytany z pliku (karta techniczna z panelu B2B). Trzymany przy dokumencie, więc kolejne pobranie
     * cennika nie ściąga PDF-ów jeszcze raz, a treść zostaje przy źródle — do wglądu i do wyszukiwania.
     * null = tekstu nie próbowano odczytać, '' = plik nie ma warstwy tekstowej (skan).
     */
    public function up(): void
    {
        Schema::table('product_documents', function (Blueprint $table): void {
            $table->longText('text')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('product_documents', function (Blueprint $table): void {
            $table->dropColumn('text');
        });
    }
};
