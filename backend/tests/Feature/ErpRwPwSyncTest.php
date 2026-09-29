<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpItem;
use App\Models\ErpRwPwPair;
use App\Services\Erp\ErpRwPwSync;
use App\Services\Erp\ErpXlGateway;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

/** Parowanie RW → PW z ERP XL: ten sam towar, ta sama ilość, PW 0–30 dni po RW, dokument w najwyżej jednej parze. */
final class ErpRwPwSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeErpXlGateway $xl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00'));
        $this->xl = new FakeErpXlGateway;
        $this->app->instance(ErpXlGateway::class, $this->xl);
    }

    public function test_pairs_same_item_and_quantity_within_thirty_days_with_document_fields(): void
    {
        $item = ErpItem::query()->create(['xl_gid' => 7, 'code' => 'BSNSL46', 'name' => 'SANDAŁY ATLAS SL 46', 'unit' => 'par', 'archived' => false, 'stock_trade' => 0, 'stock_total' => 20]);
        $this->xl->moveRows = [
            FakeErpXlGateway::move('rw', 51, $this->d('2026-09-21'), 7, 5, 1100.0, 'TAIZ'),
            FakeErpXlGateway::move('pw', 52, $this->d('2026-09-21'), 7, 5, 1100.0, 'TAIZ', '01H', 'NOMA'),
            // korekta ilości (1 → 40) to nie para
            FakeErpXlGateway::move('rw', 60, $this->d('2026-08-01'), 7, 1, 11600.6),
            FakeErpXlGateway::move('pw', 61, $this->d('2026-08-01'), 7, 40, 11600.6),
            // PW przed RW to nie para; PW 31 dni po RW też nie
            FakeErpXlGateway::move('pw', 70, $this->d('2026-06-01'), 7, 3, 30.0),
            FakeErpXlGateway::move('rw', 71, $this->d('2026-06-02'), 7, 3, 30.0),
            FakeErpXlGateway::move('rw', 80, $this->d('2026-04-01'), 7, 2, 20.0),
            FakeErpXlGateway::move('pw', 81, $this->d('2026-05-02'), 7, 2, 20.0),
            // inny towar z tą samą ilością i dniem to nie para
            FakeErpXlGateway::move('pw', 90, $this->d('2026-09-21'), 8, 5, 1100.0),
        ];

        $this->assertSame(['moves' => 9, 'pairs' => 1, 'items' => 1], app(ErpRwPwSync::class)->run());
        $this->assertSame($this->d('2025-09-30'), $this->xl->movesFrom);

        $pair = ErpRwPwPair::query()->sole();
        $this->assertSame($item->id, $pair->erp_item_id);
        $this->assertSame([51, 52], [$pair->rw_document_id, $pair->pw_document_id]);
        $this->assertSame('RW-01H/51/26/09', $pair->rw_number);
        $this->assertSame('2026-09-21', $pair->rw_date->toDateString());
        $this->assertSame(['TAIZ', 'TAIZ', 'TAIZ', 'NOMA'], [$pair->rw_operator, $pair->rw_approver, $pair->pw_operator, $pair->pw_approver]);
        $this->assertSame(0, $pair->gap_days);
        $this->assertTrue($pair->same_value);
        $this->assertTrue($pair->same_warehouse);
        $this->assertSame('1100.00', $pair->pw_value);
    }

    public function test_each_document_pairs_once_nearest_then_same_warehouse_then_closest_value(): void
    {
        $this->xl->moveRows = [
            // dwa RW i dwa PW tej samej ilości: RW od najstarszego bierze najbliższe wolne PW
            FakeErpXlGateway::move('rw', 1, $this->d('2026-09-01'), 5, 10, 100.0),
            FakeErpXlGateway::move('rw', 2, $this->d('2026-09-05'), 5, 10, 100.0),
            FakeErpXlGateway::move('pw', 3, $this->d('2026-09-06'), 5, 10, 100.0),
            FakeErpXlGateway::move('pw', 4, $this->d('2026-09-20'), 5, 10, 100.0),
            // w tym samym dniu dwa PW: wygrywa ten sam magazyn, choć ma wyższy numer
            FakeErpXlGateway::move('rw', 10, $this->d('2026-07-10'), 6, 4, 40.0, 'CZAL', '15H'),
            FakeErpXlGateway::move('pw', 11, $this->d('2026-07-10'), 6, 4, 40.0, 'CZAL', '01H'),
            FakeErpXlGateway::move('pw', 12, $this->d('2026-07-10'), 6, 4, 55.0, 'CZAL', '15H'),
        ];

        app(ErpRwPwSync::class)->run();

        $pairs = ErpRwPwPair::query()->orderBy('rw_document_id')->get()
            ->map(fn (ErpRwPwPair $p): array => [$p->rw_document_id, $p->pw_document_id, $p->gap_days, $p->same_value, $p->same_warehouse])->all();
        $this->assertSame([
            [1, 3, 5, true, true],
            [2, 4, 15, true, true],
            [10, 12, 0, false, true],
        ], $pairs);
    }

    public function test_rerun_replaces_pairs_and_command_skips_when_xl_is_off(): void
    {
        $this->xl->moveRows = [
            FakeErpXlGateway::move('rw', 1, $this->d('2026-09-01'), 5, 1, 10.0),
            FakeErpXlGateway::move('pw', 2, $this->d('2026-09-01'), 5, 1, 10.0),
        ];
        $this->assertSame(0, Artisan::call('erp:rw-pw'));
        $this->assertSame(1, ErpRwPwPair::query()->count());

        // w XL PW anulowano — przy kolejnym odczycie para znika
        $this->xl->moveRows = [FakeErpXlGateway::move('rw', 1, $this->d('2026-09-01'), 5, 1, 10.0)];
        $this->assertSame(0, Artisan::call('erp:rw-pw'));
        $this->assertSame(0, ErpRwPwPair::query()->count());

        $this->xl->isConfigured = false;
        $this->xl->movesFrom = null;
        $this->assertSame(0, Artisan::call('erp:rw-pw'));
        $this->assertNull($this->xl->movesFrom);
    }

    private function d(string $date): int
    {
        return ClarionDate::fromDate(CarbonImmutable::parse($date));
    }
}
