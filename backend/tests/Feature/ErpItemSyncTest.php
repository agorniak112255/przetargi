<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ErpItemPurchase;
use App\Models\Product;
use App\Services\Erp\ErpCardStock;
use App\Services\Erp\ErpItemSync;
use App\Services\Erp\ErpXlGateway;
use App\Services\Erp\StockLots;
use App\Services\Erp\WarehouseLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

final class ErpItemSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeErpXlGateway $xl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->xl = new FakeErpXlGateway;
        $this->app->instance(ErpXlGateway::class, $this->xl);
    }

    public function test_copies_item_with_trade_stock_breakdown_suppliers_purchases_and_last_sale(): void
    {
        $this->xl->items = [FakeErpXlGateway::item(15785, 'SOK9301145', 'GOGLE UVEX 9301.145 spawal.', 'bez zmian')];
        $this->xl->stockRows = [
            ['gid' => 15785, 'warehouse_code' => '01H', 'warehouse_name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 8.0],
            ['gid' => 15785, 'warehouse_code' => '15H', 'warehouse_name' => 'Magazyn HANDEL Kraków', 'quantity' => 4.0],
            ['gid' => 15785, 'warehouse_code' => '15MITKR', 'warehouse_name' => 'Magazyn Mittal - Kraków', 'quantity' => 20.0],
        ];
        $this->xl->supplierRows = [
            ['gid' => 15785, 'supplier_id' => 7, 'supplier' => 'UVEX', 'price' => 41.5, 'currency' => 'PLN', 'updated' => 82455],
        ];
        $this->xl->purchaseRows = [
            FakeErpXlGateway::purchase(15785, 2234598, 82455, 'UVEX', 10, 452.0),
            // zakup w EUR: wartość księgowa w PLN, cena z dokumentu w walucie dosłownie
            FakeErpXlGateway::purchase(15785, 2200001, 82000, 'UVEX', 4, 172.0, 'EUR', 10.0),
        ];
        $this->xl->sales = [15785 => 82450];

        $stats = app(ErpItemSync::class)->run();

        $this->assertSame(['items' => 1, 'with_trade_stock' => 1, 'purchases' => 2, 'removed' => 0], $stats);
        $item = ErpItem::query()->where('xl_gid', 15785)->firstOrFail();
        $this->assertSame('SOK9301145', $item->code);
        $this->assertSame('GOGLE UVEX 9301.145 spawal.', $item->name);
        $this->assertSame('bez zmian', $item->name1);
        // HANDEL = 01H + 15H; magazyn kontraktowy tylko w sumie wszystkich i w rozbiciu
        $this->assertSame('12.0000', $item->stock_trade);
        $this->assertSame('32.0000', $item->stock_total);
        $this->assertSame(['15MITKR', '01H', '15H'], array_column($item->stock_by_warehouse, 'code'));
        $this->assertSame('2026-09-29', $item->suppliers[0]['updated_at']);
        $this->assertSame('2026-09-29', $item->last_purchase_at->toDateString());
        $this->assertSame('2026-09-24', $item->last_sale_at->toDateString());

        $purchases = $item->purchases()->get();
        $this->assertCount(2, $purchases);
        $this->assertSame(2234598, (int) $purchases[0]->document_id);
        $this->assertSame('45.2000', $purchases[0]->unit_price_pln);
        $this->assertSame('EUR', $purchases[1]->currency);
        $this->assertSame('43.0000', $purchases[1]->unit_price_pln);
        $this->assertSame('10.0000', $purchases[1]->document_price);
    }

    public function test_pages_through_items_and_keeps_only_last_purchases_per_item(): void
    {
        config(['erpxl.batch' => 2, 'erpxl.purchases_per_item' => 3]);
        foreach ([10, 20, 30, 40, 50] as $gid) {
            $this->xl->items[] = FakeErpXlGateway::item($gid, 'T'.$gid, 'Towar '.$gid);
        }
        foreach ([5, 4, 3, 2, 1] as $n) {
            $this->xl->purchaseRows[] = FakeErpXlGateway::purchase(30, 1000 + $n, 82000 + $n, 'DOST', 1, 10.0);
        }

        $stats = app(ErpItemSync::class)->run();

        $this->assertSame(5, $stats['items']);
        $this->assertSame(5, ErpItem::query()->count());
        $this->assertSame([1005, 1004, 1003], ErpItemPurchase::query()->orderByDesc('document_id')->pluck('document_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_second_run_replaces_purchases_and_marks_items_missing_from_xl(): void
    {
        $this->xl->items = [FakeErpXlGateway::item(1, 'A1', 'Towar A'), FakeErpXlGateway::item(2, 'B2', 'Towar B')];
        $this->xl->purchaseRows = [FakeErpXlGateway::purchase(1, 500, 82000, 'DOST', 1, 10.0)];
        app(ErpItemSync::class)->run();

        $this->travel(1)->hours();
        $this->xl->items = [FakeErpXlGateway::item(1, 'A1', 'Towar A nowa nazwa')];
        $this->xl->purchaseRows = [
            FakeErpXlGateway::purchase(1, 600, 82100, 'DOST', 2, 30.0),
            FakeErpXlGateway::purchase(1, 500, 82000, 'DOST', 1, 10.0),
        ];
        $stats = app(ErpItemSync::class)->run();

        $this->assertSame(1, $stats['removed']);
        $this->assertNotNull(ErpItem::query()->where('xl_gid', 2)->value('removed_at'));
        $a = ErpItem::query()->where('xl_gid', 1)->firstOrFail();
        $this->assertNull($a->removed_at);
        $this->assertSame('Towar A nowa nazwa', $a->name);
        $this->assertSame(2, ErpItemPurchase::query()->where('erp_item_id', $a->id)->count());
    }

    public function test_trial_run_with_limit_does_not_mark_removed(): void
    {
        $this->xl->items = [FakeErpXlGateway::item(1, 'A1', 'Towar A'), FakeErpXlGateway::item(2, 'B2', 'Towar B')];
        app(ErpItemSync::class)->run();
        $this->travel(1)->hours();

        $stats = app(ErpItemSync::class)->run(1);

        $this->assertSame(1, $stats['items']);
        $this->assertSame(0, $stats['removed']);
        $this->assertSame(0, ErpItem::query()->whereNotNull('removed_at')->count());
    }

    public function test_xl_data_does_not_touch_card_prices_or_stock(): void
    {
        $product = Product::query()->create([
            'sku' => '9301.145', 'name' => 'Gogle 9301.145', 'manufacturer' => 'UVEX',
            'catalog_price_net' => 80, 'purchase_price' => 50, 'stock' => 3,
        ]);
        $this->xl->items = [FakeErpXlGateway::item(1, 'SOK9301145', 'GOGLE UVEX 9301.145')];
        $this->xl->stockRows = [['gid' => 1, 'warehouse_code' => '01H', 'warehouse_name' => 'Magazyn HANDEL', 'quantity' => 99.0]];
        $this->xl->purchaseRows = [FakeErpXlGateway::purchase(1, 1, 82000, 'UVEX', 1, 12.0)];

        app(ErpItemSync::class)->run();

        $product->refresh();
        $this->assertSame('50.00', $product->purchase_price);
        $this->assertSame('80.00', $product->catalog_price_net);
        $this->assertSame(3, (int) $product->stock);
    }

    public function test_stock_refresh_updates_only_stock_and_its_read_time(): void
    {
        $this->xl->items = [
            FakeErpXlGateway::item(1, 'A1', 'Towar A'),
            FakeErpXlGateway::item(2, 'B2', 'Towar B'),
            FakeErpXlGateway::item(3, 'C3', 'Towar C'),
        ];
        $this->xl->stockRows = [
            ['gid' => 1, 'warehouse_code' => '01H', 'warehouse_name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 10.0],
            ['gid' => 2, 'warehouse_code' => '01H', 'warehouse_name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 5.0],
            ['gid' => 3, 'warehouse_code' => '15H', 'warehouse_name' => 'Magazyn HANDEL Kraków', 'quantity' => 7.0],
        ];
        $this->xl->purchaseRows = [FakeErpXlGateway::purchase(1, 500, 82000, 'DOST', 1, 10.0)];
        app(ErpItemSync::class)->run();
        $nightly = ErpItem::query()->where('xl_gid', 1)->value('synced_at');

        $this->travel(3)->hours();
        // w ciągu dnia: A sprzedany do 4 i przesunięty częściowo do Krakowa, B wyprzedany, C bez zmian
        $this->xl->stockRows = [
            ['gid' => 1, 'warehouse_code' => '01H', 'warehouse_name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 3.0],
            ['gid' => 1, 'warehouse_code' => '15H', 'warehouse_name' => 'Magazyn HANDEL Kraków', 'quantity' => 1.0],
            ['gid' => 3, 'warehouse_code' => '15H', 'warehouse_name' => 'Magazyn HANDEL Kraków', 'quantity' => 7.0],
        ];
        $this->xl->items[0]['name'] = 'Nazwa zmieniona w XL';
        $this->xl->purchaseRows = [];

        $this->assertSame(0, Artisan::call('erp:stock'));

        $a = ErpItem::query()->where('xl_gid', 1)->firstOrFail();
        $this->assertSame('4.0000', $a->stock_trade);
        $this->assertSame(['01H', '15H'], array_column($a->stock_by_warehouse, 'code'));
        // bez nazw i zakupów — to robi nocna kopia
        $this->assertSame('Towar A', $a->name);
        $this->assertSame(1, $a->purchases()->count());
        $this->assertEquals($nightly, $a->synced_at);
        $this->assertTrue($a->stock_synced_at->greaterThan($a->synced_at));
        $b = ErpItem::query()->where('xl_gid', 2)->firstOrFail();
        $this->assertSame('0.0000', $b->stock_trade);
        $this->assertSame([], $b->stock_by_warehouse);
        // bez zmiany stanu też dostaje czas odczytu
        $c = ErpItem::query()->where('xl_gid', 3)->firstOrFail();
        $this->assertEquals($a->stock_synced_at, $c->stock_synced_at);

        $card = Product::query()->create(['sku' => 'A-1', 'name' => 'Wyrób A', 'manufacturer' => 'X', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0]);
        ErpItemLink::query()->create(['erp_item_id' => $a->id, 'product_id' => $card->id, 'status' => ErpItemLink::STATUS_AUTO, 'method' => ErpItemLink::METHOD_NAME]);
        $this->assertSame($a->stock_synced_at->toIso8601String(), app(ErpCardStock::class)->forProduct($card->id)['synced_at']);
    }

    public function test_stock_value_and_oldest_lot_come_from_all_warehouses_and_refresh(): void
    {
        $this->xl->items = [FakeErpXlGateway::item(1, 'A1', 'Towar A'), FakeErpXlGateway::item(2, 'B2', 'Towar B')];
        $this->xl->stockRows = [
            // wartość księgowa partii; najstarsza partia: 2025-10-02 w magazynie spoza HANDEL
            ['gid' => 1, 'warehouse_code' => '01H', 'warehouse_name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 3800.0, 'value' => 1584.8, 'oldest_lot' => 1147942279],
            ['gid' => 1, 'warehouse_code' => '01BSH', 'warehouse_name' => 'Magazyn BSH Rzeszów', 'quantity' => 600.0, 'value' => 245.7, 'oldest_lot' => 1128261658],
            // XL nie podał wartości jednego magazynu — suma byłaby zaniżona, więc wartości brak
            ['gid' => 2, 'warehouse_code' => '01H', 'warehouse_name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 5.0, 'value' => 50.0, 'oldest_lot' => null],
            ['gid' => 2, 'warehouse_code' => '02X', 'warehouse_name' => 'Magazyn X', 'quantity' => 1.0],
        ];
        app(ErpItemSync::class)->run();

        $a = ErpItem::query()->where('xl_gid', 1)->firstOrFail();
        $this->assertSame('1830.50', $a->stock_value);
        $this->assertSame('2025-10-02', $a->oldest_lot_at?->toDateString());
        $this->assertEquals(245.7, $a->stock_by_warehouse[1]['value']);
        $b = ErpItem::query()->where('xl_gid', 2)->firstOrFail();
        $this->assertNull($b->stock_value);
        $this->assertNull($b->oldest_lot_at);

        // odświeżenie samych stanów: sprzedana stara partia BSH, zostaje HANDEL
        $this->xl->stockRows = [
            ['gid' => 1, 'warehouse_code' => '01H', 'warehouse_name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 3800.0, 'value' => 1584.8, 'oldest_lot' => 1147942279],
        ];
        $this->assertSame(0, Artisan::call('erp:stock'));
        $a->refresh();
        $this->assertSame('1584.80', $a->stock_value);
        $this->assertSame('2026-05-18', $a->oldest_lot_at?->toDateString());
        // brak stanu = wartość 0, nie „nieznana”
        $this->assertSame('0.00', ErpItem::query()->where('xl_gid', 2)->value('stock_value'));
    }

    public function test_stock_lots_saved_per_warehouse_and_day_and_replaced_by_stock_refresh(): void
    {
        $this->xl->items = [FakeErpXlGateway::item(1, 'A1', 'Towar A'), FakeErpXlGateway::item(2, 'B2', 'Towar B')];
        $this->xl->stockLotRows = [
            // dwie partie tego samego dnia (inna godzina) na 01H — jeden wiersz
            ['gid' => 1, 'warehouse_code' => '01H', 'received_at' => 1147942279, 'quantity' => 3.0, 'value' => 30.0],
            ['gid' => 1, 'warehouse_code' => '01H', 'received_at' => 1147942279 + 3600, 'quantity' => 2.0, 'value' => 20.0],
            ['gid' => 1, 'warehouse_code' => '15H', 'received_at' => 1128261658, 'quantity' => 1.0, 'value' => null],
            // XL bez daty przyjęcia
            ['gid' => 1, 'warehouse_code' => '01H', 'received_at' => 0, 'quantity' => 4.0, 'value' => 8.0],
            ['gid' => 2, 'warehouse_code' => '20H', 'received_at' => 1128261658, 'quantity' => 7.0, 'value' => 70.0],
        ];
        app(ErpItemSync::class)->run();

        $a = ErpItem::query()->where('xl_gid', 1)->firstOrFail();
        $rows = DB::table(StockLots::TABLE)->where('erp_item_id', $a->id)->orderBy('warehouse_code')->orderBy('received_at')->get();
        $this->assertSame(['01H', '01H', '15H'], $rows->pluck('warehouse_code')->all());
        $this->assertSame(['01', '01', '15'], $rows->pluck('location')->all());
        $this->assertSame([null, '2026-05-18', '2025-10-02'], $rows->pluck('received_at')->map(fn ($d) => $d === null ? null : substr((string) $d, 0, 10))->all());
        $this->assertEquals([4, 5, 1], $rows->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertEquals([8.0, 50.0, null], $rows->pluck('value')->map(fn ($v) => $v === null ? null : (float) $v)->all());

        // odświeżenie stanów zastępuje partie, także towaru bez zmiany sum; wyprzedany B traci partie
        $this->xl->stockLotRows = [['gid' => 1, 'warehouse_code' => '11H', 'received_at' => 1147942279, 'quantity' => 5.0, 'value' => 50.0]];
        $this->assertSame(0, Artisan::call('erp:stock'));
        $this->assertSame(['11H'], DB::table(StockLots::TABLE)->where('erp_item_id', $a->id)->pluck('warehouse_code')->all());
        $b = ErpItem::query()->where('xl_gid', 2)->firstOrFail();
        $this->assertSame(0, DB::table(StockLots::TABLE)->where('erp_item_id', $b->id)->count());
    }

    public function test_stock_per_warehouse_rows_follow_breakdown_with_location_from_code(): void
    {
        $this->xl->items = [FakeErpXlGateway::item(1, 'A1', 'Towar A'), FakeErpXlGateway::item(2, 'B2', 'Towar B')];
        $this->xl->stockRows = [
            ['gid' => 1, 'warehouse_code' => '01H', 'warehouse_name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 8.0, 'value' => 80.0, 'oldest_lot' => 1147942279],
            ['gid' => 1, 'warehouse_code' => '01MTU', 'warehouse_name' => 'Magazyn MTU', 'quantity' => 2.0, 'oldest_lot' => null],
            ['gid' => 1, 'warehouse_code' => '15H', 'warehouse_name' => 'Magazyn HANDEL Kraków', 'quantity' => 1.0, 'value' => 10.0, 'oldest_lot' => 1128261658],
            ['gid' => 2, 'warehouse_code' => '20H', 'warehouse_name' => 'Magazyn HANDEL - Sanok', 'quantity' => 3.0, 'value' => 30.0, 'oldest_lot' => null],
        ];
        app(ErpItemSync::class)->run();

        $a = ErpItem::query()->where('xl_gid', 1)->firstOrFail();
        $rows = DB::table(WarehouseLocations::TABLE)->where('erp_item_id', $a->id)->orderBy('warehouse_code')->get();
        $this->assertSame(['01H', '01MTU', '15H'], $rows->pluck('warehouse_code')->all());
        $this->assertSame(['01', '01', '15'], $rows->pluck('location')->all());
        $this->assertEquals([8, 2, 1], $rows->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertEquals([80.0, null, 10.0], $rows->pluck('value')->map(fn ($v) => $v === null ? null : (float) $v)->all());
        $this->assertSame(['2026-05-18', null, '2025-10-02'], $rows->pluck('oldest_lot_at')->map(fn ($d) => $d === null ? null : substr((string) $d, 0, 10))->all());

        // odświeżenie stanów: towar przeniesiony do Tarnowa, B wyprzedany
        $this->xl->stockRows = [
            ['gid' => 1, 'warehouse_code' => '11H', 'warehouse_name' => 'Magazyn HANDEL - Tarnów', 'quantity' => 11.0, 'value' => 110.0, 'oldest_lot' => 1147942279],
        ];
        $this->assertSame(0, Artisan::call('erp:stock'));
        $this->assertSame(['11H' => '11'], DB::table(WarehouseLocations::TABLE)->where('erp_item_id', $a->id)->pluck('location', 'warehouse_code')->all());
        $b = ErpItem::query()->where('xl_gid', 2)->firstOrFail();
        $this->assertSame(0, DB::table(WarehouseLocations::TABLE)->where('erp_item_id', $b->id)->count());

        // nocna kopia też zastępuje, nie dopisuje
        $this->xl->stockRows = [['gid' => 1, 'warehouse_code' => '13H', 'warehouse_name' => 'Magazyn HANDEL Stalowa Wola', 'quantity' => 1.0]];
        app(ErpItemSync::class)->run();
        $this->assertSame(['13H' => '13'], DB::table(WarehouseLocations::TABLE)->where('erp_item_id', $a->id)->pluck('location', 'warehouse_code')->all());
        $this->assertSame('Magazyny 99', WarehouseLocations::name('99'));
        $this->assertNull(WarehouseLocations::of('MAG'));
        // 14U (Kraków – usługi) należy do Krakowa
        $this->assertSame('15', WarehouseLocations::of('14U'));
    }

    public function test_last_sale_per_warehouse_and_overall_latest(): void
    {
        $this->xl->items = [FakeErpXlGateway::item(1, 'A1', 'Towar A'), FakeErpXlGateway::item(2, 'B2', 'Towar B')];
        // FS bez magazynu najpóźniej — ona jest ostatnią sprzedażą towaru, ale żadnego oddziału
        $this->xl->sales = [1 => 82450];
        $this->xl->saleRows = [
            ['gid' => 1, 'warehouse_code' => '01H', 'date' => 82300],
            ['gid' => 1, 'warehouse_code' => '14U', 'date' => 82400],
        ];
        app(ErpItemSync::class)->run();

        $a = ErpItem::query()->where('xl_gid', 1)->firstOrFail();
        $this->assertSame('2026-09-24', $a->last_sale_at?->toDateString());
        $rows = DB::table(WarehouseLocations::SALES_TABLE)->where('erp_item_id', $a->id)->orderBy('warehouse_code')->get();
        $this->assertSame(['', '01H', '14U'], $rows->pluck('warehouse_code')->all());
        $this->assertSame([null, '01', '15'], $rows->pluck('location')->all());
        $this->assertSame(['2026-09-24', '2026-04-27', '2026-08-05'], $rows->pluck('last_sale_at')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        $b = ErpItem::query()->where('xl_gid', 2)->firstOrFail();
        $this->assertNull($b->last_sale_at);
        $this->assertSame(0, DB::table(WarehouseLocations::SALES_TABLE)->where('erp_item_id', $b->id)->count());

        // następna noc zastępuje wiersze
        $this->xl->sales = [];
        $this->xl->saleRows = [['gid' => 1, 'warehouse_code' => '20H', 'date' => 82460]];
        app(ErpItemSync::class)->run();
        $this->assertSame(['20H' => '20'], DB::table(WarehouseLocations::SALES_TABLE)->where('erp_item_id', $a->id)->pluck('location', 'warehouse_code')->all());
    }

    public function test_refuses_to_run_when_not_configured_and_command_skips_quietly(): void
    {
        $this->xl->isConfigured = false;

        $this->assertSame(0, Artisan::call('erp:sync'));
        $this->assertSame(0, ErpItem::query()->count());

        $this->expectException(RuntimeException::class);
        app(ErpItemSync::class)->run();
    }
}
