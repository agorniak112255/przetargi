<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raport „Wynik kampanii” (04.10.2026): ile towar leżał i ile kosztował w chwili startu wysyłki. erp_items zmienia
 * się po sprzedaży (ostatnia sprzedaż, partie), więc te wartości trzeba zamrozić przy pozycji kampanii.
 * snap_source: 'send' = zapis przy starcie; null = kampania wysłana przed tą zmianą (brak danych z chwili wysyłki).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_items', function (Blueprint $table): void {
            // koszt jednostki, który handlowiec widział przy pozycji (wartość partii / stan, jak CampaignItemPresenter)
            $table->decimal('snap_unit_cost', 14, 4)->nullable()->after('snap_stock_at');
            // ostatnia sprzedaż towaru (FS/PA/WZ, dowolny magazyn) przed wysyłką; null przy snap_source = nigdy
            $table->date('snap_last_sale_at')->nullable()->after('snap_unit_cost');
            // przyjęcie najstarszej partii ze stanem (magazyny handlowe, a bez nich wszystkie)
            $table->date('snap_oldest_lot_at')->nullable()->after('snap_last_sale_at');
            $table->string('snap_source', 10)->nullable()->after('snap_oldest_lot_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_items', function (Blueprint $table): void {
            $table->dropColumn(['snap_unit_cost', 'snap_last_sale_at', 'snap_oldest_lot_at', 'snap_source']);
        });
    }
};
