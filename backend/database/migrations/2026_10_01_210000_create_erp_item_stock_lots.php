<?php

declare(strict_types=1);

use App\Services\Erp\StockLots;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partie towaru XL na stanie: magazyn, dzień przyjęcia, ilość i wartość — do rozbicia „jak długo leży” w raporcie dla
 * zarządu. Wypełnia nocny odczyt z XL (erp:sync) albo erp:stock; do tego czasu raport pokazuje, że liczb jeszcze nie ma.
 * Cofnięcie usuwa tylko tę kopię.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(StockLots::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_item_id')->constrained('erp_items')->cascadeOnDelete();
            $table->string('warehouse_code', 20);
            $table->string('location', 10)->nullable();
            // dzień przyjęcia partii (TwZ_DataP); null = XL nie podał
            $table->date('received_at')->nullable();
            $table->decimal('quantity', 14, 4);
            // wartość księgowa netto partii w PLN; null = XL nie podał
            $table->decimal('value', 14, 2)->nullable();

            $table->index(['erp_item_id', 'received_at']);
            $table->index(['location', 'erp_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_item_stock_lots');
    }
};
