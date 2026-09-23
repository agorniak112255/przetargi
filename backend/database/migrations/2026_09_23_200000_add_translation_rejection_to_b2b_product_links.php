<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odrzucone tłumaczenie tekstu źródła (TranslateB2bProductTextJob). Model tłumaczy z temperaturą 0, więc ten sam tekst
 * odrzucony raz był odrzucany przy każdym przebiegu (23.09.2026: 7 kart Bolle, 7 zapytań do modelu na przebieg).
 * translation_rejected_hash = odcisk wysłanego tekstu — nowy tekst u dostawcy ma inny odcisk i znów idzie do tłumaczenia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_product_links', function (Blueprint $table): void {
            $table->char('translation_rejected_hash', 40)->nullable()->after('source_description_hash');
            $table->string('translation_rejected_reason', 500)->nullable()->after('translation_rejected_hash');
            $table->timestamp('translation_rejected_at')->nullable()->after('translation_rejected_reason');
        });
    }

    public function down(): void
    {
        Schema::table('b2b_product_links', function (Blueprint $table): void {
            $table->dropColumn(['translation_rejected_hash', 'translation_rejected_reason', 'translation_rejected_at']);
        });
    }
};
