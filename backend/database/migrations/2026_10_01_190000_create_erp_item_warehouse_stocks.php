<?php

declare(strict_types=1);

use App\Services\Erp\WarehouseLocations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stan towaru XL na magazyn jako wiersze — do filtra oddziału w Zapasach i raporcie dla zarządu (suma, wartość i wiek
 * partii w SQL, po całej liście). Kopia rozbicia erp_items.stock_by_warehouse (to ono jest źródłem): zapis przy odczycie
 * stanów (ErpItemSync), przebudowa z rozbicia w erp:warehouse-split. location = cyfry z początku kodu magazynu
 * (01H, 01MTU → 01 Rzeszów).
 *
 * Wypełnienie z zapisanego rozbicia — bez odczytu z XL. Cofnięcie usuwa tylko tę kopię.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(WarehouseLocations::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_item_id')->constrained('erp_items')->cascadeOnDelete();
            $table->string('warehouse_code', 20);
            $table->string('location', 10)->nullable();
            $table->decimal('quantity', 14, 4);
            // wartość księgowa netto partii w PLN; null = XL nie podał
            $table->decimal('value', 14, 2)->nullable();
            $table->date('oldest_lot_at')->nullable();

            $table->unique(['erp_item_id', 'warehouse_code']);
            $table->index(['location', 'erp_item_id']);
        });

        DB::table('erp_items')->select(['id', 'stock_by_warehouse'])->whereNotNull('stock_by_warehouse')
            ->chunkById(500, function ($items): void {
                $rows = [];
                foreach ($items as $item) {
                    $warehouses = json_decode((string) $item->stock_by_warehouse, true);
                    array_push($rows, ...WarehouseLocations::rows((int) $item->id, is_array($warehouses) ? $warehouses : []));
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table(WarehouseLocations::TABLE)->insert($chunk);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_item_warehouse_stocks');
    }
};
