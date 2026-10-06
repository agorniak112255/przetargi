<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpCustomer;
use App\Models\InspectionDue;
use App\Models\InspectionPosition;
use App\Services\Inspections\InspectionDueBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Terminy przeglądów z kopii faktur: wizyty (31 dni), korekty, odnowienie po 75% interwału, kilka obiektów, towar
 * odnowiony usługą, klienci archiwalni i nieznani, inna karta z tym samym NIP-em, pozycja nieaktywna.
 */
final class InspectionDueBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE = 4737;

    private const GOODS = 500;

    private int $document = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Europe/Warsaw'));
    }

    public function test_events_within_31_days_form_one_visit_and_last_fields_come_from_latest_visit(): void
    {
        $position = $this->position(self::SERVICE, 12);
        // wcześniejsza wizyta odnowiona przez następną (po ponad 75% interwału)
        $this->line(10, '2024-10-01', self::SERVICE, 8, 72);
        // jedna wizyta z dwóch faktur: początek = data sprzedaży pierwszej, druga 28 dni później
        $this->line(10, '2025-10-10', self::SERVICE, 6, 54, ['sold_on' => '2025-10-08', 'location' => '01', 'operator_ident' => 'NOMA']);
        $this->line(10, '2025-11-05', self::SERVICE, 4, 36.5, ['location' => '13', 'operator_ident' => 'KOWA', 'recipient_xl_gid' => 77]);

        $this->assertSame(1, app(InspectionDueBuilder::class)->rebuild());

        $due = $this->due($position, 10);
        $this->assertSame('2026-10-08', $due->due_on->format('Y-m-d'));
        $this->assertSame(1, $due->open_count);
        $this->assertSame('10.000', $due->open_quantity);
        $this->assertSame('2025-10-08', $due->last_on->format('Y-m-d'));
        $this->assertSame('10.000', $due->last_quantity);
        $this->assertSame('90.50', $due->last_net);
        $this->assertSame('2024-10-01', $due->first_on->format('Y-m-d'));
        $this->assertSame([
            ['number' => 'FS-01G/2/25/10', 'issued_on' => '2025-10-10', 'quantity' => 6],
            ['number' => 'FS-01G/3/25/11', 'issued_on' => '2025-11-05', 'quantity' => 4],
        ], $due->last_documents);
        // z najpóźniejszej faktury ostatniej wizyty
        $this->assertSame('13', $due->location);
        $this->assertSame('KOWA', $due->operator_ident);
        $this->assertSame(77, $due->recipient_xl_gid);
        $this->assertFalse($due->same_nip_newer);
    }

    public function test_event_32_days_after_visit_start_opens_a_new_visit(): void
    {
        $position = $this->position(self::SERVICE, 12);
        $this->line(11, '2025-03-01', self::SERVICE, 2, 20);
        $this->line(11, '2025-04-02', self::SERVICE, 3, 30);

        app(InspectionDueBuilder::class)->rebuild();

        $due = $this->due($position, 11);
        $this->assertSame(2, $due->open_count);
        $this->assertSame('5.000', $due->open_quantity);
        $this->assertSame('2026-03-01', $due->due_on->format('Y-m-d'));
        $this->assertSame('2025-04-02', $due->last_on->format('Y-m-d'));
        $this->assertSame('3.000', $due->last_quantity);
    }

    public function test_monthly_inspections_are_separate_visits_not_one_glued_visit(): void
    {
        // przegląd co miesiąc: okno wizyty 31 dni sklejało 1.11 i 1.12 w jedną wizytę z terminem 1.12 (miesiąc za wcześnie)
        $position = $this->position(self::SERVICE, 1);
        foreach (['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'] as $date) {
            $this->line(12, $date, self::SERVICE, 5, 50);
        }

        app(InspectionDueBuilder::class)->rebuild();

        $due = $this->due($position, 12);
        $this->assertSame('2026-11-01', $due->due_on->format('Y-m-d'));
        $this->assertSame(1, $due->open_count);
        $this->assertSame('5.000', $due->last_quantity);
        $this->assertSame('2026-10-01', $due->last_on->format('Y-m-d'));
    }

    public function test_export_invoice_correction_counts_like_invoice_correction(): void
    {
        // korekta FSE (2045) zmniejsza fakturę FSE (2037) jak korekta FS
        $position = $this->position(self::SERVICE, 12);
        $invoice = $this->line(13, '2025-06-01', self::SERVICE, 4, 40, ['document_type' => 2037]);
        $this->line(13, '2025-06-10', self::SERVICE, -1, -10, ['document_type' => 2045, 'corrects_document_type' => 2037, 'corrects_document_id' => $invoice]);

        app(InspectionDueBuilder::class)->rebuild();

        $this->assertSame('3.000', $this->due($position, 13)->last_quantity);
    }

    public function test_corrections_are_added_to_corrected_document_and_orphans_are_ignored(): void
    {
        $position = $this->position(self::SERVICE, 12);
        $invoice = $this->line(20, '2025-06-01', self::SERVICE, 10, 100);
        $correction = $this->line(20, '2025-06-15', self::SERVICE, -4, -40, ['document_type' => 2041, 'corrects_document_type' => 2033, 'corrects_document_id' => $invoice]);
        // korekta korekty trafia do tej samej faktury
        $this->line(20, '2025-06-20', self::SERVICE, 1, 10, ['document_type' => 2041, 'corrects_document_type' => 2041, 'corrects_document_id' => $correction]);
        // korekta ceny (ilość 0)
        $this->line(20, '2025-06-21', self::SERVICE, 0, -5, ['document_type' => 2041, 'corrects_document_type' => 2033, 'corrects_document_id' => $invoice]);
        // korekta bez oryginału w kopii (np. faktura sprzed 2019) — pomijana
        $this->line(20, '2025-07-01', self::SERVICE, -5, -50, ['document_type' => 2041, 'corrects_document_type' => 2033, 'corrects_document_id' => 99999]);
        // faktura innego klienta skorygowana do zera — brak wizyty, brak wiersza
        $other = $this->line(21, '2025-06-01', self::SERVICE, 3, 30);
        $this->line(21, '2025-06-02', self::SERVICE, -3, -30, ['document_type' => 2041, 'corrects_document_type' => 2033, 'corrects_document_id' => $other]);

        app(InspectionDueBuilder::class)->rebuild();

        $due = $this->due($position, 20);
        $this->assertSame('7.000', $due->open_quantity);
        $this->assertSame('7.000', $due->last_quantity);
        $this->assertSame('65.00', $due->last_net);
        $this->assertSame([['number' => 'FS-01G/'.$invoice.'/25/06', 'issued_on' => '2025-06-01', 'quantity' => 7]], $due->last_documents);
        $this->assertNull(InspectionDue::query()->where('customer_xl_gid', 21)->first());
    }

    public function test_later_visit_renews_only_after_75_percent_of_interval(): void
    {
        $position = $this->position(self::SERVICE, 12);
        // 12 mies. × 30,44 × 0,75 = 274 dni
        $this->line(30, '2024-01-01', self::SERVICE, 4, 40);
        $this->line(30, '2024-10-01', self::SERVICE, 4, 40); // dokładnie 274 dni — odnawia
        $this->line(31, '2024-01-01', self::SERVICE, 4, 40);
        $this->line(31, '2024-09-30', self::SERVICE, 6, 60); // 273 dni — drugi obiekt, nie odnawia

        app(InspectionDueBuilder::class)->rebuild();

        $renewed = $this->due($position, 30);
        $this->assertSame(1, $renewed->open_count);
        $this->assertSame('2025-10-01', $renewed->due_on->format('Y-m-d'));
        $this->assertSame('4.000', $renewed->open_quantity);

        $twoObjects = $this->due($position, 31);
        $this->assertSame(2, $twoObjects->open_count);
        $this->assertSame('10.000', $twoObjects->open_quantity);
        $this->assertSame('2025-01-01', $twoObjects->due_on->format('Y-m-d'));
        $this->assertSame('2024-09-30', $twoObjects->last_on->format('Y-m-d'));
    }

    public function test_goods_purchase_is_renewed_by_later_sale_of_renewing_service(): void
    {
        $this->position(self::SERVICE, 12);
        $goods = $this->position(self::GOODS, 12, ['xl_type' => InspectionPosition::TYPE_GOODS, 'renewed_by_xl_gid' => self::SERVICE]);
        // przegląd po zakupie — zakup odnowiony
        $this->line(40, '2025-02-01', self::GOODS, 2, 300, ['xl_item_type' => 1]);
        $this->line(40, '2025-12-01', self::SERVICE, 2, 20);
        // towar bez usługi
        $this->line(41, '2025-02-01', self::GOODS, 2, 300, ['xl_item_type' => 1]);
        // przegląd przed zakupem — nie odnawia
        $this->line(42, '2025-04-01', self::SERVICE, 1, 10);
        $this->line(42, '2025-05-01', self::GOODS, 1, 150, ['xl_item_type' => 1]);
        // przegląd po zakupie skorygowany do zera — nie odnawia
        $this->line(43, '2025-02-01', self::GOODS, 1, 150, ['xl_item_type' => 1]);
        $service = $this->line(43, '2025-08-01', self::SERVICE, 1, 10);
        $this->line(43, '2025-08-02', self::SERVICE, -1, -10, ['document_type' => 2041, 'corrects_document_type' => 2033, 'corrects_document_id' => $service]);

        app(InspectionDueBuilder::class)->rebuild([$goods->id]);

        $rows = InspectionDue::query()->where('inspection_position_id', $goods->id)->orderBy('customer_xl_gid')->get()->keyBy('customer_xl_gid');
        $this->assertSame([41, 42, 43], $rows->keys()->all());
        $this->assertSame('2026-02-01', $rows[41]->due_on->format('Y-m-d'));
        $this->assertSame('2026-05-01', $rows[42]->due_on->format('Y-m-d'));
        $this->assertSame('2026-02-01', $rows[43]->due_on->format('Y-m-d'));
        // przebudowa jednej pozycji nie dotyka innych
        $this->assertSame(0, InspectionDue::query()->where('inspection_position_id', '<>', $goods->id)->count());
    }

    public function test_archived_and_removed_customers_are_skipped_unknown_stay_and_same_nip_newer_is_flagged(): void
    {
        $position = $this->position(self::SERVICE, 12);
        $this->customer(50, ['archived' => true]);
        $this->customer(51, ['removed_at' => now()]);
        $this->customer(60, ['nip' => 'PL 123-456-78-90']);
        $this->customer(61, ['nip' => '1234567890']);
        $this->customer(62, ['nip' => '123']);
        $this->customer(63, ['nip' => '123']);
        $this->line(50, '2025-05-01', self::SERVICE, 1, 10);
        $this->line(51, '2025-05-01', self::SERVICE, 1, 10);
        $this->line(52, '2025-05-01', self::SERVICE, 1, 10); // brak w erp_customers — zostaje
        $this->line(60, '2025-01-01', self::SERVICE, 1, 10);
        $this->line(61, '2025-06-01', self::SERVICE, 1, 10);
        $this->line(62, '2025-01-01', self::SERVICE, 1, 10);
        $this->line(63, '2025-06-01', self::SERVICE, 1, 10);

        app(InspectionDueBuilder::class)->rebuild();

        $rows = InspectionDue::query()->where('inspection_position_id', $position->id)->orderBy('customer_xl_gid')->get()->keyBy('customer_xl_gid');
        $this->assertSame([52, 60, 61, 62, 63], $rows->keys()->all());
        $this->assertTrue($rows[60]->same_nip_newer);
        $this->assertFalse($rows[61]->same_nip_newer);
        // za krótki NIP to nie NIP
        $this->assertFalse($rows[62]->same_nip_newer);
    }

    public function test_same_nip_newer_counts_visit_on_archived_card(): void
    {
        $position = $this->position(self::SERVICE, 12);
        $this->customer(70, ['nip' => '9876543210']);
        $this->customer(71, ['nip' => '9876543210', 'archived' => true]);
        $this->line(70, '2025-01-01', self::SERVICE, 1, 10);
        $this->line(71, '2025-07-01', self::SERVICE, 1, 10);

        app(InspectionDueBuilder::class)->rebuild();

        $this->assertTrue($this->due($position, 70)->same_nip_newer);
        $this->assertNull(InspectionDue::query()->where('customer_xl_gid', 71)->first());
    }

    public function test_inactive_or_deleted_position_loses_rows_and_rebuild_keeps_row_ids(): void
    {
        $position = $this->position(self::SERVICE, 12);
        $other = $this->position(4800, 6);
        $this->line(80, '2025-05-01', self::SERVICE, 1, 10);
        $this->line(81, '2025-05-01', self::SERVICE, 1, 10);
        $this->line(80, '2025-05-01', 4800, 1, 10);

        $this->assertSame(3, app(InspectionDueBuilder::class)->rebuild());
        $id = $this->due($position, 80)->id;

        // nowa wizyta klienta 80, klient 81 bez faktur (np. anulowana) — wiersz 80 ten sam, wiersz 81 znika
        $this->line(80, '2025-11-01', self::SERVICE, 2, 20);
        DB::table('inspection_sale_lines')->where('customer_xl_gid', 81)->delete();
        $this->assertSame(1, app(InspectionDueBuilder::class)->rebuild([$position->id]));
        $due = $this->due($position, 80);
        $this->assertSame($id, $due->id);
        $this->assertSame(2, $due->open_count);
        $this->assertNull(InspectionDue::query()->where('customer_xl_gid', 81)->first());

        // pozycja nieaktywna — jej wiersze znikają, inne zostają
        $position->update(['active' => false]);
        $this->assertSame(1, app(InspectionDueBuilder::class)->rebuild());
        $this->assertSame(0, InspectionDue::query()->where('inspection_position_id', $position->id)->count());
        $this->assertSame(1, InspectionDue::query()->where('inspection_position_id', $other->id)->count());

        // pozycja usunięta
        $other->delete();
        $this->assertSame(0, app(InspectionDueBuilder::class)->rebuild([$other->id]));
        $this->assertSame(0, InspectionDue::query()->count());
    }

    public function test_due_adds_months_without_overflow(): void
    {
        $position = $this->position(self::SERVICE, 1);
        $this->line(90, '2026-01-31', self::SERVICE, 1, 10);

        app(InspectionDueBuilder::class)->rebuild();

        $this->assertSame('2026-02-28', $this->due($position, 90)->due_on->format('Y-m-d'));
    }

    /** @param  array<string, mixed>  $attributes */
    private function position(int $xlGid, int $interval, array $attributes = []): InspectionPosition
    {
        return InspectionPosition::query()->create([
            'xl_gid' => $xlGid, 'xl_type' => InspectionPosition::TYPE_SERVICE, 'code' => 'K'.$xlGid, 'name' => 'Pozycja '.$xlGid,
            'interval_months' => $interval, 'history_loaded_at' => now(), ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function customer(int $xlGid, array $attributes = []): void
    {
        ErpCustomer::query()->create(['xl_gid' => $xlGid, 'acronym' => 'K'.$xlGid, 'name' => 'Firma '.$xlGid, ...$attributes]);
    }

    /**
     * Pozycja faktury w kopii (jak z InspectionSaleSync); zwraca numer dokumentu XL.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function line(int $customer, string $issued, int $item, float $quantity, float $net, array $overrides = []): int
    {
        $id = ++$this->document;
        $date = CarbonImmutable::parse($issued);
        DB::table('inspection_sale_lines')->insert([
            'document_type' => 2033, 'document_id' => $id, 'line' => 1,
            'document_number' => sprintf('FS-01G/%d/%02d/%02d', $id, $date->year % 100, $date->month),
            'issued_on' => $issued, 'sold_on' => null, 'customer_xl_gid' => $customer, 'recipient_xl_gid' => null,
            'xl_item_gid' => $item, 'xl_item_type' => 4, 'quantity' => $quantity, 'net_value' => $net, 'warehouse_code' => '01G',
            'location' => '01', 'operator_ident' => 'NOMA', 'corrects_document_type' => null, 'corrects_document_id' => null,
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(), ...$overrides,
        ]);

        return $id;
    }

    private function due(InspectionPosition $position, int $customer): InspectionDue
    {
        return InspectionDue::query()->where('inspection_position_id', $position->id)->where('customer_xl_gid', $customer)->firstOrFail();
    }
}
