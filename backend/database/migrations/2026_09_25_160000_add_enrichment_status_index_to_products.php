<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stan kolejki opisów AI (/api/product-enrichment-batches/active) idzie z paneli Produktów i Cenników co 2,5 s
     * i za każdym razem szukał kart zawieszonych w „running” pełnym skanem tabeli (25.09.2026: 48 tys. kart, ~30 ms,
     * zapytanie z now() omija cache zapytań MariaDB). Po statusie liczą też liczniki kolejki, lista cenników i filtr
     * statusu na liście produktów.
     */
    public function up(): void
    {
        // Dodanie indeksu czeka na chwilową wyłączną blokadę tabeli. Trwający import albo usuwanie cennika trzyma products
        // w długiej transakcji, a czekający ALTER wstrzymuje wszystkie kolejne zapytania o karty — stanęłaby cała
        // aplikacja (MariaDB domyślnie czeka do doby). Po 10 s migracja kończy się błędem i wdrożenie wystarczy powtórzyć.
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        if ($mysql) {
            DB::statement('SET SESSION lock_wait_timeout = 10');
        }
        try {
            Schema::table('products', function (Blueprint $table): void {
                $table->index('enrichment_status');
            });
        } finally {
            if ($mysql) {
                DB::statement('SET SESSION lock_wait_timeout = DEFAULT');
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['enrichment_status']);
        });
    }
};
