<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historia zapasów (decyzja właściciela 01.10.2026: widać w czasie, czy zapasy maleją). Co noc po odczycie stanów z XL
 * (InventorySnapshots):
 *  - erp_inventory_snapshots — liczby raportu dla zarządu na dzień odczytu: oddział ('' = wszystkie) × magazyny
 *    (trade/service/all), w totals te same koszyki co kafelki;
 *  - erp_inventory_warehouse_snapshots — surowa warstwa: ilość i wartość na każdy magazyn XL (do przeliczenia historii,
 *    gdy zmieni się podział magazynów na oddziały albo handlowe/usługowe).
 * source: 'live' — nocny zapis; 'xl_history' — dzień odtworzony z historii stanów XL. Cofnięcie usuwa obie tabele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_inventory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('taken_on');
            $table->string('location', 10)->default('');
            $table->string('scope', 10);
            $table->string('source', 16)->default('live');
            $table->json('totals');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['taken_on', 'location', 'scope']);
            $table->index(['location', 'scope', 'taken_on']);
        });

        Schema::create('erp_inventory_warehouse_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('taken_on');
            $table->string('warehouse_code', 20);
            $table->string('location', 10)->nullable();
            $table->boolean('is_service')->default(false);
            $table->string('source', 16)->default('live');
            $table->unsignedInteger('items');
            $table->decimal('quantity', 16, 4);
            $table->decimal('value', 16, 2);
            // towary bez ceny zakupu — ich wartości nie ma w value
            $table->unsignedInteger('value_unknown')->default(0);
            $table->timestamps();

            $table->unique(['taken_on', 'warehouse_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_inventory_warehouse_snapshots');
        Schema::dropIfExists('erp_inventory_snapshots');
    }
};
