<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skąd jest products.category (decyzja użytkownika 22.09.2026): kategoria sklepu nadana automatycznie
 * (przepisanie na drzewo Presty z nazwy albo opisu) NIE jest dowodem rodzaju wyrobu. Bez znacznika rodzina
 * wyrobu czytała kategorię, którą sama wcześniej wybrała — zła rodzina podtrzymywała się w kółko.
 *
 * Istniejące wiersze zostają z null = pochodzenie nieznane: z samej bazy nie da się odróżnić ścieżki drzewa
 * wybranej ręcznie w panelu od nadanej automatem, więc nic tu nie zgadujemy. Oznaczenie zastanych kart robi
 * osobno products:category-provenance (domyślnie podgląd).
 *
 * category_evidence (przegląd 22.09.2026): category zostaje ścieżką drzewa sklepu (eksport do Presty, grupa w
 * panelu), a kategoria ze źródła — kolumna cennika, kategoria z formularza importu, kategoria z karty B2B — ląduje
 * tu i nie znika, gdy automat przepisze category na ścieżkę drzewa. Bez tego import, który od razu zamienia
 * kategorię cennika na ścieżkę dobraną z nazwy, gubił jedyny dowód rodzaju wyrobu. Istniejące wiersze: null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // import | b2b | manual | presta_rewrite | presta_map; null = nieznane (wiersze sprzed znacznika)
            $table->string('category_source', 20)->nullable()->after('category');
            // kategoria dosłownie ze źródła (cennik, formularz importu, B2B); null = brak dowodu
            $table->string('category_evidence', 255)->nullable()->after('category_source');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['category_source', 'category_evidence']);
        });
    }
};
