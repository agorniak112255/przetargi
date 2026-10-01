<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpWarehouse;
use App\Models\User;
use App\Services\Erp\ErpXlGateway;
use App\Services\Erp\InventorySnapshots;
use App\Services\Erp\WarehouseLocations;
use App\Services\Erp\WarehouseSplit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

/** Historia zapasów: zapis dnia po pełnym odczycie z XL (te same liczby co kafelki) i punkty wykresu w raporcie. */
final class InventorySnapshotTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // 02:30 UTC = 04:30 w Polsce — jak nocny odczyt
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(2, 30));
    }

    public function test_saves_the_day_with_board_numbers_for_every_location_and_scope(): void
    {
        $this->item('AKAPTUR', 10, 1000, lastSale: '2025-01-10', oldestLot: '2024-06-01');
        $this->item('BBUTY', 5, 500, lastSale: '2026-09-20', oldestLot: '2026-03-01');
        $this->item('SFILTR', 4, 400, lastSale: '2024-02-01', oldestLot: '2023-01-01', warehouses: [
            ['code' => '11H', 'name' => 'Magazyn HANDEL - Tarnów', 'quantity' => 3, 'value' => 300],
            ['code' => '01M', 'name' => 'Magazyn materiałów - Rzeszów', 'quantity' => 1, 'value' => 100],
        ]);

        $this->assertSame(0, Artisan::call('erp:inventory-snapshot'));

        // '' + oddziały z nazwy (6) × 3 zakresy magazynów
        $this->assertSame(21, DB::table(InventorySnapshots::TABLE)->where('taken_on', '2026-10-02')->count());
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        foreach ([[null, 'trade'], [null, 'all'], ['01', 'trade'], ['11', 'all'], ['01', 'service']] as [$location, $scope]) {
            $board = $this->getJson('/api/inventory/board?warehouses='.$scope.($location ? '&location='.$location : ''))->assertOk();
            $buckets = $this->snapshot($location ?? '', $scope)['buckets'];
            $this->assertEquals($board->json('stock'), array_intersect_key($buckets['stock'], ['items' => 1, 'value' => 1]), "$location/$scope stock");
            foreach ($board->json('no_sale') as $b) {
                $this->assertEquals(['items' => $b['items'], 'value' => $b['value']], array_intersect_key($buckets['no_sale_'.$b['months']], ['items' => 1, 'value' => 1]), "$location/$scope no_sale_{$b['months']}");
            }
            $this->assertEquals($board->json('never_sold'), array_intersect_key($buckets['never_sold'], ['items' => 1, 'value' => 1]));
        }
        // oddział bez towaru dostaje zera, nie znika z historii
        $this->assertSame(['items' => 0, 'value' => 0, 'value_unknown' => 0], $this->snapshot('20', 'all')['buckets']['stock']);
        $this->assertContains('01M', $this->snapshot('', 'all')['service_codes']);

        $warehouses = DB::table(InventorySnapshots::WAREHOUSE_TABLE)->where('taken_on', '2026-10-02')->orderBy('warehouse_code')
            ->get(['warehouse_code', 'location', 'is_service', 'items', 'value'])->map(fn ($r) => (array) $r)->all();
        $this->assertEquals([
            ['warehouse_code' => '01H', 'location' => '01', 'is_service' => 0, 'items' => 2, 'value' => 1500],
            ['warehouse_code' => '01M', 'location' => '01', 'is_service' => 1, 'items' => 1, 'value' => 100],
            ['warehouse_code' => '11H', 'location' => '11', 'is_service' => 0, 'items' => 1, 'value' => 300],
        ], $warehouses);
    }

    public function test_skips_a_partial_reading_and_never_overwrites_the_day_without_force(): void
    {
        $a = $this->item('AKAPTUR', 10, 1000, lastSale: '2025-01-10', oldestLot: '2024-06-01');
        $b = $this->item('BBUTY', 5, 500, lastSale: '2026-09-20', oldestLot: '2026-03-01');
        // przerwana synchronizacja: jeden towar ma jeszcze stan z wczoraj
        $b->forceFill(['stock_synced_at' => now()->subDay()])->save();
        $this->assertSame('stale', app(InventorySnapshots::class)->take()['status']);
        $this->assertSame(0, DB::table(InventorySnapshots::TABLE)->count());

        $b->forceFill(['stock_synced_at' => now()])->save();
        $this->assertSame('saved', app(InventorySnapshots::class)->take()['status']);

        // ręczny odczyt w dzień nie zastępuje nocnego obrazu
        $a->forceFill(['stock_value' => 9999])->save();
        $this->assertSame('exists', app(InventorySnapshots::class)->take()['status']);
        $this->assertSame(1500, (int) $this->snapshot('', 'all')['buckets']['stock']['value']);

        $this->assertSame(0, Artisan::call('erp:inventory-snapshot', ['--force' => true]));
        $this->assertSame(10499, (int) $this->snapshot('', 'all')['buckets']['stock']['value']);
        $this->assertSame(21, DB::table(InventorySnapshots::TABLE)->count());
    }

    public function test_full_sync_saves_the_day_and_a_snapshot_failure_does_not_fail_the_sync(): void
    {
        $xl = new FakeErpXlGateway;
        $xl->items = [FakeErpXlGateway::item(15785, 'SOK9301145', 'GOGLE UVEX', '')];
        $xl->stockRows = [['gid' => 15785, 'warehouse_code' => '01H', 'warehouse_name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 8.0]];
        $this->app->instance(ErpXlGateway::class, $xl);

        $this->assertSame(0, Artisan::call('erp:sync', ['--limit' => 5]));
        $this->assertSame(0, DB::table(InventorySnapshots::TABLE)->count(), 'próba z --limit nie zapisuje historii');

        $this->assertSame(0, Artisan::call('erp:sync'));
        $this->assertSame(21, DB::table(InventorySnapshots::TABLE)->where('taken_on', '2026-10-02')->count());

        // zapis historii pada (np. baza zajęta) — synchronizacja i tak kończy się sukcesem
        DB::listen(static function ($query): void {
            if (str_contains($query->sql, InventorySnapshots::TABLE)) {
                throw new RuntimeException('baza zajęta');
            }
        });
        $this->assertSame(0, Artisan::call('erp:sync'));
        $this->assertStringContainsString('Zapis historii zapasów przerwany: baza zajęta', Artisan::output());
    }

    public function test_history_points_range_weekly_and_comparison(): void
    {
        $board = Role::findOrCreate('zarzad', 'web');
        $board->givePermissionTo(Permission::findOrCreate('inventory.report.view', 'web'));
        $user = User::factory()->create();
        $user->assignRole($board);
        Sanctum::actingAs($user);

        $this->getJson('/api/inventory/board/history')->assertOk()
            ->assertJsonPath('points', [])->assertJsonPath('first_date', null)->assertJsonPath('compare', null);

        foreach (['2026-09-01' => [1000, 600], '2026-09-15' => [900, 500], '2026-10-01' => [800, 300]] as $day => [$stock, $unsold]) {
            $this->storeDay($day, '', 'trade', $stock, $unsold);
            $this->storeDay($day, '01', 'trade', $stock / 2, $unsold / 2);
            $this->storeDay($day, '01', 'all', $stock, $unsold);
            DB::table(InventorySnapshots::WAREHOUSE_TABLE)->insert([
                ['taken_on' => $day, 'warehouse_code' => '01H', 'location' => '01', 'is_service' => false, 'source' => 'live', 'items' => 3, 'quantity' => 10, 'value' => $stock / 2, 'value_unknown' => 0],
                ['taken_on' => $day, 'warehouse_code' => '01M', 'location' => '01', 'is_service' => true, 'source' => 'live', 'items' => 1, 'quantity' => 1, 'value' => 7, 'value_unknown' => 0],
            ]);
        }

        // domyślnie 30 dni do ostatniego zapisu
        $r = $this->getJson('/api/inventory/board/history')->assertOk();
        $this->assertSame(['2026-09-01', '2026-10-01'], [$r->json('from'), $r->json('to')]);
        $this->assertSame('2026-09-01', $r->json('first_date'));
        $this->assertSame(['2026-09-01', '2026-09-15', '2026-10-01'], array_column($r->json('points'), 'date'));
        $this->assertEquals(['items' => 1, 'value' => 300], $r->json('points.2.no_sale_12'));

        $r = $this->getJson('/api/inventory/board/history?location=01&from=2026-09-10&to=2026-10-31')->assertOk();
        $this->assertSame(['2026-09-15', '2026-10-01'], array_column($r->json('points'), 'date'));
        $this->assertSame(['2026-09-15', '2026-10-01'], [$r->json('compare.start_date'), $r->json('compare.end_date')]);
        $this->assertSame(['Wszystkie oddziały', 'Rzeszów'], array_column($r->json('compare.locations'), 'name'));
        $this->assertEquals(['items' => 2, 'value' => 450], $r->json('compare.locations.1.start.stock'));
        $this->assertEquals(['items' => 1, 'value' => 150], $r->json('compare.locations.1.end.no_sale_12'));
        // magazyny handlowe oddziału: bez 01M
        $this->assertSame(['01H'], array_column($r->json('compare.warehouses'), 'code'));
        $this->assertEquals(['items' => 3, 'value' => 400], $r->json('compare.warehouses.0.end'));

        $this->getJson('/api/inventory/board/history?warehouses=all&location=01&from=2026-09-01&to=2026-09-01')->assertOk()
            ->assertJsonPath('compare.warehouses.1.code', '01M')
            ->assertJsonPath('compare.warehouses.1.start.value', 7);
        $this->getJson('/api/inventory/board/history?from=2026-13-01')->assertUnprocessable();

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/inventory/board/history')->assertForbidden();
    }

    public function test_history_lot_age_thresholds_from_interval_and_cumulative_snapshots(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        // pierwszy zapis nocny (wersja 1): przedziały sumujące się — próg = suma od progu wzwyż, bez liczby towarów
        $interval = fn (string $key, ?int $from, ?int $to, float $value): array => ['key' => $key, 'from_months' => $from, 'to_months' => $to, 'items' => 1, 'value' => $value];
        DB::table(InventorySnapshots::TABLE)->insert(['taken_on' => '2026-10-01', 'location' => '', 'scope' => 'trade', 'source' => 'live', 'totals' => json_encode([
            'version' => 1, 'buckets' => ['stock' => ['items' => 3, 'value' => 1000]],
            'lot_age' => ['buckets' => [$interval('lot_age_0_6', 0, 6, 500), $interval('lot_age_6_12', 6, 12, 200), $interval('lot_age_12_24', 12, 24, 100),
                $interval('lot_age_60', 60, null, 50), $interval('lot_age_unknown', null, null, 7)], 'items' => 3, 'value' => 857, 'value_unknown_items' => 0],
        ])]);
        // wersja 2: progi wprost
        DB::table(InventorySnapshots::TABLE)->insert(['taken_on' => '2026-10-02', 'location' => '', 'scope' => 'trade', 'source' => 'live', 'totals' => json_encode([
            'version' => 2, 'buckets' => ['stock' => ['items' => 3, 'value' => 990]],
            'lot_age' => ['buckets' => [['key' => 'lot_age_6', 'from_months' => 6, 'to_months' => null, 'items' => 2, 'value' => 340],
                ['key' => 'lot_age_12', 'from_months' => 12, 'to_months' => null, 'items' => 1, 'value' => 140]], 'items' => 3, 'value' => 990, 'value_unknown_items' => 0],
        ])]);

        $r = $this->getJson('/api/inventory/board/history?from=2026-10-01&to=2026-10-02')->assertOk();
        $this->assertEquals([['items' => null, 'value' => 350], ['items' => 2, 'value' => 340]], array_column($r->json('points'), 'lot_age_6'));
        $this->assertEquals([['items' => null, 'value' => 150], ['items' => 1, 'value' => 140]], array_column($r->json('points'), 'lot_age_12'));
        $this->assertEquals(['items' => 1, 'value' => 140], $r->json('compare.locations.0.end.lot_age_12'));
    }

    public function test_long_range_shows_one_point_per_week(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $day = now()->setDate(2025, 1, 1)->toImmutable();
        for ($i = 0; $i < 420; $i++) {
            $this->storeDay($day->addDays($i)->toDateString(), '', 'trade', 1000 - $i, 100);
        }

        $r = $this->getJson('/api/inventory/board/history?from=2025-01-01&to=2026-12-31')->assertOk();
        $this->assertTrue($r->json('weekly'));
        $dates = array_column($r->json('points'), 'date');
        $this->assertLessThan(70, count($dates));
        // ostatni zapisany dzień zostaje ostatnim punktem
        $this->assertSame($day->addDays(419)->toDateString(), end($dates));
    }

    private function storeDay(string $day, string $location, string $scope, float $stock, float $unsold): void
    {
        DB::table(InventorySnapshots::TABLE)->insert([
            'taken_on' => $day, 'location' => $location, 'scope' => $scope, 'source' => 'live',
            'totals' => json_encode(['version' => 1, 'buckets' => [
                'stock' => ['items' => 2, 'value' => $stock, 'value_unknown' => 0],
                'no_sale_12' => ['items' => 1, 'value' => $unsold, 'value_unknown' => 0],
            ]]),
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $location, string $scope): array
    {
        $json = DB::table(InventorySnapshots::TABLE)->where('location', $location)->where('scope', $scope)->value('totals');

        return json_decode((string) $json, true);
    }

    /** @param  list<array<string, mixed>>|null  $warehouses */
    private function item(string $code, float $stock, float $value, ?string $lastSale, ?string $oldestLot, ?array $warehouses = null): ErpItem
    {
        $warehouses ??= [['code' => '01H', 'name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => $stock, 'value' => $value]];
        $split = WarehouseSplit::compute($warehouses, ErpWarehouse::serviceCodes(), $oldestLot);
        $item = ErpItem::query()->create([
            'xl_gid' => $this->gid++, 'code' => $code, 'name' => $code, 'unit' => 'szt', 'archived' => false,
            'stock_trade' => $stock, 'stock_total' => $stock, 'stock_value' => $value, 'oldest_lot_at' => $oldestLot,
            'stock_by_warehouse' => $warehouses, ...$split,
            'last_sale_at' => $lastSale, 'synced_at' => now(), 'stock_synced_at' => now(),
        ]);
        WarehouseLocations::replace((int) $item->id, $warehouses);

        return $item;
    }
}
