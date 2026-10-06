<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpCustomer;
use App\Models\ErpItem;
use App\Models\ErpService;
use App\Models\InspectionDue;
use App\Models\InspectionPosition;
use App\Models\InspectionSaleLine;
use App\Services\Erp\ErpXlGateway;
use App\Services\Inspections\InspectionCustomerDetails;
use App\Services\Inspections\InspectionDueBuilder;
use App\Services\Inspections\InspectionSaleSync;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

/**
 * erp:inspections: katalog usług (removed_at, odświeżenie nazw pozycji), okno 60 dni (kasowanie tylko w oknie i tylko
 * usług oraz towarów pozycji z doczytaną historią), doczytanie historii nowego towaru od 2019, zapis pól pozycji faktury.
 */
final class InspectionSaleSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeErpXlGateway $xl;

    protected function setUp(): void
    {
        parent::setUp();
        // 6 października 2026 w Polsce — okno 60 dni od 7 sierpnia 2026
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Europe/Warsaw'));
        $this->xl = new FakeErpXlGateway;
        $this->app->instance(ErpXlGateway::class, $this->xl);
    }

    public function test_service_catalog_is_upserted_missing_service_marked_removed_and_position_names_refreshed(): void
    {
        $gone = ErpService::query()->create(['xl_gid' => 900, 'xl_type' => 4, 'code' => 'UPRSTARA', 'name' => 'Stara usługa', 'synced_at' => now()->subDay()]);
        ErpService::query()->create(['xl_gid' => 4737, 'xl_type' => 4, 'code' => 'UPRGP6', 'name' => 'Stara nazwa', 'synced_at' => now()->subDay()]);
        $this->erpItem(100, 'GP6X', 'GAŚNICA PROSZKOWA GP-6X ABC');
        $service = $this->position(4737, InspectionPosition::TYPE_SERVICE, ['code' => 'UPRGP6', 'name' => 'Stara nazwa', 'unit' => 'szt']);
        $goods = $this->position(100, InspectionPosition::TYPE_GOODS, ['code' => 'GP6', 'name' => 'GAŚNICA GP-6X', 'history_loaded_at' => now()->subDay()]);
        $updatedAt = $service->updated_at?->toIso8601String();

        $this->xl->serviceRows = [
            FakeErpXlGateway::service(4737, 'UPRGP6', 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6'),
            FakeErpXlGateway::service(4740, 'UPRGS5X', 'PRZEGLĄD GAŚNICY ŚNIEGOWEJ GS-5X', true, null),
        ];

        $stats = app(InspectionSaleSync::class)->run();

        $this->assertSame(2, $stats['services']);
        $this->assertNotNull($gone->fresh()->removed_at);
        $this->assertNull(ErpService::query()->where('xl_gid', 4737)->value('removed_at'));
        $this->assertSame('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', ErpService::query()->where('xl_gid', 4737)->value('name'));
        $snow = ErpService::query()->where('xl_gid', 4740)->firstOrFail();
        $this->assertTrue($snow->archived);
        $this->assertNull($snow->unit);

        $service->refresh();
        $this->assertSame('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', $service->name);
        // odświeżenie z katalogu to nie zmiana przez człowieka
        $this->assertSame($updatedAt, $service->updated_at?->toIso8601String());
        $goods->refresh();
        $this->assertSame('GP6X', $goods->code);
        $this->assertSame('GAŚNICA PROSZKOWA GP-6X ABC', $goods->name);
    }

    public function test_empty_catalog_from_xl_does_not_mark_services_removed(): void
    {
        $kept = ErpService::query()->create(['xl_gid' => 4737, 'xl_type' => 4, 'code' => 'UPRGP6', 'name' => 'Przegląd', 'synced_at' => now()->subDay()]);

        app(InspectionSaleSync::class)->run();

        $this->assertNull($kept->fresh()->removed_at);
    }

    public function test_window_replaces_rows_only_inside_window_and_only_services_and_loaded_goods(): void
    {
        $this->position(100, InspectionPosition::TYPE_GOODS, ['history_loaded_at' => now()->subDays(3)]);
        // w oknie, XL już ich nie zwraca (anulowane) — znikają
        $this->storedLine(1, '2026-09-01', 10, 4737, 4);
        $this->storedLine(2, '2026-09-02', 10, 100, 1);
        // przed oknem — zostają
        $this->storedLine(3, '2026-08-06', 10, 4737, 4);
        $this->storedLine(4, '2026-01-02', 10, 100, 1);
        // towar spoza pozycji w oknie — poza zakresem odczytu, zostaje
        $this->storedLine(5, '2026-09-03', 10, 300, 1);

        $this->xl->inspectionLineRows = [
            FakeErpXlGateway::inspectionLine(10, $this->d('2026-09-10'), 20, 4737, 6, 54.6, 4, [
                'sold' => $this->d('2026-09-08'), 'recipient_gid' => 21, 'warehouse_code' => '14U', 'operator' => 'NOMA',
            ]),
            FakeErpXlGateway::inspectionLine(11, $this->d('2026-09-11'), 20, 100, 2, 300, 1, ['recipient_gid' => 20, 'warehouse_code' => null, 'operator' => null]),
            FakeErpXlGateway::inspectionLine(12, $this->d('2026-09-20'), 20, 4737, -2, -18.2, 4, [
                'doc_type' => 2041, 'corrects_type' => 2033, 'corrects_id' => 10,
            ]),
            // klient jednorazowy (numer 0) — pomijany
            FakeErpXlGateway::inspectionLine(13, $this->d('2026-09-21'), 0, 4737, 1, 10, 4),
        ];

        $stats = app(InspectionSaleSync::class)->run();

        $this->assertSame([['items' => [100], 'all_services' => true, 'from' => $this->d('2026-08-07')]], $this->xl->inspectionLineCalls);
        $this->assertSame(['services' => 0, 'lines' => 3, 'deleted' => 2, 'history_positions' => 0], $stats);
        $this->assertSame([3, 4, 5, 10, 11, 12], InspectionSaleLine::query()->orderBy('document_id')->pluck('document_id')->all());

        $fs = InspectionSaleLine::query()->where('document_id', 10)->firstOrFail();
        $this->assertSame('2026-09-10', $fs->issued_on->format('Y-m-d'));
        $this->assertSame('2026-09-08', $fs->sold_on?->format('Y-m-d'));
        $this->assertSame(21, $fs->recipient_xl_gid);
        $this->assertSame('14U', $fs->warehouse_code);
        // 14U Kraków – usługi należy do Krakowa (15)
        $this->assertSame('15', $fs->location);
        $this->assertSame('NOMA', $fs->operator_ident);
        $this->assertSame(4, $fs->xl_item_type);
        $this->assertSame('6.000', $fs->quantity);
        $this->assertSame('54.60', $fs->net_value);
        $this->assertSame('FS-01G/10/26/09', $fs->document_number);

        $goods = InspectionSaleLine::query()->where('document_id', 11)->firstOrFail();
        $this->assertNull($goods->sold_on);
        // odbiorca równy nabywcy — brak odbiorcy
        $this->assertNull($goods->recipient_xl_gid);
        $this->assertNull($goods->warehouse_code);
        $this->assertNull($goods->location);
        $this->assertNull($goods->operator_ident);

        $correction = InspectionSaleLine::query()->where('document_id', 12)->firstOrFail();
        $this->assertSame(2041, $correction->document_type);
        $this->assertSame('-2.000', $correction->quantity);
        $this->assertSame(2033, $correction->corrects_document_type);
        $this->assertSame(10, $correction->corrects_document_id);

        // drugi przebieg z tymi samymi danymi — bez duplikatów, nic do skasowania
        $again = app(InspectionSaleSync::class)->run();
        $this->assertSame(0, $again['deleted']);
        $this->assertSame(6, InspectionSaleLine::query()->count());
    }

    public function test_since_option_moves_window_start(): void
    {
        $this->storedLine(1, '2020-05-05', 10, 4737, 4);
        $this->storedLine(2, '2018-12-31', 10, 4737, 4);

        $stats = app(InspectionSaleSync::class)->run(CarbonImmutable::parse('2019-01-01'));

        $this->assertSame($this->d('2019-01-01'), $this->xl->inspectionLineCalls[0]['from']);
        $this->assertSame(1, $stats['deleted']);
        $this->assertSame([2], InspectionSaleLine::query()->pluck('document_id')->all());
    }

    public function test_new_goods_position_gets_history_from_2019_once(): void
    {
        $position = $this->position(200, InspectionPosition::TYPE_GOODS);
        $this->position(201, InspectionPosition::TYPE_SERVICE);
        $this->xl->inspectionLineRows = [
            FakeErpXlGateway::inspectionLine(20, $this->d('2019-03-01'), 30, 200, 4, 400, 1),
            FakeErpXlGateway::inspectionLine(21, $this->d('2018-12-31'), 30, 200, 1, 100, 1),
            FakeErpXlGateway::inspectionLine(22, $this->d('2026-09-30'), 30, 200, 1, 100, 1),
        ];

        $stats = app(InspectionSaleSync::class)->run();

        $this->assertSame([
            // okno: usługi, bez towarów (żaden nie ma jeszcze historii)
            ['items' => [], 'all_services' => true, 'from' => $this->d('2026-08-07')],
            // historia nowego towaru — bez usług
            ['items' => [200], 'all_services' => false, 'from' => $this->d('2019-01-01')],
        ], $this->xl->inspectionLineCalls);
        $this->assertSame(1, $stats['history_positions']);
        $this->assertSame(2, $stats['lines']);
        $this->assertSame([20, 22], InspectionSaleLine::query()->orderBy('document_id')->pluck('document_id')->all());
        $this->assertNotNull($position->fresh()->history_loaded_at);

        // następna noc: towar już w oknie, bez ponownego czytania historii
        $this->xl->inspectionLineCalls = [];
        $again = app(InspectionSaleSync::class)->run();
        $this->assertSame(0, $again['history_positions']);
        $this->assertSame([['items' => [200], 'all_services' => true, 'from' => $this->d('2026-08-07')]], $this->xl->inspectionLineCalls);
    }

    public function test_wz_with_invoice_number_and_wz_correction_are_stored_and_count_in_due(): void
    {
        // faktura do WZ nie ma w XL pozycji — gaśnice są na WZ, faktura w spinaczu WZ; korekta WZK wskazuje WZ
        $position = $this->position(200, InspectionPosition::TYPE_GOODS, ['interval_months' => 12, 'history_loaded_at' => now()]);
        $this->xl->inspectionLineRows = [
            FakeErpXlGateway::inspectionLine(50, $this->d('2026-09-01'), 30, 200, 3, 400.5, 1, ['doc_type' => 2001, 'invoice_number' => 'FS-01H/137/26/09']),
            FakeErpXlGateway::inspectionLine(51, $this->d('2026-09-10'), 30, 200, -1, -133.5, 1, ['doc_type' => 2009, 'corrects_type' => 2001, 'corrects_id' => 50]),
        ];

        app(InspectionSaleSync::class)->run();

        $wz = InspectionSaleLine::query()->where('document_id', 50)->firstOrFail();
        $this->assertSame(2001, $wz->document_type);
        $this->assertSame('WZ-01G/50/26/09', $wz->document_number);
        $this->assertSame('FS-01H/137/26/09', $wz->invoice_number);
        $this->assertNull(InspectionSaleLine::query()->where('document_id', 51)->firstOrFail()->invoice_number);

        app(InspectionDueBuilder::class)->rebuild();
        $due = InspectionDue::query()->where('inspection_position_id', $position->id)->firstOrFail();
        $this->assertSame('2.000', $due->last_quantity);
        $this->assertSame('2027-09-01', $due->due_on->format('Y-m-d'));
        $this->assertSame([['number' => 'WZ-01G/50/26/09 (faktura FS-01H/137/26/09)', 'issued_on' => '2026-09-01', 'quantity' => 2]], $due->last_documents);
    }

    public function test_command_stores_customer_card_details_and_unreadable_columns(): void
    {
        $this->position(4737, InspectionPosition::TYPE_SERVICE, ['interval_months' => 12]);
        $this->xl->inspectionLineRows = [FakeErpXlGateway::inspectionLine(30, $this->d('2026-09-01'), 40, 4737, 3, 27, 4)];
        ErpCustomer::query()->create(['xl_gid' => 40, 'acronym' => 'ALFA', 'name' => 'Alfa']);
        $this->xl->cardRows = [FakeErpXlGateway::card(40, 'ALFA', ['phone' => null, 'street' => 'ul. Krótka 2'])];
        $this->xl->cardUnavailable = ['phone', 'phone2'];
        $this->xl->contactRows = [
            ['customer_gid' => 40, 'name' => 'Jan Nowak', 'position' => 'kierownik BHP', 'email' => 'jan@alfa.pl', 'phone' => null, 'mobile' => null],
        ];
        $this->xl->contactUnavailable = ['phone', 'mobile'];
        $this->xl->managerRows = [['customer_gid' => 40, 'first_name' => 'Anna', 'last_name' => 'Kowal', 'acronym' => 'AKOW', 'email' => 'anna@supon.pl']];

        $this->artisan('erp:inspections')
            ->expectsOutputToContain('Kartoteka klientów: 1 klientów, 1 osób kontaktowych; bez prawa odczytu w XL: phone, phone2, contact_phone, contact_mobile.')
            ->assertSuccessful();

        $c = ErpCustomer::query()->where('xl_gid', 40)->firstOrFail();
        $this->assertSame('ul. Krótka 2', $c->street);
        $this->assertSame('35-001', $c->postal_code);
        $this->assertNull($c->phone);
        $this->assertSame([['name' => 'Jan Nowak', 'position' => 'kierownik BHP', 'email' => 'jan@alfa.pl']], $c->contacts);
        $this->assertSame('Anna Kowal', $c->account_manager);
        $this->assertSame('anna@supon.pl', $c->account_manager_email);
        $this->assertNotNull($c->details_synced_at);
        $this->assertSame(['phone', 'phone2', 'contact_phone', 'contact_mobile'], Cache::get(InspectionCustomerDetails::UNAVAILABLE_KEY));
    }

    public function test_command_reads_xl_and_rebuilds_due_rows(): void
    {
        $position = $this->position(4737, InspectionPosition::TYPE_SERVICE, ['interval_months' => 12]);
        $this->xl->inspectionLineRows = [FakeErpXlGateway::inspectionLine(30, $this->d('2026-09-01'), 40, 4737, 3, 27, 4)];

        $this->artisan('erp:inspections')
            ->expectsOutputToContain('Usługi w katalogu XL: 0, pozycje faktur zapisane: 1, usunięte nieaktualne: 0, towary z doczytaną historią: 0.')
            ->expectsOutputToContain('Terminy przeglądów przeliczone: 1 (klient i pozycja).')
            ->assertSuccessful();

        $due = InspectionDue::query()->where('inspection_position_id', $position->id)->firstOrFail();
        $this->assertSame('2027-09-01', $due->due_on->format('Y-m-d'));
    }

    public function test_command_without_xl_only_rebuilds_and_disabled_xl_is_skipped(): void
    {
        $position = $this->position(4737, InspectionPosition::TYPE_SERVICE, ['interval_months' => 6]);
        $this->storedLine(1, '2026-03-31', 40, 4737, 4);
        $this->xl->isConfigured = false;

        $this->artisan('erp:inspections --no-xl')
            ->expectsOutputToContain('Terminy przeglądów przeliczone: 1 (klient i pozycja).')
            ->assertSuccessful();
        $this->assertSame([], $this->xl->inspectionLineCalls);
        $this->assertSame('2026-09-30', InspectionDue::query()->where('inspection_position_id', $position->id)->firstOrFail()->due_on->format('Y-m-d'));

        $this->artisan('erp:inspections')
            ->expectsOutputToContain('pomijam')
            ->assertSuccessful();
        $this->assertSame([], $this->xl->inspectionLineCalls);
    }

    public function test_command_rejects_invalid_since(): void
    {
        $this->artisan('erp:inspections --since=2019-13-01')->assertExitCode(2);
        $this->artisan('erp:inspections --since=2030-01-01')->assertExitCode(2);
        $this->assertSame([], $this->xl->inspectionLineCalls);
    }

    private function d(string $date): int
    {
        return ClarionDate::fromDate(CarbonImmutable::parse($date));
    }

    /** @param  array<string, mixed>  $attributes */
    private function position(int $xlGid, int $type, array $attributes = []): InspectionPosition
    {
        return InspectionPosition::query()->create([
            'xl_gid' => $xlGid, 'xl_type' => $type, 'code' => 'K'.$xlGid, 'name' => 'Pozycja '.$xlGid, 'interval_months' => 12,
            ...$attributes,
        ]);
    }

    private function erpItem(int $xlGid, string $code, string $name): ErpItem
    {
        return ErpItem::query()->create(['xl_gid' => $xlGid, 'code' => $code, 'name' => $name, 'unit' => 'szt']);
    }

    /** Wiersz zapisany wcześniejszym odczytem (synced_at = wczoraj). */
    private function storedLine(int $documentId, string $issued, int $customer, int $item, int $itemType): void
    {
        $at = now()->subDay();
        DB::table('inspection_sale_lines')->insert([
            'document_type' => 2033, 'document_id' => $documentId, 'line' => 1, 'document_number' => 'FS-01G/'.$documentId.'/26/09',
            'issued_on' => $issued, 'sold_on' => null, 'customer_xl_gid' => $customer, 'recipient_xl_gid' => null,
            'xl_item_gid' => $item, 'xl_item_type' => $itemType, 'quantity' => 1, 'net_value' => 10, 'warehouse_code' => '01G',
            'location' => '01', 'operator_ident' => 'NOMA', 'corrects_document_type' => null, 'corrects_document_id' => null,
            'synced_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }
}
