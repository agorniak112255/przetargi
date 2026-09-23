<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cennik sugerowany (decyzja użytkownika 23.09.2026): plik producenta z cenami sugerowanymi, bez cen zakupu
 * („ATG-sugerowany.xlsx” — ATG kupowane tylko przez Ardon). Taki cennik nie ma pierwszeństwa przed ceną zakupu
 * z konta B2B dystrybutora (ProductEffectivePrice::explain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->boolean('suggested_prices')->default(false)->after('manufacturer_key');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropColumn('suggested_prices');
        });
    }
};
