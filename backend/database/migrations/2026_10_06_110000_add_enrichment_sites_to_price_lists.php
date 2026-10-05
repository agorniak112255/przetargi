<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Źródła opisów per cennik z pliku (zakładka Cenniki → „Z pliku”, prośba użytkownika 05.10.2026): uporządkowana lista
 * hostów (kolejność = ważność) i tryb — 'first' (strony cennika, potem dotychczasowa hierarchia) albo 'only' (tylko
 * producent i strony cennika). Strony cennika działają wyłącznie bez karty producenta w puli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->json('enrichment_sites')->nullable()->after('suggested_prices');
            $table->string('enrichment_sites_mode', 10)->default('first')->after('enrichment_sites');
            $table->timestamp('enrichment_sites_updated_at')->nullable()->after('enrichment_sites_mode');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropColumn(['enrichment_sites', 'enrichment_sites_mode', 'enrichment_sites_updated_at']);
        });
    }
};
