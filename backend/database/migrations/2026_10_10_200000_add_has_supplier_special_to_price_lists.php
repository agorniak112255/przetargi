<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cennik z pliku z cenami specjalnymi dostawcy (SECURA, decyzja właściciela 10.10.2026: „40% s.dystryb.” = cena
 * specjalna, „21%” = cena normalna). Znacznik ustawia import, gdy zapisze choć jeden slot „file” z oceną ceny
 * specjalnej; import go nie zdejmuje. Kolejny import takiego cennika bez kolumny ceny normalnej jest odrzucany —
 * cena specjalna weszłaby jako zwykła cena zakupu, widoczna dla wszystkich.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->boolean('has_supplier_special')->default(false)->after('suggested_prices');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropColumn('has_supplier_special');
        });
    }
};
