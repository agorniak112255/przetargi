<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ErpItemPurchase;
use App\Models\ErpRwPwPair;
use App\Models\ErpWarehouse;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\StockLots;
use App\Services\Erp\WarehouseLocations;
use App\Services\Erp\WarehouseSplit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Raport zapasów dla zarządu: te same reguły co lista Zalegające, magazyny handlowe/usługowe, okna z listami. */
final class InventoryBoardApiTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    private int $doc = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(10, 0));
    }

    public function test_board_role_sees_only_the_report_and_its_lists(): void
    {
        $board = Role::findOrCreate('zarzad', 'web');
        $board->givePermissionTo(Permission::findOrCreate('inventory.report.view', 'web'));
        $user = User::factory()->create();
        $user->assignRole($board);
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/board')->assertOk()->assertJsonPath('warehouses', 'trade');
        $this->getJson('/api/inventory/board/items?bucket=no_sale_6')->assertOk();
        $this->getJson('/api/inventory/board/moves')->assertOk();
        $this->getJson('/api/inventory')->assertForbidden();
        $this->getJson('/api/inventory/rw-pw')->assertForbidden();
        $this->getJson('/api/inventory/board/items?bucket=lot_12')->assertUnprocessable();
        $this->getJson('/api/inventory/board?warehouses=klient')->assertUnprocessable();

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/inventory/board')->assertForbidden();
    }

    public function test_totals_nested_buckets_stale_lots_groups_and_top_list(): void
    {
        $kaptur = $this->item('AKAPE1000', 'KAPTUR METALURGICZNY', 10, 7589, lastSale: '2021-12-30', oldestLot: '2021-06-01');
        $card = Product::query()->create(['sku' => 'E1000', 'name' => 'Kaptur metalurgiczny E1000', 'manufacturer' => 'X', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0]);
        ErpItemLink::query()->create(['erp_item_id' => $kaptur->id, 'product_id' => $card->id, 'status' => ErpItemLink::STATUS_CONFIRMED, 'method' => ErpItemLink::METHOD_MANUAL]);
        $this->item('SFILTR', 'FILTR BLS', 624, 6638, lastSale: '2025-08-01', oldestLot: '2023-10-01');
        // sprzedaż 8 mies. temu: tylko w „pół roku”
        $this->item('ABLUZA', 'BLUZA', 5, 500, lastSale: '2026-01-15', oldestLot: '2025-11-01');
        // nigdy nie sprzedany, leży od roku
        $this->item('BNEVER', 'NIGDY', 2, 200, lastSale: null, oldestLot: '2025-09-01');
        // nigdy nie sprzedany, świeża dostawa — to nie zaleganie (także bez daty w zakresie handlowym)
        $this->item('BNEW', 'NOWY', 3, 300, lastSale: null, oldestLot: '2026-09-01');
        $this->item('HSOLD', 'SCHODZI', 7, 700, lastSale: '2026-09-20', oldestLot: '2020-08-01');
        $this->item('ZERO', 'BRAK STANU', 0, 0, lastSale: '2020-01-01', oldestLot: '2019-01-01');

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $r = $this->getJson('/api/inventory/board')->assertOk();

        $this->assertEquals(['items' => 6, 'value' => 15927], $r->json('stock'));
        $this->assertEquals([
            ['months' => 6, 'items' => 4, 'value' => 14927],
            // nigdy niesprzedany z partią sprzed roku też „nie sprzedaje się od roku”
            ['months' => 12, 'items' => 3, 'value' => 14427],
            ['months' => 24, 'items' => 1, 'value' => 7589],
        ], $r->json('no_sale'));
        $this->assertEquals(['items' => 1, 'value' => 200], $r->json('never_sold'));
        // wiek tylko towaru bez sprzedaży ponad rok: HSOLD (partia z 2020, ale schodzi) się nie liczy
        $this->assertEquals([['months' => 36, 'items' => 1, 'value' => 7589], ['months' => 60, 'items' => 1, 'value' => 7589]], $r->json('stale_lot'));
        // do zdania drobnym drukiem: cały zapas z partią starszą niż rok
        $this->assertEquals(['items' => 4, 'value' => 15127], $r->json('lot_12_total'));

        $groups = collect($r->json('groups'))->keyBy('group');
        $this->assertSame(['A', 'S', 'B', 'T', 'H', 'other'], collect($r->json('groups'))->pluck('group')->all());
        $this->assertEquals(['group' => 'A', 'label' => 'Odzież', 'unsold_items' => 1, 'unsold_value' => 7589, 'stock_value' => 8089], $groups['A']);
        $this->assertEquals(200, $groups['B']['unsold_value']);

        $this->assertSame(['AKAPE1000', 'SFILTR', 'BNEVER'], array_column($r->json('top_unsold'), 'code'));
        $this->assertSame('Kaptur metalurgiczny E1000', $r->json('top_unsold.0.card_name'));
        $this->assertNull($r->json('top_unsold.2.last_sale_at'));
    }

    public function test_trade_and_service_warehouses_split(): void
    {
        // 01M = usługowy (słownik z migracji); 01H handlowy. Gaśnice 100 szt.: 60 w handlu, 40 w usługach.
        $this->item('TGAS', 'GAŚNICA', 100, 1000, lastSale: '2025-01-01', oldestLot: '2020-01-01', warehouses: [
            ['code' => '01H', 'name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 60, 'value' => 600, 'oldest_lot' => '2024-06-01'],
            ['code' => '01M', 'name' => 'Magazyn materiałów - Rzeszów', 'quantity' => 40, 'value' => 400, 'oldest_lot' => '2020-01-01'],
        ]);
        // tylko w magazynie usługowym
        $this->item('SUSL', 'NARZĘDZIE', 5, 50, lastSale: '2024-01-01', oldestLot: '2022-01-01', warehouses: [
            ['code' => '01E', 'name' => 'Magazyn Elektryczny', 'quantity' => 5, 'value' => 50, 'oldest_lot' => '2022-01-01'],
        ]);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $trade = $this->getJson('/api/inventory/board')->assertOk();
        $this->assertEquals(['trade' => ['items' => 1, 'value' => 600], 'service' => ['items' => 2, 'value' => 450]], $trade->json('split'));
        $this->assertEquals(['items' => 1, 'value' => 600], $trade->json('stock'));
        // w handlu najstarsza partia z 06.2024 — nie ma 3 lat
        $this->assertEquals(0, $trade->json('stale_lot.0.items'));

        $service = $this->getJson('/api/inventory/board?warehouses=service')->assertOk();
        $this->assertEquals(['items' => 2, 'value' => 450], $service->json('stock'));
        $this->assertEquals(['months' => 36, 'items' => 2, 'value' => 450], $service->json('stale_lot.0'));
        $this->assertEquals(['items' => 2, 'value' => 1050], $this->getJson('/api/inventory/board?warehouses=all')->json('stock'));

        $row = $this->getJson('/api/inventory/board/items?bucket=stock&warehouses=trade')->assertOk()->json('data.0');
        $this->assertSame(['TGAS', 60, 600, 10, '2024-06-01'], [$row['code'], (int) $row['quantity'], (int) $row['value'], (int) $row['unit_cost'], $row['oldest_lot_at']]);
        $this->assertStringContainsString('(magazyny usługowe)', $this->getJson('/api/inventory/board/items?bucket=stock&warehouses=service')->json('title'));
    }

    public function test_location_narrows_tiles_windows_and_documents_to_its_warehouses(): void
    {
        // Rzeszów (01H) stara partia, Tarnów (11H) świeża dostawa tego samego towaru
        $kurtka = $this->item('AKURTKA', 'KURTKA', 15, 1500, lastSale: '2025-01-01', oldestLot: '2020-01-01', warehouses: [
            ['code' => '01H', 'name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 10, 'value' => 1000, 'oldest_lot' => '2020-01-01'],
            ['code' => '11H', 'name' => 'Magazyn HANDEL - Tarnów', 'quantity' => 5, 'value' => 500, 'oldest_lot' => '2026-08-01'],
        ]);
        // 01MTU to też Rzeszów
        $this->item('BBUT', 'BUT', 4, 400, lastSale: '2025-06-01', oldestLot: '2024-01-01', warehouses: [
            ['code' => '01MTU', 'name' => 'Magazyn MTU', 'quantity' => 4, 'value' => 400, 'oldest_lot' => '2024-01-01'],
        ]);
        // usługowy magazyn Rzeszowa
        $this->item('SGAS', 'GAŚNICA', 3, 300, lastSale: '2024-01-01', oldestLot: '2021-01-01', warehouses: [
            ['code' => '01M', 'name' => 'Magazyn materiałów - Rzeszów', 'quantity' => 3, 'value' => 300, 'oldest_lot' => '2021-01-01'],
        ]);
        // Tarnów bez wartości partii — ilość × ostatnia PZ; nigdy nie sprzedany
        $tar = $this->item('TTAR', 'TARNOWSKI', 2, 0, lastSale: null, oldestLot: '2022-01-01', warehouses: [
            ['code' => '11H', 'name' => 'Magazyn HANDEL - Tarnów', 'quantity' => 2, 'value' => null, 'oldest_lot' => '2022-01-01'],
        ]);
        ErpItemPurchase::query()->create([
            'erp_item_id' => $tar->id, 'document_type' => 1489, 'document_id' => 1, 'document_line' => 1, 'purchased_at' => '2022-01-01',
            'supplier' => 'X', 'quantity' => 2, 'document_unit' => 'szt', 'net_value_pln' => 50, 'unit_price_pln' => 25, 'document_price' => 25, 'currency' => 'PLN',
        ]);
        $this->item('H15', 'KRAKOWSKI', 1, 10, lastSale: '2026-09-01', oldestLot: '2026-01-01', warehouses: [
            ['code' => '15H', 'name' => 'Magazyn HANDEL Kraków', 'quantity' => 1, 'value' => 10, 'oldest_lot' => '2026-01-01'],
        ]);
        $this->pair($kurtka, '2026-06-01', 'NOMA', 'Nowak Maria', 70, warehouse: '01H');
        $this->pair($kurtka, '2026-06-02', 'CZAL', 'Czajka Alicja', 30, warehouse: '11H');

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $rze = $this->getJson('/api/inventory/board?location=01')->assertOk();
        $this->assertSame(['01', 'Rzeszów'], [$rze->json('location'), $rze->json('location_name')]);
        $this->assertSame(['01', '11', '15'], array_column($rze->json('locations'), 'key'));
        $this->assertEquals(['items' => 2, 'value' => 1400], $rze->json('stock'));
        $this->assertEquals(['trade' => ['items' => 2, 'value' => 1400], 'service' => ['items' => 1, 'value' => 300]], $rze->json('split'));
        // w Rzeszowie kurtka leży od 2020 — ponad 3 lata
        $this->assertEquals(['months' => 36, 'items' => 1, 'value' => 1000], $rze->json('stale_lot.0'));
        $this->assertSame(['total' => 1, 'unexplained' => 1, 'unexplained_value' => 70.0], [
            'total' => $rze->json('internal_moves.total'), 'unexplained' => $rze->json('internal_moves.unexplained'), 'unexplained_value' => (float) $rze->json('internal_moves.unexplained_value'),
        ]);
        $this->assertEquals(['items' => 3, 'value' => 1700], $this->getJson('/api/inventory/board?location=01&warehouses=all')->json('stock'));

        $tarnow = $this->getJson('/api/inventory/board?location=11')->assertOk();
        $this->assertEquals(['items' => 2, 'value' => 550], $tarnow->json('stock'));
        // kurtka w Tarnowie to świeża dostawa; stary jest tylko nigdy niesprzedany TTAR
        $this->assertEquals(['months' => 36, 'items' => 1, 'value' => 50], $tarnow->json('stale_lot.0'));
        $this->assertEquals(['items' => 1, 'value' => 50], $tarnow->json('never_sold'));
        $this->assertEquals(0, $this->getJson('/api/inventory/board?location=11&warehouses=service')->json('stock.items'));

        $window = $this->getJson('/api/inventory/board/items?bucket=stock&location=11')->assertOk();
        $this->assertStringContainsString('(Tarnów, magazyny handlowe)', $window->json('title'));
        $this->assertSame(['AKURTKA', 'TTAR'], array_column($window->json('data'), 'code'));
        $this->assertSame([5, 500, 100, '2026-08-01'], [
            (int) $window->json('data.0.quantity'), (int) $window->json('data.0.value'), (int) $window->json('data.0.unit_cost'), $window->json('data.0.oldest_lot_at'),
        ]);
        $this->assertEquals(50, $window->json('data.1.value'));
        $this->assertEquals(['items' => 2, 'value' => 550], $window->json('totals'));
        $this->assertSame(['TTAR'], array_column($this->getJson('/api/inventory/board/items?bucket=stock&location=11&search=tarnowski')->json('data'), 'code'));

        $moves = $this->getJson('/api/inventory/board/moves?scope=all&location=11')->assertOk();
        $this->assertEquals(['pairs' => 1, 'value' => 30], $moves->json('totals'));
        $this->assertSame('Czajka Alicja', $moves->json('data.0.operator_name'));
        $this->assertStringContainsString('(Tarnów, magazyny handlowe)', $moves->json('title'));

        // oddział bez towaru — puste liczby; nie cyfry — błąd
        $this->assertEquals(['items' => 0, 'value' => 0], $this->getJson('/api/inventory/board?location=99')->assertOk()->json('stock'));
        $this->getJson('/api/inventory/board?location=Rz')->assertUnprocessable();
        $this->getJson('/api/inventory/board/items?bucket=stock&location=01%27')->assertUnprocessable();
    }

    public function test_last_sale_per_location_krakow_services_and_stalowa_wola_trade(): void
    {
        // sprzedaje się w Tarnowie, w Rzeszowie leży od marca 2025
        $kurtka = $this->item('AKURTKA', 'KURTKA', 15, 1500, lastSale: '2026-09-20', oldestLot: '2024-01-01', warehouses: [
            ['code' => '01H', 'name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 10, 'value' => 1000, 'oldest_lot' => '2024-01-01'],
            ['code' => '11H', 'name' => 'Magazyn HANDEL - Tarnów', 'quantity' => 5, 'value' => 500, 'oldest_lot' => '2024-01-01'],
        ]);
        WarehouseLocations::replaceSales((int) $kurtka->id, [
            ['warehouse_code' => '11H', 'last_sale_at' => '2026-09-20'],
            ['warehouse_code' => '01H', 'last_sale_at' => '2025-03-01'],
        ]);
        // sprzedany tylko na fakturze bez magazynu — w oddziale to „ani razu”
        $faktura = $this->item('BFAKTURA', 'BUT', 3, 30, lastSale: '2026-09-01', oldestLot: '2020-01-01');
        WarehouseLocations::replaceSales((int) $faktura->id, [['warehouse_code' => null, 'last_sale_at' => '2026-09-01']]);
        // przed pierwszą nocną kopią sprzedaży na magazyn — sprzedaż z dowolnego magazynu
        $this->item('CSTARY', 'CZAPKA', 2, 20, lastSale: '2026-09-25', oldestLot: '2020-01-01');
        // 14U (Kraków – usługi) to Kraków; 13G (Stalowa Wola) — handlowy
        $this->item('K14', 'KRAKÓW USŁUGI', 4, 40, lastSale: '2024-01-01', oldestLot: '2025-01-01', warehouses: [
            ['code' => '14U', 'name' => 'Magazyn Kraków -Usługi', 'quantity' => 4, 'value' => 40, 'oldest_lot' => '2025-01-01'],
        ]);
        $this->item('SW13', 'GAŚNICA GP-6X', 6, 60, lastSale: '2026-09-29', oldestLot: '2026-01-01', warehouses: [
            ['code' => '13G', 'name' => 'Magazyn gaśnic - Stalowa Wola', 'quantity' => 6, 'value' => 60, 'oldest_lot' => '2026-01-01'],
        ]);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        // bez oddziału — sprzedaż z dowolnego magazynu: nic nie leży pół roku bez sprzedaży poza K14 (usługowy, poza handlem)
        $this->assertEquals(['months' => 6, 'items' => 0, 'value' => 0], $this->getJson('/api/inventory/board')->json('no_sale.0'));

        $rze = $this->getJson('/api/inventory/board?location=01')->assertOk();
        $this->assertEquals(['months' => 6, 'items' => 2, 'value' => 1030], $rze->json('no_sale.0'));
        $this->assertEquals(['items' => 1, 'value' => 30], $rze->json('never_sold'));
        $rows = collect($this->getJson('/api/inventory/board/items?bucket=no_sale_6&location=01')->json('data'))->keyBy('code');
        $this->assertSame(['2025-03-01', null], [$rows['AKURTKA']['last_sale_at'], $rows['BFAKTURA']['last_sale_at']]);
        $this->assertSame(['AKURTKA'], array_column($this->getJson('/api/inventory/board/items?bucket=stock&location=01&search=marzec')->json('data'), 'code'));
        $this->assertEquals(0, $this->getJson('/api/inventory/board?location=11')->json('no_sale.0.items'));

        $this->assertSame(['01', '11', '15', '13'], array_column($rze->json('locations'), 'key'));
        $this->assertEquals(['items' => 1, 'value' => 40], $this->getJson('/api/inventory/board?location=15&warehouses=service')->json('stock'));
        $this->assertEquals(['items' => 1, 'value' => 60], $this->getJson('/api/inventory/board?location=13')->json('stock'));
        $this->assertEquals(0, $this->getJson('/api/inventory/board?location=14&warehouses=all')->json('stock.items'));
    }

    public function test_items_window_pages_groups_and_totals(): void
    {
        foreach (range(1, 25) as $i) {
            $this->item(sprintf('A%03d', $i), 'BLUZA '.$i, 1, $i * 10, lastSale: '2024-01-01', oldestLot: '2023-01-01');
        }
        $this->item('B001', 'BUT', 1, 5000, lastSale: '2024-01-01', oldestLot: '2023-01-01');

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $r = $this->getJson('/api/inventory/board/items?bucket=no_sale_12&per_page=10&page=2')->assertOk();
        $this->assertSame(['current_page' => 2, 'last_page' => 3, 'per_page' => 10, 'total' => 26], $r->json('meta'));
        $this->assertEquals(['items' => 26, 'value' => 8250], $r->json('totals'));
        // od największej wartości: strona 1 = B001 i A025…A017, strona 2 zaczyna się od A016
        $this->assertSame('A016', $r->json('data.0.code'));
        $this->assertSame('Towar, który nie sprzedaje się ponad rok (magazyny handlowe)', $r->json('title'));

        $a = $this->getJson('/api/inventory/board/items?bucket=no_sale_12&group=A&per_page=50')->assertOk();
        $this->assertSame(25, $a->json('meta.total'));
        $this->assertSame('Odzież', $a->json('data.0.group_label'));
        $this->assertStringContainsString('— odzież', $a->json('title'));
        $this->getJson('/api/inventory/board/items?bucket=no_sale_12&per_page=15')->assertUnprocessable();
    }

    public function test_moves_summary_people_context_and_documents_window(): void
    {
        $bluza = $this->item('ABLUZA', 'BLUZA', 5, 500, lastSale: '2026-01-15', oldestLot: '2025-11-01');
        $this->pair($bluza, '2026-09-01', 'DOEW', 'Domin Ewelina', 100, lotAge: 51);
        $this->pair($bluza, '2026-08-01', 'DOEW', 'Domin Ewelina', 50);
        $this->pair($bluza, '2026-07-01', 'CZAL', 'Alina Czyżyk-Tomaszewska', 30);
        // zmiany rozmiaru tej samej osoby — kontekst „z N wszystkich”
        $this->pair($bluza, '2026-06-01', 'DOEW', 'Domin Ewelina', 10, sameFeature: false);
        $this->pair($bluza, '2026-05-01', 'NOMA', 'Nowaczek Martyna', 10, note: 'ZAMIANA ROZMIARÓW');
        $this->pair($bluza, '2026-04-01', 'NOMA', 'Nowaczek Martyna', 10, gap: 10);
        $this->pair($bluza, '2025-09-01', 'NOMA', 'Nowaczek Martyna', 10);
        // świeży towar (partia < 3 mies.) i para bez znanego wieku partii — poza raportem
        $this->pair($bluza, '2026-09-15', 'DOEW', 'Domin Ewelina', 777, lotAge: 2);
        $this->pair($bluza, '2026-09-16', 'DOEW', 'Domin Ewelina', 888, unknownLot: true);
        // magazyn usługowy — poza zakresem handlowym
        $this->pair($bluza, '2026-09-10', 'DOEW', 'Domin Ewelina', 999, warehouse: '01M');

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $r = $this->getJson('/api/inventory/board')->assertOk();
        $this->assertEquals([
            'from' => '2025-09-30', 'min_lot_age_months' => 3, 'total' => 5, 'unexplained' => 3, 'unexplained_value' => 180,
            'people' => [
                ['operator' => 'DOEW', 'name' => 'Domin Ewelina', 'count' => 2, 'total' => 3],
                ['operator' => 'CZAL', 'name' => 'Alina Czyżyk-Tomaszewska', 'count' => 1, 'total' => 1],
            ],
        ], $r->json('internal_moves'));
        $this->assertSame(1, $this->getJson('/api/inventory/board?warehouses=service')->json('internal_moves.unexplained'));

        $m = $this->getJson('/api/inventory/board/moves?operator=DOEW')->assertOk();
        $this->assertSame('Dokumenty wystawione przez: Domin Ewelina (magazyny handlowe)', $m->json('title'));
        $this->assertEquals(['pairs' => 2, 'value' => 150], $m->json('totals'));
        $this->assertSame(3, $m->json('min_lot_age_months'));
        $this->assertSame(['2026-09-01', '2026-08-01'], array_column($m->json('data'), 'rw_date'));
        $this->assertSame(51, $m->json('data.0.lot_age_months'));
        $this->assertSame('BLUZA', $m->json('data.0.item_name'));
        $this->assertSame(5, $this->getJson('/api/inventory/board/moves?scope=all')->json('meta.total'));
    }

    public function test_items_window_search_by_every_column_and_sort_by_column(): void
    {
        $kaptur = $this->item('AKAPE1000', 'KAPTUR METALURGICZNY', 10, 7589, lastSale: '2021-12-30', oldestLot: '2021-06-01');
        $card = Product::query()->create(['sku' => 'E1000', 'name' => 'Kaptur hutniczy E1000', 'manufacturer' => 'X', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0]);
        ErpItemLink::query()->create(['erp_item_id' => $kaptur->id, 'product_id' => $card->id, 'status' => ErpItemLink::STATUS_CONFIRMED, 'method' => ErpItemLink::METHOD_MANUAL]);
        $this->item('SFILTR', 'FILTR BLS', 624, 6638, lastSale: '2025-08-01', oldestLot: '2023-10-01')->update(['last_supplier' => 'Canis Safety']);
        $this->item('BNEVER', 'NIGDY', 2, 200, lastSale: null, oldestLot: '2025-09-01');
        $this->item('BBUT', 'BUT ROBOCZY', 5, 500, lastSale: '2026-03-10', oldestLot: '2025-12-01');

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $codes = fn (string $qs): array => array_column($this->getJson('/api/inventory/board/items?bucket=stock&per_page=50&'.$qs)->assertOk()->json('data'), 'code');

        // bez parametrów — od największej wartości
        $this->assertSame(['AKAPE1000', 'SFILTR', 'BBUT', 'BNEVER'], $codes(''));
        // nazwa jak w wierszu: karta katalogu przed nazwą z XL
        $this->assertSame(['BBUT', 'SFILTR', 'AKAPE1000', 'BNEVER'], $codes('sort=name&dir=asc'));
        // „ani razu” = sprzedaż najdawniej
        $this->assertSame(['BNEVER', 'AKAPE1000', 'SFILTR', 'BBUT'], $codes('sort=last_sale&dir=asc'));
        $this->assertSame(['SFILTR', 'AKAPE1000', 'BBUT', 'BNEVER'], $codes('sort=quantity&dir=desc'));
        $this->assertSame(['AKAPE1000', 'SFILTR', 'BNEVER', 'BBUT'], $codes('sort=oldest_lot&dir=asc'));
        $this->assertSame(['BNEVER', 'BBUT', 'SFILTR', 'AKAPE1000'], $codes('sort=value&dir=asc'));

        $this->assertSame(['AKAPE1000'], $codes('search=hutniczy'));
        $this->assertSame(['SFILTR'], $codes('search=canis'));
        $this->assertSame(['BBUT', 'BNEVER'], $codes('search=obuw'));
        // nazwa miesiąca z ogonkami i bez — data ostatniej sprzedaży 2025-08-01
        $this->assertSame(['SFILTR'], $codes('search='.urlencode('sierpień')));
        $this->assertSame(['SFILTR'], $codes('search=sierpien'));
        // każde słowo musi pasować: nazwa i rok najstarszej dostawy
        $this->assertSame(['SFILTR'], $codes('search='.urlencode('filtr 2023')));
        $this->assertSame([], $codes('search='.urlencode('filtr 2021')));
        // ilość i wartość w pełnych złotych
        $this->assertSame(['SFILTR'], $codes('search=624'));
        $this->assertSame(['AKAPE1000'], $codes('search=7589'));

        $r = $this->getJson('/api/inventory/board/items?bucket=stock&search=obuw')->assertOk();
        $this->assertEquals(['items' => 4, 'value' => 14927], $r->json('totals'));
        $this->assertEquals(['items' => 2, 'value' => 700], $r->json('found'));
        $this->assertSame(2, $r->json('meta.total'));

        $this->getJson('/api/inventory/board/items?bucket=stock&sort=code')->assertUnprocessable();
        $this->getJson('/api/inventory/board/items?bucket=stock&sort=name&dir=up')->assertUnprocessable();
    }

    public function test_moves_window_search_by_every_column_and_sort_by_column(): void
    {
        $bluza = $this->item('ABLUZA', 'BLUZA', 5, 500, lastSale: '2026-01-15', oldestLot: '2025-11-01');
        $this->pair($bluza, '2026-09-01', 'DOEW', 'Domin Ewelina', 100, lotAge: 51);
        $this->pair($bluza, '2026-08-01', 'DOEW', 'Domin Ewelina', 50);
        $this->pair($bluza, '2026-07-01', 'CZAL', 'Alina Czyżyk-Tomaszewska', 30);
        $this->pair($bluza, '2026-05-01', 'NOMA', 'Nowaczek Martyna', 10, note: 'ZAMIANA ROZMIARÓW');

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $values = fn (string $qs): array => array_map('intval', array_column(
            $this->getJson('/api/inventory/board/moves?scope=all&'.$qs)->assertOk()->json('data'), 'value'));

        $this->assertSame([100, 50, 30, 10], $values(''));
        $this->assertSame([10, 30, 50, 100], $values('sort=date&dir=asc'));
        $this->assertSame([10, 30, 50, 100], $values('sort=value&dir=asc'));
        $this->assertSame([30, 100, 50, 10], $values('sort=operator&dir=asc'));
        // z opisem na początku, bez opisu dalej od najnowszego
        $this->assertSame([10, 100, 50, 30], $values('sort=note&dir=asc'));
        $this->assertSame([100, 50, 30, 10], $values('sort=lot_age&dir=desc'));

        $this->assertSame([100, 50], $values('search=domin'));
        $this->assertSame([100, 50], $values('search='.urlencode('bluza domin')));
        $this->assertSame([10], $values('search=zamiana'));
        $this->assertSame([30], $values('search=lipca'));
        // miesiące leżenia partii
        $this->assertSame([100], $values('search=51'));

        $r = $this->getJson('/api/inventory/board/moves?scope=all&search=domin')->assertOk();
        $this->assertEquals(['pairs' => 4, 'value' => 190], $r->json('totals'));
        $this->assertEquals(['pairs' => 2, 'value' => 150], $r->json('found'));
        $this->getJson('/api/inventory/board/moves?sort=quantity')->assertUnprocessable();
    }

    public function test_lot_age_buckets_count_only_stock_lying_at_least_the_threshold(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $x = $this->item('AX', 'BLUZA X', 7, 160, lastSale: '2026-09-01', oldestLot: '2021-09-30');
        // przed pierwszym odczytem partii z XL
        $this->assertNull($this->getJson('/api/inventory/board')->assertOk()->json('lot_age'));

        // granice: dostawa z dnia progu jest już w starszym przedziale (dziś 30.09.2026)
        $this->lots($x, [['01H', '2026-09-01', 1, 10], ['01H', '2026-03-30', 2, 20], ['01H', '2025-09-30', 3, 30], ['01H', '2021-09-30', 1, 100]]);
        // bez wartości partii: ilość × cena ostatniej PZ
        $y = $this->item('SY', 'FILTR Y', 5, 20, lastSale: null, oldestLot: '2023-01-01');
        $this->lots($y, [['01H', '2023-01-01', 5, null]]);
        ErpItemPurchase::query()->create([
            'erp_item_id' => $y->id, 'document_type' => 1489, 'document_id' => 9, 'document_line' => 1, 'purchased_at' => '2023-01-01',
            'supplier' => 'X', 'quantity' => 5, 'document_unit' => 'szt', 'net_value_pln' => 20, 'unit_price_pln' => 4, 'document_price' => 4, 'currency' => 'PLN',
        ]);
        // usługowy 01M
        $z = $this->item('TZ', 'GAŚNICA Z', 1, 1000, lastSale: null, oldestLot: '2022-01-01', warehouses: [
            ['code' => '01M', 'name' => 'Magazyn materiałów - Rzeszów', 'quantity' => 1, 'value' => 1000, 'oldest_lot' => '2022-01-01'],
        ]);
        $this->lots($z, [['01M', '2022-01-01', 1, 1000]]);
        $w = $this->item('HW', 'BEZ DATY', 1, 5, lastSale: null, oldestLot: null);
        $this->lots($w, [['01H', null, 1, 5]]);
        $v = $this->item('BV', 'KRAKÓW', 1, 7, lastSale: null, oldestLot: '2024-01-01', warehouses: [
            ['code' => '15H', 'name' => 'Magazyn HANDEL Kraków', 'quantity' => 1, 'value' => 7, 'oldest_lot' => '2024-01-01'],
        ]);
        $this->lots($v, [['15H', '2024-01-01', 1, 7]]);
        $removed = $this->item('AR', 'USUNIĘTY', 1, 999, lastSale: null, oldestLot: '2020-01-01');
        $this->lots($removed, [['01H', '2020-01-01', 1, 999]]);
        $removed->update(['removed_at' => now()]);

        $lotAge = fn (string $query = ''): array => $this->getJson('/api/inventory/board'.$query)->assertOk()->json('lot_age');
        $byKey = fn (array $a): array => collect($a['buckets'])->mapWithKeys(fn (array $b) => [$b['key'] => [$b['items'], $b['value']]])->all();

        // narastająco: tylko sztuki z dostaw leżących co najmniej próg; świeża dostawa AX z 1.09 nie liczy się nigdzie
        $trade = $lotAge();
        $this->assertEquals([
            'lot_age_6' => [3, 177], 'lot_age_12' => [3, 157], 'lot_age_24' => [3, 127], 'lot_age_36' => [2, 120],
            'lot_age_48' => [1, 100], 'lot_age_60' => [1, 100], 'lot_age_unknown' => [1, 5],
        ], $byKey($trade));
        // value — wszystkie dostawy na stanie (podstawa %), także świeże
        $this->assertEquals(['items' => 4, 'value' => 192, 'value_unknown_items' => 0], array_intersect_key($trade, array_flip(['items', 'value', 'value_unknown_items'])));
        $this->assertSame([60, null], [$trade['buckets'][5]['from_months'], $trade['buckets'][5]['to_months']]);

        $rzeszow = $lotAge('?location=01');
        $this->assertEquals([2, 120], $byKey($rzeszow)['lot_age_24']);
        $this->assertEquals(185, $rzeszow['value']);
        $service = $lotAge('?warehouses=service');
        $this->assertEquals(['items' => 1, 'value' => 1000], array_intersect_key($service, array_flip(['items', 'value'])));
        $this->assertEquals([[1, 1000], [0, 0]], [$byKey($service)['lot_age_48'], $byKey($service)['lot_age_60']]);

        // okno: ilość, wartość i najstarsza dostawa — tylko sztuki leżące co najmniej próg
        $r = $this->getJson('/api/inventory/board/items?bucket=lot_age_12')->assertOk();
        $this->assertSame('Towar, który leży w magazynie ponad rok (magazyny handlowe)', $r->json('title'));
        $this->assertEquals(['items' => 3, 'value' => 157], $r->json('totals'));
        $this->assertSame(['AX', 4, 130, '2021-09-30'], [$r->json('data.0.code'), (int) $r->json('data.0.quantity'), (int) $r->json('data.0.value'), $r->json('data.0.oldest_lot_at')]);
        $pz = $this->getJson('/api/inventory/board/items?bucket=lot_age_36')->assertOk();
        $this->assertEquals(['SY', 20, 4], [$pz->json('data.1.code'), $pz->json('data.1.value'), $pz->json('data.1.unit_cost')]);
        $this->assertSame(['HW'], array_column($this->getJson('/api/inventory/board/items?bucket=lot_age_unknown')->json('data'), 'code'));
        $this->assertSame(['BV'], array_column($this->getJson('/api/inventory/board/items?bucket=lot_age_24&location=15')->json('data'), 'code'));
        // dawny przedział „do pół roku” już nie istnieje
        $this->getJson('/api/inventory/board/items?bucket=lot_age_0_6')->assertUnprocessable();
        // wyszukiwanie i sortowanie działają na wartości z okresu
        $this->assertSame(1, $this->getJson('/api/inventory/board/items?bucket=lot_age_60&search=100')->json('found.items'));
        $this->assertSame(0, $this->getJson('/api/inventory/board/items?bucket=lot_age_60&search=160')->json('found.items'));
        $this->getJson('/api/inventory/board/items?bucket=lot_age_60&sort=quantity&dir=asc')->assertOk();
    }

    public function test_warehouse_split_command_recomputes_from_stored_breakdown(): void
    {
        $item = $this->item('TGAS', 'GAŚNICA', 100, 1000, lastSale: '2025-01-01', oldestLot: '2020-01-01', warehouses: [
            ['code' => '01H', 'name' => 'HANDEL', 'quantity' => 60, 'value' => 600, 'oldest_lot' => '2024-06-01'],
            ['code' => '05X', 'name' => 'Nowy', 'quantity' => 40, 'value' => 400, 'oldest_lot' => '2020-01-01'],
        ]);
        $this->assertSame('0.0000', $item->fresh()->stock_service);

        ErpWarehouse::query()->create(['code' => '05X', 'name' => 'Nowy', 'is_service' => true]);
        DB::table(WarehouseLocations::TABLE)->delete();
        $this->assertSame(0, Artisan::call('erp:warehouse-split'));
        // stan na magazyn (oddziały) odbudowany z zapisanego rozbicia
        $this->assertSame(['01H' => '01', '05X' => '05'], DB::table(WarehouseLocations::TABLE)
            ->where('erp_item_id', $item->id)->orderBy('warehouse_code')->pluck('location', 'warehouse_code')->all());

        $item->refresh();
        $this->assertSame(['40.0000', '400.00', '2024-06-01', '2020-01-01'], [
            $item->stock_service, $item->stock_service_value, $item->oldest_lot_trade_at?->toDateString(), $item->oldest_lot_service_at?->toDateString(),
        ]);
        // stare rozbicie bez dat na magazyn: data towaru tylko, gdy cały stan jest po jednej stronie
        $this->assertSame(
            ['stock_service' => 0.0, 'stock_service_value' => 0.0, 'oldest_lot_trade_at' => '2021-01-01', 'oldest_lot_service_at' => null],
            WarehouseSplit::compute([['code' => '01H', 'quantity' => 3, 'value' => 30]], ['01M'], '2021-01-01'),
        );
    }

    /** @param  list<array<string, mixed>>|null  $warehouses  rozbicie; bez niego cały stan w 01H */
    private function item(string $code, string $name, float $stock, float $value, ?string $lastSale, ?string $oldestLot, ?array $warehouses = null): ErpItem
    {
        $warehouses ??= $stock > 0 ? [['code' => '01H', 'name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => $stock, 'value' => $value]] : [];
        $split = WarehouseSplit::compute($warehouses, ErpWarehouse::serviceCodes(), $oldestLot);

        $item = ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => $name, 'unit' => 'szt', 'archived' => false,
            'stock_trade' => $stock, 'stock_total' => $stock, 'stock_value' => $value, 'oldest_lot_at' => $oldestLot,
            'stock_by_warehouse' => $warehouses, ...$split,
            'last_sale_at' => $lastSale, 'synced_at' => now(),
        ]);
        WarehouseLocations::replace((int) $item->id, $warehouses);

        return $item;
    }

    /** @param  list<array{0: string, 1: ?string, 2: float|int, 3: float|int|null}>  $lots  [magazyn, dzień przyjęcia, ilość, wartość] */
    private function lots(ErpItem $item, array $lots): void
    {
        foreach ($lots as [$code, $day, $quantity, $value]) {
            DB::table(StockLots::TABLE)->insert([
                'erp_item_id' => $item->id, 'warehouse_code' => $code, 'location' => WarehouseLocations::of($code),
                'received_at' => $day, 'quantity' => $quantity, 'value' => $value,
            ]);
        }
    }

    private function pair(ErpItem $item, string $rwDate, string $operator, string $name, float $value, bool $sameFeature = true, ?string $note = null, int $gap = 0, ?int $lotAge = 12, string $warehouse = '01H', bool $unknownLot = false): void
    {
        $rw = $this->doc++;
        $pw = $this->doc++;
        ErpRwPwPair::query()->create([
            'xl_gid' => $item->xl_gid, 'erp_item_id' => $item->id,
            'rw_document_id' => $rw, 'rw_number' => 'RW-01H/'.$rw.'/26/09', 'rw_date' => $rwDate, 'rw_warehouse' => $warehouse,
            'rw_quantity' => 1, 'rw_value' => $value, 'rw_operator' => $operator, 'rw_approver' => $operator,
            'rw_operator_name' => $name, 'rw_note' => $note, 'rw_lot_age_months' => $unknownLot ? null : $lotAge,
            'pw_document_id' => $pw, 'pw_number' => 'PW-01H/'.$pw.'/26/09', 'pw_date' => now()->parse($rwDate)->addDays($gap)->toDateString(),
            'pw_warehouse' => $warehouse, 'pw_quantity' => 1, 'pw_value' => $value, 'pw_operator' => $operator, 'pw_approver' => $operator,
            'gap_days' => $gap, 'same_value' => true, 'same_warehouse' => true, 'same_feature' => $sameFeature, 'synced_at' => now(),
        ]);
    }
}
