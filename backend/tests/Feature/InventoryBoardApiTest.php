<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ErpRwPwPair;
use App\Models\ErpWarehouse;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\WarehouseSplit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
            ['code' => '13G', 'name' => 'Magazyn gaśnic - Stalowa Wola', 'quantity' => 5, 'value' => 50, 'oldest_lot' => '2022-01-01'],
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

    public function test_warehouse_split_command_recomputes_from_stored_breakdown(): void
    {
        $item = $this->item('TGAS', 'GAŚNICA', 100, 1000, lastSale: '2025-01-01', oldestLot: '2020-01-01', warehouses: [
            ['code' => '01H', 'name' => 'HANDEL', 'quantity' => 60, 'value' => 600, 'oldest_lot' => '2024-06-01'],
            ['code' => '05X', 'name' => 'Nowy', 'quantity' => 40, 'value' => 400, 'oldest_lot' => '2020-01-01'],
        ]);
        $this->assertSame('0.0000', $item->fresh()->stock_service);

        ErpWarehouse::query()->create(['code' => '05X', 'name' => 'Nowy', 'is_service' => true]);
        $this->assertSame(0, Artisan::call('erp:warehouse-split'));

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

        return ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => $name, 'unit' => 'szt', 'archived' => false,
            'stock_trade' => $stock, 'stock_total' => $stock, 'stock_value' => $value, 'oldest_lot_at' => $oldestLot,
            'stock_by_warehouse' => $warehouses, ...$split,
            'last_sale_at' => $lastSale, 'synced_at' => now(),
        ]);
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
