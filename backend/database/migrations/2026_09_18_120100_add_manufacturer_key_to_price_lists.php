<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klucz producenta, po którym cennik jest odnajdywany przy kolejnej aktualizacji. Znormalizowany zapis
 * nazwy („ARTRA”, „Artra”, „artra.” → „artra”), żeby ta sama firma nie zakładała drugiego wpisu przez
 * wielkość liter albo kropkę. Nazwy różniące się treścią („ARTRA” vs „ARTRA SAFETY”) zostają osobno —
 * łączenie ich na wyczucie scaliłoby katalogi dwóch dostawców bez pytania.
 *
 * Bez unikalności na tym etapie: nakłada ją dopiero zwinięcie istniejących wpisów, żeby migracja
 * schematu nie wywracała się na danych, które dopiero mają zostać scalone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->string('manufacturer_key', 100)->nullable()->after('manufacturer');
            $table->index('manufacturer_key');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropIndex(['manufacturer_key']);
            $table->dropColumn('manufacturer_key');
        });
    }
};
