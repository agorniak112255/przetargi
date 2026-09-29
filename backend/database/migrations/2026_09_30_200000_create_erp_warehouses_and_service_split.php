<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Magazyny XL: handlowe i usługowe (decyzja właściciela 30.09.2026 — usługowe: 01M materiały Rzeszów, 01G i 13G
 * gaśnice, 01E elektryczny, 14U Kraków usługi; wszystkie pozostałe, także magazyny klientów, są handlowe).
 * Magazynu spoza słownika nie uznajemy za usługowy.
 *
 * erp_items: ilość, wartość i najstarsza dostawa w magazynach usługowych (handlowe = całość − usługowe) — liczone
 * przy odczycie stanów (ErpItemSync) i przeliczane z zapisanego rozbicia po zmianie słownika (erp:warehouse-split).
 */
return new class extends Migration
{
    private const SERVICE = [
        '01M' => 'Magazyn materiałów - Rzeszów',
        '01G' => 'Magazyn gaśnic - Rzeszów',
        '13G' => 'Magazyn gaśnic - Stalowa Wola',
        '01E' => 'Magazyn Elektryczny',
        '14U' => 'Magazyn Kraków -Usługi',
    ];

    public function up(): void
    {
        Schema::create('erp_warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100)->nullable();
            $table->boolean('is_service')->default(false);
            $table->timestamps();
        });
        $now = now();
        DB::table('erp_warehouses')->insert(array_map(
            static fn (string $code, string $name): array => ['code' => $code, 'name' => $name, 'is_service' => true, 'created_at' => $now, 'updated_at' => $now],
            array_keys(self::SERVICE),
            self::SERVICE,
        ));

        Schema::table('erp_items', function (Blueprint $table): void {
            $table->decimal('stock_service', 14, 4)->default(0)->after('oldest_lot_at');
            // domyślnie 0: do przeliczenia (erp:warehouse-split) kwota handlowa = cała wartość partii, a nie zapas z PZ
            $table->decimal('stock_service_value', 14, 2)->nullable()->default(0)->after('stock_service');
            $table->date('oldest_lot_trade_at')->nullable()->after('stock_service_value');
            $table->date('oldest_lot_service_at')->nullable()->after('oldest_lot_trade_at');
        });
    }

    public function down(): void
    {
        Schema::table('erp_items', function (Blueprint $table): void {
            $table->dropColumn(['stock_service', 'stock_service_value', 'oldest_lot_trade_at', 'oldest_lot_service_at']);
        });
        Schema::dropIfExists('erp_warehouses');
    }
};
