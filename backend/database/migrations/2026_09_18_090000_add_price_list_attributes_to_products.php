<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parametry wyrobu wypisane wprost w cenniku dostawcy (klasa ochrony, normy, rozmiar, kolor, materiał).
 * Trzymamy je osobno od enrichment_payload.attributes, bo to dwie różne rzeczy: tamto jest wynikiem
 * czytania stron przez model i bywa błędne, a to jest cytatem z dokumentu producenta, który ma datę
 * obowiązywania. Osobna kolumna pozwala też przeżyć reset wzbogacania, który payload czyści.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('price_list_attributes')->nullable()->after('norms');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('price_list_attributes');
        });
    }
};
