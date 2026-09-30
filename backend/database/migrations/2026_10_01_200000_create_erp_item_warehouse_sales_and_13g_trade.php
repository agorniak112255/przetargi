<?php

declare(strict_types=1);

use App\Services\Erp\WarehouseLocations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decyzje właściciela 01.10.2026:
 *  - ostatnia sprzedaż także na oddział — tabela erp_item_warehouse_sales (magazyn z nagłówka FS/PA/WZ), wypełnia ją
 *    nocna kopia XL; do tego czasu filtr oddziału bierze ostatnią sprzedaż towaru z dowolnego magazynu;
 *  - 13G (Stalowa Wola) jest handlowy: w rok ok. 600 faktur i 70 paragonów z tego magazynu, na stanie gaśnice, znaki,
 *    rękawice, odzież — sklep, nie magazyn usługowy;
 *  - 14U (Kraków – usługi) należy do oddziału Kraków (WarehouseLocations::ALIASES).
 *
 * Po zmianie słownika i oddziałów erp:warehouse-split przelicza część usługową i stan na magazyn z zapisanego rozbicia
 * (bez odczytu z XL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(WarehouseLocations::SALES_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_item_id')->constrained('erp_items')->cascadeOnDelete();
            // '' = dokument bez magazynu
            $table->string('warehouse_code', 20);
            $table->string('location', 10)->nullable();
            $table->date('last_sale_at');

            $table->unique(['erp_item_id', 'warehouse_code']);
            $table->index(['location', 'erp_item_id']);
        });

        DB::table('erp_warehouses')->where('code', '13G')->update(['is_service' => false, 'updated_at' => now()]);
        Artisan::call('erp:warehouse-split');
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_item_warehouse_sales');
        DB::table('erp_warehouses')->where('code', '13G')->update(['is_service' => true, 'updated_at' => now()]);
        Artisan::call('erp:warehouse-split');
    }
};
