<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ErpRwPwPair;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Raport zapasów dla zarządu: te same reguły co lista Zalegające, same fakty, nazwiska przy „odmładzaniu”. */
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

    public function test_board_role_sees_only_the_report(): void
    {
        $board = Role::findOrCreate('zarzad', 'web');
        $board->givePermissionTo(Permission::findOrCreate('inventory.report.view', 'web'));
        $user = User::factory()->create();
        $user->assignRole($board);
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/board')->assertOk();
        $this->getJson('/api/inventory')->assertForbidden();
        $this->getJson('/api/inventory/rw-pw')->assertForbidden();

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/inventory/board')->assertForbidden();
    }

    public function test_totals_buckets_top_list_and_internal_moves(): void
    {
        $kaptur = $this->item('AKAPE1000', 'KAPTUR METALURGICZNY', 10, 7589, lastSale: '2021-12-30', oldestLot: '2021-06-01');
        $card = Product::query()->create(['sku' => 'E1000', 'name' => 'Kaptur metalurgiczny E1000', 'manufacturer' => 'X', 'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0]);
        ErpItemLink::query()->create(['erp_item_id' => $kaptur->id, 'product_id' => $card->id, 'status' => ErpItemLink::STATUS_CONFIRMED, 'method' => ErpItemLink::METHOD_MANUAL]);
        $this->item('FILTR', 'FILTR BLS', 624, 6638, lastSale: '2025-08-01', oldestLot: '2023-10-01');
        // sprzedaż 8 mies. temu: tylko w „pół roku”
        $this->item('BLUZA', 'BLUZA', 5, 500, lastSale: '2026-01-15', oldestLot: '2025-11-01');
        // nigdy nie sprzedany, leży od roku
        $this->item('NEVER', 'NIGDY', 2, 200, lastSale: null, oldestLot: '2025-09-01');
        // nigdy nie sprzedany, świeża dostawa — to nie zaleganie
        $this->item('NEW', 'NOWY', 3, 300, lastSale: null, oldestLot: '2026-09-01');
        $this->item('SOLD', 'SCHODZI', 7, 700, lastSale: '2026-09-20', oldestLot: '2026-08-01');
        $this->item('ZERO', 'BRAK STANU', 0, 0, lastSale: '2020-01-01', oldestLot: '2019-01-01');

        $bluza = ErpItem::query()->where('code', 'BLUZA')->sole();
        // bez wyjaśnienia (ta sama cecha, puste uwagi RW)
        $this->pair($bluza, '2026-09-01', 'DOEW', 'Dobosz Ewa', 100);
        $this->pair($bluza, '2026-08-01', 'DOEW', 'Dobosz Ewa', 50);
        $this->pair($bluza, '2026-07-01', 'CZAL', 'Alina Czyżyk-Tomaszewska', 30);
        // zmiana rozmiaru, uwagi, za długi odstęp, starsze niż rok — liczą się tylko do „wszystkich” albo wcale
        $this->pair($bluza, '2026-06-01', 'NOMA', 'Nowaczek Martyna', 10, sameFeature: false);
        $this->pair($bluza, '2026-05-01', 'NOMA', 'Nowaczek Martyna', 10, note: 'ZAMIANA ROZMIARÓW');
        $this->pair($bluza, '2026-04-01', 'NOMA', 'Nowaczek Martyna', 10, gap: 10);
        $this->pair($bluza, '2025-09-01', 'NOMA', 'Nowaczek Martyna', 10);

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
        $this->assertEquals([
            ['months' => 12, 'items' => 3, 'value' => 14427],
            ['months' => 36, 'items' => 1, 'value' => 7589],
            // partia kaptura z 06.2021 — ponad 5 lat
            ['months' => 60, 'items' => 1, 'value' => 7589],
        ], $r->json('lot_age'));

        $this->assertSame(['AKAPE1000', 'FILTR', 'NEVER'], array_column($r->json('top_unsold'), 'code'));
        $this->assertNull($r->json('top_unsold.2.last_sale_at'));
        $this->assertSame('Kaptur metalurgiczny E1000', $r->json('top_unsold.0.card_name'));
        $this->assertSame('2021-12-30', $r->json('top_unsold.0.last_sale_at'));
        $this->assertNull($r->json('top_unsold.1.card_name'));

        $this->assertEquals([
            'from' => '2025-09-30', 'total' => 5, 'unexplained' => 3, 'unexplained_value' => 180,
            'people' => [['name' => 'Dobosz Ewa', 'count' => 2], ['name' => 'Alina Czyżyk-Tomaszewska', 'count' => 1]],
        ], $r->json('internal_moves'));
    }

    private function item(string $code, string $name, float $stock, float $value, ?string $lastSale, ?string $oldestLot): ErpItem
    {
        return ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => $name, 'unit' => 'szt', 'archived' => false,
            'stock_trade' => $stock, 'stock_total' => $stock, 'stock_value' => $value, 'oldest_lot_at' => $oldestLot,
            'last_sale_at' => $lastSale, 'synced_at' => now(),
        ]);
    }

    private function pair(ErpItem $item, string $rwDate, string $operator, string $name, float $value, bool $sameFeature = true, ?string $note = null, int $gap = 0): void
    {
        $rw = $this->doc++;
        $pw = $this->doc++;
        ErpRwPwPair::query()->create([
            'xl_gid' => $item->xl_gid, 'erp_item_id' => $item->id,
            'rw_document_id' => $rw, 'rw_number' => 'RW-01H/'.$rw.'/26/09', 'rw_date' => $rwDate, 'rw_warehouse' => '01H',
            'rw_quantity' => 1, 'rw_value' => $value, 'rw_operator' => $operator, 'rw_approver' => $operator,
            'rw_operator_name' => $name, 'rw_note' => $note,
            'pw_document_id' => $pw, 'pw_number' => 'PW-01H/'.$pw.'/26/09', 'pw_date' => now()->parse($rwDate)->addDays($gap)->toDateString(),
            'pw_warehouse' => '01H', 'pw_quantity' => 1, 'pw_value' => $value, 'pw_operator' => $operator, 'pw_approver' => $operator,
            'gap_days' => $gap, 'same_value' => true, 'same_warehouse' => true, 'same_feature' => $sameFeature, 'synced_at' => now(),
        ]);
    }
}
