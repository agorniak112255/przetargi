<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpWarehouse;
use App\Services\Erp\ErpXlClient;
use App\Services\Erp\ErpXlGateway;
use App\Services\Erp\InventoryHistoryRebuild;
use App\Services\Erp\InventorySnapshots;
use App\Services\Erp\WarehouseLocations;
use App\Services\Erp\WarehouseSplit;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

/** Historia zapasów wstecz z ruchów partii XL: stan na początek dnia = dziś − ruchy od tego dnia; kontrola szwu. */
final class InventoryHistoryRebuildTest extends TestCase
{
    use RefreshDatabase;

    private FakeErpXlGateway $xl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->xl = new FakeErpXlGateway;
        $this->app->instance(ErpXlGateway::class, $this->xl);
        // nocny zapis 2.10.2026 (04:30 w Polsce), odtwarzanie wieczorem tego dnia
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(2, 30));
    }

    public function test_rebuilds_days_back_from_lot_moves_and_saves_after_seam_check(): void
    {
        $this->liveItemAndSnapshot();
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(18, 0));
        $unknown = ['gid' => 1, 'dst' => 10, 'warehouse_code' => '01H', 'received_at' => $this->ts('2024-01-01'), 'type' => 9999, 'day' => $this->day('2026-10-01'), 'quantity' => 1.0, 'cost' => 1.0];
        $this->xl->historyMoves[] = $unknown;

        $sleeps = [];
        $r = app(InventoryHistoryRebuild::class)->run(3, workSeconds: 0.0, pauseSeconds: 5.0, sleep: function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });

        $this->assertSame('saved', $r['status'], json_encode($r['seam']));
        $this->assertTrue($r['seam_ok']);
        $this->assertSame(['2026-09-29', '2026-09-30', '2026-10-01'], $r['days']);
        $this->assertSame([9999 => 1], $r['unknown_types']);
        $this->assertSame(0, $r['negative_lots']);
        // przerwa po każdej porcji pracy (tu: po każdej paczce)
        $this->assertSame([5.0], $sleeps);

        // początek 1.10: sprzedaż FS i MM z 1.10 cofnięte → 01H: partia 10 = 7 szt (70 zł), partia 11 = 3 szt (30 zł)
        $this->assertEquals(['items' => 1, 'value' => 100, 'value_unknown' => 0], $this->bucket('2026-10-01', '', 'all', 'stock'));
        $this->assertEquals(['items' => 1, 'value' => 100, 'value_unknown' => 0], $this->bucket('2026-10-01', '01', 'trade', 'stock'));
        $this->assertEquals(['items' => 0, 'value' => 0, 'value_unknown' => 0], $this->bucket('2026-10-01', '15', 'all', 'stock'));
        // ostatnia sprzedaż przed 1.10 — czerwiec 2025: ponad rok bez sprzedaży; partia z 2024 r. — nie 3 lata
        $this->assertEquals(['items' => 1, 'value' => 100, 'value_unknown' => 0], $this->bucket('2026-10-01', '', 'all', 'no_sale_12'));
        $this->assertEquals(['items' => 0, 'value' => 0, 'value_unknown' => 0], $this->bucket('2026-10-01', '', 'all', 'stale_36'));
        // leży ponad pół roku / rok: tylko partia 10 z 2024 r. (7 szt, 70 zł); partia 11 z 30.09 — świeża
        $lotAge = json_decode((string) DB::table(InventorySnapshots::TABLE)->where('taken_on', '2026-10-01')->where('location', '')->where('scope', 'all')->value('totals'), true)['lot_age'];
        $this->assertEquals(['key' => 'lot_age_6', 'from_months' => 6, 'to_months' => null, 'items' => 1, 'value' => 70], $lotAge['buckets'][0]);
        $this->assertEquals([1, 70], [$lotAge['buckets'][1]['items'], $lotAge['buckets'][1]['value']]);
        // 33 miesiące: ponad 2 lata tak, ponad 3 lata nie
        $this->assertEquals([1, 70], [$lotAge['buckets'][2]['items'], $lotAge['buckets'][2]['value']]);
        $this->assertEquals([0, 0], [$lotAge['buckets'][3]['items'], $lotAge['buckets'][3]['value']]);
        $this->assertSame(['lot_age_6', 'lot_age_12', 'lot_age_24', 'lot_age_36', 'lot_age_48', 'lot_age_60'], array_column($lotAge['buckets'], 'key'));
        // początek 30.09: także PZ z 30.09 cofnięta → tylko partia 10
        $this->assertEquals(['items' => 1, 'value' => 70, 'value_unknown' => 0], $this->bucket('2026-09-30', '', 'all', 'stock'));
        $this->assertEquals(['items' => 1, 'value' => 70, 'value_unknown' => 0], $this->bucket('2026-09-29', '', 'trade', 'stock'));
        $this->assertSame('xl_history', DB::table(InventorySnapshots::TABLE)->where('taken_on', '2026-09-29')->value('source'));
        $this->assertEquals(
            ['items' => 1, 'quantity' => 10, 'value' => 100],
            (array) DB::table(InventorySnapshots::WAREHOUSE_TABLE)->where('taken_on', '2026-10-01')->where('warehouse_code', '01H')->first(['items', 'quantity', 'value']),
        );
        // zapis nocny nietknięty
        $this->assertSame('live', DB::table(InventorySnapshots::TABLE)->where('taken_on', '2026-10-02')->value('source'));
        $this->assertSame(21, DB::table(InventorySnapshots::TABLE)->where('taken_on', '2026-10-02')->count());

        // drugi przebieg: dni już są — bez zapytań do XL
        $calls = $this->xl->historyCalls;
        $this->assertSame('nothing', app(InventoryHistoryRebuild::class)->run(3)['status']);
        $this->assertSame($calls, $this->xl->historyCalls);
        $this->assertSame(0, Artisan::call('erp:inventory-history', ['--days' => 3, '--pause' => 0]));
        $this->assertStringContainsString('Nic do odtworzenia', Artisan::output());

        // --force odtwarza dni historii od nowa (zapis nocny dalej nietknięty)
        $this->assertSame(0, Artisan::call('erp:inventory-history', ['--days' => 3, '--pause' => 0, '--force' => true]));
        $this->assertSame(21 * 4, DB::table(InventorySnapshots::TABLE)->count());
        $this->assertSame(21, DB::table(InventorySnapshots::TABLE)->where('source', 'live')->count());
    }

    public function test_seam_mismatch_saves_nothing_and_dry_run_never_writes(): void
    {
        $this->liveItemAndSnapshot();
        // zapis nocny mówi co innego niż partie XL (np. inny dzień odczytu)
        $row = DB::table(InventorySnapshots::TABLE)->where('location', '')->where('scope', 'all')->first();
        $totals = json_decode((string) $row->totals, true);
        $totals['buckets']['stock']['value'] = 500;
        DB::table(InventorySnapshots::TABLE)->where('id', $row->id)->update(['totals' => json_encode($totals)]);

        $this->assertSame(1, Artisan::call('erp:inventory-history', ['--days' => 3, '--pause' => 0]));
        $this->assertStringContainsString('Kontrola niezgodna', Artisan::output());
        // --force przelicza dni od nowa, ale kontroli nie omija
        $this->assertSame(1, Artisan::call('erp:inventory-history', ['--days' => 3, '--pause' => 0, '--force' => true]));
        $this->assertSame(0, DB::table(InventorySnapshots::TABLE)->where('source', 'xl_history')->count());
        $this->assertSame(0, Artisan::call('erp:inventory-history', ['--days' => 1, '--pause' => 0, '--ignore-check' => true]));
        $this->assertSame(21, DB::table(InventorySnapshots::TABLE)->where('source', 'xl_history')->count());
        DB::table(InventorySnapshots::TABLE)->where('source', 'xl_history')->delete();

        DB::table(InventorySnapshots::TABLE)->where('id', $row->id)->update(['totals' => $row->totals]);
        $this->assertSame(0, Artisan::call('erp:inventory-history', ['--days' => 3, '--pause' => 0, '--dry-run' => true]));
        $this->assertStringContainsString('Próba bez zapisu', Artisan::output());
        $this->assertSame(0, DB::table(InventorySnapshots::TABLE)->where('source', 'xl_history')->count());
    }

    public function test_live_day_from_older_rules_is_replaced_only_with_force_and_seam_uses_current_rules(): void
    {
        // 1.10.2026: zapis nocny jeszcze starymi regułami (bufor jako sprzedaż z dzisiaj) — wersja 1
        $this->liveItemAndSnapshot();
        DB::table(InventorySnapshots::TABLE)->insert(['taken_on' => '2026-10-01', 'location' => '', 'scope' => 'all', 'source' => 'live',
            'totals' => json_encode(['version' => 1, 'buckets' => ['stock' => ['items' => 1, 'value' => 999]]])]);

        $r = app(InventoryHistoryRebuild::class)->run(3, pauseSeconds: 0.0);
        $this->assertSame('2026-10-02', $r['first_live']);
        $this->assertSame(['2026-09-29', '2026-09-30'], $r['days']);
        $this->assertSame(999, (int) $this->bucket('2026-10-01', '', 'all', 'stock')['value']);

        // --force: dzień starszymi regułami odtworzony od nowa (cały dzień, wszystkie oddziały); nocny 2.10 nietknięty
        $r = app(InventoryHistoryRebuild::class)->run(3, force: true, pauseSeconds: 0.0);
        $this->assertSame('saved', $r['status']);
        $this->assertSame(['2026-09-29', '2026-09-30', '2026-10-01'], $r['days']);
        $this->assertSame(['xl_history'], DB::table(InventorySnapshots::TABLE)->where('taken_on', '2026-10-01')->distinct()->pluck('source')->all());
        $this->assertSame(21, DB::table(InventorySnapshots::TABLE)->where('taken_on', '2026-10-01')->count());
        $this->assertEquals(['items' => 1, 'value' => 100, 'value_unknown' => 0], $this->bucket('2026-10-01', '', 'all', 'stock'));
        $this->assertSame(21, DB::table(InventorySnapshots::TABLE)->where('taken_on', '2026-10-02')->where('source', 'live')->count());

        // sam zapis nocny starszymi regułami — nie ma z czym sprawdzić odtworzenia
        DB::table(InventorySnapshots::TABLE)->where('taken_on', '2026-10-02')->update(['totals' => json_encode(['version' => 2, 'buckets' => []])]);
        $this->assertSame('no_live', app(InventoryHistoryRebuild::class)->run(3, force: true)['status']);
    }

    public function test_buffered_sale_date_uses_last_change_with_the_right_clarion_shift(): void
    {
        // dzień ostatniej zmiany dokumentu w buforze: TrN_LastMod / 86400 (dni od 1.01.1990) + przesunięcie = data Clarion
        $sql = (new \ReflectionClassConstant(ErpXlClient::class, 'SALE_DATE_SQL'))->getValue();
        $shift = ClarionDate::fromDate(CarbonImmutable::create(1990, 1, 1));
        $this->assertStringContainsString('TrN_LastMod / 86400 + '.$shift.' ', $sql);
        $this->assertStringContainsString('n.TrN_Stan < 3', $sql);
        $this->assertSame('2026-09-30', ClarionDate::toDate(InventoryHistoryRebuild::dayNumber('2026-09-30') + $shift)?->toDateString());
    }

    public function test_deadlock_with_xl_work_pauses_and_retries_the_chunk(): void
    {
        $this->liveItemAndSnapshot();
        $this->xl->historyDeadlocks = 2;
        $sleeps = [];
        $r = app(InventoryHistoryRebuild::class)->run(3, pauseSeconds: 5.0, sleep: function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });

        $this->assertSame('saved', $r['status']);
        $this->assertSame([5.0, 5.0], $sleeps);
        $this->assertEquals(['items' => 1, 'value' => 100, 'value_unknown' => 0], $this->bucket('2026-10-01', '', 'all', 'stock'));

        // trzeci konflikt z rzędu — przerwanie bez zapisu
        $this->xl->historyDeadlocks = 3;
        $this->expectException(QueryException::class);
        app(InventoryHistoryRebuild::class)->run(3, force: true, pauseSeconds: 0.0, sleep: static function (float $s): void {});
    }

    public function test_without_live_snapshot_nothing_is_rebuilt(): void
    {
        $this->assertSame('no_live', app(InventoryHistoryRebuild::class)->run(3)['status']);
        $this->assertSame(0, $this->xl->historyCalls);
    }

    /**
     * Towar 1 dziś: 01H partia 10 — 5 szt (50 zł, z 2024 r.), 15H partia 11 — 3 szt (30 zł, PZ 30.09). Ruchy: PZ partii 11
     * do 01H 30.09, 1.10 MM 01H → 15H (3 szt) i FS 2 szt z partii 10. Sprzedaż: czerwiec 2025 i 1.10.2026. Zapis nocny
     * z tego samego stanu.
     */
    private function liveItemAndSnapshot(): void
    {
        $warehouses = [
            ['code' => '01H', 'name' => 'Magazyn HANDEL - Rzeszów', 'quantity' => 5, 'value' => 50, 'oldest_lot' => '2024-01-01'],
            ['code' => '15H', 'name' => 'Magazyn HANDEL Kraków', 'quantity' => 3, 'value' => 30, 'oldest_lot' => '2026-09-30'],
        ];
        $item = ErpItem::query()->create([
            'xl_gid' => 1, 'code' => 'AX', 'name' => 'BLUZA', 'unit' => 'szt', 'archived' => false,
            'stock_trade' => 8, 'stock_total' => 8, 'stock_value' => 80, 'oldest_lot_at' => '2024-01-01',
            'stock_by_warehouse' => $warehouses, ...WarehouseSplit::compute($warehouses, ErpWarehouse::serviceCodes(), '2024-01-01'),
            'last_sale_at' => '2026-10-01', 'synced_at' => now(), 'stock_synced_at' => now(),
        ]);
        WarehouseLocations::replace((int) $item->id, $warehouses);
        WarehouseLocations::replaceSales((int) $item->id, [['warehouse_code' => '01H', 'last_sale_at' => '2026-10-01']]);
        $this->assertSame('saved', app(InventorySnapshots::class)->take()['status']);

        $this->xl->historyLots = [
            ['gid' => 1, 'dst' => 10, 'warehouse_code' => '01H', 'received_at' => $this->ts('2024-01-01'), 'quantity' => 5.0, 'value' => 50.0],
            ['gid' => 1, 'dst' => 11, 'warehouse_code' => '15H', 'received_at' => $this->ts('2026-09-30'), 'quantity' => 3.0, 'value' => 30.0],
        ];
        $move = fn (int $dst, string $wh, int $type, string $day, float $q, float $cost, string $rec): array => [
            'gid' => 1, 'dst' => $dst, 'warehouse_code' => $wh, 'received_at' => $this->ts($rec), 'type' => $type, 'day' => $this->day($day), 'quantity' => $q, 'cost' => $cost,
        ];
        $this->xl->historyMoves = [
            $move(11, '01H', 1489, '2026-09-30', 3, 30, '2026-09-30'),
            $move(11, '01H', 1603, '2026-10-01', 3, 30, '2026-09-30'),
            $move(11, '15H', 1604, '2026-10-01', 3, 30, '2026-09-30'),
            $move(10, '01H', 2033, '2026-10-01', 2, 20, '2024-01-01'),
        ];
        $clarion = fn (string $d): int => ClarionDate::fromDate(CarbonImmutable::parse($d));
        $this->xl->historySales = [
            ['gid' => 1, 'warehouse_code' => '01H', 'date' => $clarion('2025-06-01')],
            ['gid' => 1, 'warehouse_code' => '01H', 'date' => $clarion('2026-10-01')],
        ];
    }

    /** @return array<string, mixed> */
    private function bucket(string $day, string $location, string $scope, string $bucket): array
    {
        $json = DB::table(InventorySnapshots::TABLE)->where('taken_on', $day)->where('location', $location)->where('scope', $scope)->value('totals');

        return json_decode((string) $json, true)['buckets'][$bucket];
    }

    private function day(string $date): int
    {
        return InventoryHistoryRebuild::dayNumber($date);
    }

    private function ts(string $date): int
    {
        return $this->day($date) * 86400 + 36000;
    }
}
