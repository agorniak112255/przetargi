<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normy odczytane z karty wyrobu u JEGO producenta — pary „norma: poziom” dosłownie ze źródła, razem
 * z adresem strony i datą odczytu (App\Support\ManufacturerNormFacts).
 *
 * PO CO: poziomy EN 388 brały się dotąd z opisu wzbogacanego ze sklepów, bo w hierarchii źródeł opisu sklep
 * stoi nad witryną producenta. Na 54 kartach ATG dało to 25 kart z kodem innym niż u producenta, a kod EN 388
 * decyduje o dopasowaniu do wymagania przetargu. Kolumna jest osobnym, nadrzędnym źródłem tych poziomów —
 * nie zastępuje ani cennika (price_list_attributes), ani parametrów ręcznych (manual_specs), ani opisu.
 *
 * Zapisuje ją wyłącznie synchronizacja B2B z łącznikiem witryny producenta tej marki; wzbogacanie opisu
 * jej nie dotyka, więc ponowny przebieg ze sklepu nie może zepsuć poziomów z powrotem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('manufacturer_norms')->nullable()->after('manual_specs');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('manufacturer_norms');
        });
    }
};
