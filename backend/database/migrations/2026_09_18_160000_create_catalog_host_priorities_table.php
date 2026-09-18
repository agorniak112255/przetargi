<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ręczna ranga domeny (1 = najwyżej, 100 = najniżej) używana przy wyborze źródła opisu karty.
 *
 * Osobna tabela, a nie kolumna w `catalog_search_sites`, bo lista domen w administracji skleja
 * hosty z pięciu źródeł (config, strony producentów, indeks katalogu), a wiersz w
 * `catalog_search_sites` ma tylko garstka domen dodanych ręcznie. Założenie takiego wiersza po to,
 * żeby było gdzie zapisać rangę, zmieniłoby domenę producenta w „sklep” w oczach wzbogacania
 * (CatalogSearchSite::allHosts() zasila listę sklepów).
 *
 * Brak wiersza = brak rangi. To nie to samo co ranga średnia: domena bez rangi trafia niżej niż
 * każda oceniona, ale wyżej niż reszta internetu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_host_priorities', function (Blueprint $table): void {
            $table->id();
            $table->string('host')->unique();
            $table->unsignedTinyInteger('priority');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_host_priorities');
    }
};
