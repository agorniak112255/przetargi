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
        $this->assertSame(['Osoba TAIZ', 'Osoba NOMA'], [$pair->rw_operator_name, $pair->pw_approver_name]);
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

    public function test_lot_age_from_lots_taken_by_rw(): void
    {
        $this->xl->moveRows = [
            // RW-15H/69/26/09 bluzy: partia z PZ z 30.08.2021, RW i PW 29.09.2026
            FakeErpXlGateway::move('rw', 69, $this->d('2026-09-29'), 9, 3, 159.0, 'NOMA', '15H'),
            FakeErpXlGateway::move('pw', 61, $this->d('2026-09-29'), 9, 3, 159.0, 'NOMA', '15H'),
            // RW bez danych o partiach
            FakeErpXlGateway::move('rw', 70, $this->d('2026-09-10'), 10, 1, 10.0),
            FakeErpXlGateway::move('pw', 71, $this->d('2026-09-10'), 10, 1, 10.0),
        ];
        $this->xl->lotRows = [
            // 1 szt. z partii z 30.08.2021 (60 pełnych mies. do 29.09.2026), 2 szt. z partii z PW z 1.05.2026 (4 mies.)
            FakeErpXlGateway::lot(69, 9, $this->ts('2021-08-30 10:27'), 1, 'PZ-15H/350/21/08'),
            FakeErpXlGateway::lot(69, 9, $this->ts('2026-05-01 12:00'), 2, 'PW-15H/20/26/05', 1617),
            // partia innego towaru z tego samego RW nie miesza się
            FakeErpXlGateway::lot(69, 99, $this->ts('2010-01-01 00:00'), 5),
        ];

        app(ErpRwPwSync::class)->run();

        $pair = ErpRwPwPair::query()->where('rw_document_id', 69)->sole();
        $this->assertSame('2021-08-30', $pair->rw_lot_at?->toDateString());
        $this->assertSame(60, $pair->rw_lot_age_months);
        $this->assertSame(2, $pair->rw_lots);
        $this->assertSame('PZ-15H/350/21/08', $pair->rw_lot_source);
        // najstarsza partia weszła przez PZ, nie przez PW
        $this->assertFalse($pair->rw_lot_from_pw);
        // średnia ważona ilością: (60,97 × 1 + 4,93 × 2) / 3 ≈ 23,6
        $this->assertEqualsWithDelta(23.6, (float) $pair->rw_lot_avg_age_months, 0.2);

        // brak danych o partiach PW to nie dowód zmiany rozmiaru
        $this->assertNull($pair->rw_features);
        $this->assertTrue($pair->same_feature);

        $bare = ErpRwPwPair::query()->where('rw_document_id', 70)->sole();
        $this->assertNull($bare->rw_lot_age_months);
        $this->assertSame(0, $bare->rw_lots);
    }

    public function test_size_change_is_recorded_from_lot_features(): void
    {
        $this->xl->moveRows = [
            // rozmiar 43 → 44 (zmiana cechy) oraz L×2 + XL×1 → te same cechy
            FakeErpXlGateway::move('rw', 1, $this->d('2026-09-28'), 5, 2, 228.0),
            FakeErpXlGateway::move('pw', 2, $this->d('2026-09-28'), 5, 2, 228.0),
            FakeErpXlGateway::move('rw', 3, $this->d('2026-09-20'), 6, 3, 90.0),
            FakeErpXlGateway::move('pw', 4, $this->d('2026-09-20'), 6, 3, 90.0),
        ];
        $at = $this->ts('2026-01-10 08:00');
        $this->xl->lotRows = [
            FakeErpXlGateway::lot(1, 5, $at, 2, feature: '43'),
            FakeErpXlGateway::lot(2, 5, $at, 2, 'PW-01H/2/26/09', 1617, '44', 'pw'),
            FakeErpXlGateway::lot(3, 6, $at, 2, feature: 'L'),
            FakeErpXlGateway::lot(3, 6, $at, 1, feature: 'XL'),
            FakeErpXlGateway::lot(4, 6, $at, 1, 'PW-01H/4/26/09', 1617, 'XL', 'pw'),
            FakeErpXlGateway::lot(4, 6, $at, 2, 'PW-01H/4/26/09', 1617, 'L', 'pw'),
        ];

        app(ErpRwPwSync::class)->run();

        $size = ErpRwPwPair::query()->where('rw_document_id', 1)->sole();
        $this->assertSame(['43', '44', false], [$size->rw_features, $size->pw_features, $size->same_feature]);
        $mixed = ErpRwPwPair::query()->where('rw_document_id', 3)->sole();
        $this->assertSame(['L×2, XL×1', 'L×2, XL×1', true], [$mixed->rw_features, $mixed->pw_features, $mixed->same_feature]);
        // cecha PW nie wpływa na wiek partii zdjętej przez RW
        $this->assertSame(8, $size->rw_lot_age_months);

        // RW z nazwaną cechą, a XL nie podał partii PW — bez rozstrzygnięcia, więc nie „zmiana rozmiaru”
        $this->xl->lotRows = [FakeErpXlGateway::lot(1, 5, $at, 2, feature: '43')];
        app(ErpRwPwSync::class)->run();
        $this->assertTrue(ErpRwPwPair::query()->where('rw_document_id', 1)->sole()->same_feature);
    }

    public function test_notes_are_stored_and_foreign_number_only_when_different(): void
    {
        $rw = FakeErpXlGateway::move('rw', 67, $this->d('2026-09-28'), 5, 1, 114.0, 'TUBEZ', '15H');
        $rw['note'] = 'ZAMIANA ROZMIARÓW';
        // XL wpisuje w dokument obcy RW jego własny numer — to nie informacja
        $rw['foreign_number'] = $rw['number'];
        $pw = FakeErpXlGateway::move('pw', 59, $this->d('2026-09-28'), 5, 1, 114.0, 'TUBEZ', '15H');
        $pw['note'] = 'RW-15H/67/26/09';
        $pw['foreign_number'] = 'ZW/12/2026';
        $this->xl->moveRows = [$rw, $pw];

        app(ErpRwPwSync::class)->run();

        $pair = ErpRwPwPair::query()->sole();
        $this->assertSame(['ZAMIANA ROZMIARÓW', 'RW-15H/67/26/09'], [$pair->rw_note, $pair->pw_note]);
        $this->assertSame([null, 'ZW/12/2026'], [$pair->rw_foreign_number, $pair->pw_foreign_number]);
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

    /** Znacznik XL: sekundy od 1.01.1990. */
    private function ts(string $at): int
    {
        return (int) CarbonImmutable::create(1990, 1, 1)->diffInSeconds(CarbonImmutable::parse($at));
    }
}
