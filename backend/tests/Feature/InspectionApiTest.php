<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InspectionDismissal;
use App\Models\InspectionPosition;
use App\Models\Offer;
use App\Models\User;
use App\Services\Inspections\InspectionCustomerDetails;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Przeglądy — lista terminów (jeden wiersz na klienta, pozycje w środku), szczegóły klienta, Excel, raport PDF
 * i pomijanie klientów. Wiersze inspection_due wstawiane wprost (liczy je InspectionDueBuilder — osobne testy).
 */
final class InspectionApiTest extends TestCase
{
    use RefreshDatabase;

    private int $gid = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // 06.10.2026 12:00 w Polsce
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(10, 0));
    }

    public function test_routes_need_permissions(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/inspections')->assertForbidden();
        $this->getJson('/api/inspections/customers/1')->assertForbidden();
        $this->get('/api/inspections/export')->assertForbidden();
        $this->getJson('/api/inspections/report?customer_xl_gids[]=1')->assertForbidden();
        $this->postJson('/api/inspections/dismissals', [])->assertForbidden();
        $this->deleteJson('/api/inspections/dismissals/1')->assertForbidden();

        // sam podgląd listy nie pozwala pomijać klientów
        Sanctum::actingAs($this->user(['inspections.view']));
        $this->getJson('/api/inspections')->assertOk()
            ->assertJsonPath('meta.permissions', ['manage' => false, 'offer' => false]);
        $this->postJson('/api/inspections/dismissals', ['customer_xl_gid' => 1, 'reason' => 'skip'])->assertForbidden();

        // pomijanie: z ofertami albo z prowadzeniem listy pozycji
        Sanctum::actingAs($this->user(['inspections.offer']));
        $this->getJson('/api/inspections')->assertForbidden();
        $this->postJson('/api/inspections/dismissals', [])->assertUnprocessable();

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->getJson('/api/inspections')->assertOk()
            ->assertJsonPath('meta.permissions', ['manage' => true, 'offer' => true]);
    }

    public function test_default_window_is_30_days_with_overdue_and_old_overdue_hidden(): void
    {
        $yearly = $this->position('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 12);
        $monthly = $this->position('PRZEGLĄD HYDRANTU', 1);
        $soon = $this->customer('SOON');
        $later = $this->customer('LATER');
        $overdue = $this->customer('OVERDUE');
        $ancient = $this->customer('ANCIENT');
        $monthlyOld = $this->customer('MONTHLY-OLD');
        $monthlyRecent = $this->customer('MONTHLY-RECENT');
        $edge = $this->customer('EDGE');

        $this->due($soon, $yearly, '2026-10-20');
        $this->due($later, $yearly, '2026-11-20');
        $this->due($overdue, $yearly, '2026-10-01');
        // zaległy ponad 3 × 12 miesięcy
        $this->due($ancient, $yearly, '2023-10-05');
        // interwał 1 miesiąc: zaległy ponad 3 miesiące — ukryty, 2 miesiące — widoczny
        $this->due($monthlyOld, $monthly, '2026-07-01');
        $this->due($monthlyRecent, $monthly, '2026-08-10');
        // dokładnie dziś + 30 dni — w oknie
        $this->due($edge, $yearly, '2026-11-05');

        Sanctum::actingAs($this->user(['inspections.view']));
        $res = $this->getJson('/api/inspections')->assertOk();
        $this->assertSame(['MONTHLY-RECENT', 'OVERDUE', 'SOON', 'EDGE'], $this->acronyms($res->json('data')));
        $this->assertSame(4, $res->json('meta.total'));
        $this->assertSame('2026-10-06', $res->json('meta.today'));
        $this->assertSame(30, $res->json('meta.days'));

        $row = collect($res->json('data'))->firstWhere('customer.acronym', 'OVERDUE');
        $this->assertSame('overdue', $row['status']);
        $this->assertSame(5, $row['overdue_days']);
        $this->assertNull($row['days_left']);
        $row = collect($res->json('data'))->firstWhere('customer.acronym', 'SOON');
        $this->assertSame('upcoming', $row['status']);
        $this->assertSame(14, $row['days_left']);
        $this->assertNull($row['overdue_days']);

        $this->assertSame(['MONTHLY-RECENT', 'OVERDUE', 'SOON', 'EDGE', 'LATER'], $this->acronyms($this->getJson('/api/inspections?days=60')->json('data')));
        $this->assertSame(
            ['ANCIENT', 'MONTHLY-OLD', 'MONTHLY-RECENT', 'OVERDUE', 'SOON', 'EDGE'],
            $this->acronyms($this->getJson('/api/inspections?old=1')->json('data')),
        );
        $this->assertSame(['MONTHLY-RECENT', 'OVERDUE'], $this->acronyms($this->getJson('/api/inspections?status=overdue')->json('data')));
        $this->assertSame(['SOON', 'EDGE'], $this->acronyms($this->getJson('/api/inspections?status=upcoming')->json('data')));

        $this->getJson('/api/inspections?days=731')->assertUnprocessable()
            ->assertJsonPath('errors.days.0', 'Termin można ustawić najwyżej na 730 dni naprzód.');
        $this->getJson('/api/inspections?sort=bad')->assertUnprocessable()
            ->assertJsonPath('errors.sort.0', 'Nieznana wartość pola sort.');
    }

    public function test_one_row_per_customer_with_positions_and_full_position_shape(): void
    {
        $gp6 = $this->position('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 12, code: 'UPRGP6');
        $gp2 = $this->position('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-2', 12, code: 'UPRGP2');
        $firma = $this->customer('FIRMA', name: 'Firma Sp. z o.o.', nip: '123-456-78-90', city: 'Rzeszów', emails: ['a@b.pl']);
        $odbiorca = $this->customer('ODB', name: 'Odbiorca');
        $this->due($firma, $gp6, '2026-10-20', [
            'open_count' => 1, 'open_quantity' => 10, 'last_on' => '2025-10-20', 'last_quantity' => 10, 'last_net' => 91.4,
            'last_documents' => json_encode([['number' => 'FS-123/25/01G', 'issued_on' => '2025-10-20', 'quantity' => 10]]),
            'first_on' => '2022-10-11', 'location' => '01', 'operator_ident' => 'NOMA', 'recipient_xl_gid' => $odbiorca,
        ]);
        // odbiorca = nabywca → bez odbiorcy
        $this->due($firma, $gp2, '2026-10-10', ['recipient_xl_gid' => $firma, 'location' => '15']);

        Sanctum::actingAs($this->user(['inspections.view']));
        $res = $this->getJson('/api/inspections')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $row = $res->json('data.0');
        $this->assertSame([
            'xl_gid' => $firma, 'acronym' => 'FIRMA', 'name' => 'Firma Sp. z o.o.', 'nip' => '123-456-78-90', 'city' => 'Rzeszów',
            'emails' => ['a@b.pl'], 'archived' => false, 'known' => true,
            'street' => null, 'address_line2' => null, 'postal_code' => null, 'voivodeship' => null, 'phones' => [], 'contacts' => [],
            'account_manager' => null, 'main_operator' => null, 'last_sale_on' => null, 'client_id' => null, 'details_synced_at' => null,
        ], $row['customer']);
        // termin klienta = najwcześniejszy termin pozycji
        $this->assertSame('2026-10-10', $row['due_on']);
        $this->assertSame(4, $row['days_left']);
        $this->assertNull($row['last_offer']);
        $this->assertNull($row['dismissal']);
        $this->assertSame([$gp2->id, $gp6->id], array_column($row['positions'], 'position_id'));

        $p = $row['positions'][1];
        $this->assertSame('UPRGP6', $p['code']);
        $this->assertSame(InspectionPosition::TYPE_SERVICE, $p['xl_type']);
        $this->assertSame(12, $p['interval_months']);
        $this->assertSame('2026-10-20', $p['due_on']);
        $this->assertSame('upcoming', $p['status']);
        $this->assertSame(14, $p['days_left']);
        $this->assertEquals(10, $p['open_quantity']);
        $this->assertEquals(91.4, $p['last_net']);
        $this->assertSame('2025-10-20', $p['last_on']);
        $this->assertSame([['number' => 'FS-123/25/01G', 'issued_on' => '2025-10-20', 'quantity' => 10]], $p['last_documents']);
        $this->assertSame('2022-10-11', $p['first_on']);
        $this->assertSame('01', $p['location']);
        $this->assertSame('Rzeszów', $p['location_name']);
        $this->assertSame('NOMA', $p['operator_ident']);
        $this->assertSame(['xl_gid' => $odbiorca, 'name' => 'Odbiorca'], $p['recipient']);
        $this->assertFalse($p['same_nip_newer']);
        $this->assertNull($p['dismissal']);
        $this->assertNull($row['positions'][0]['recipient']);
        $this->assertSame('Kraków', $row['positions'][0]['location_name']);

        $this->assertSame([['code' => '01', 'name' => 'Rzeszów'], ['code' => '15', 'name' => 'Kraków']], $res->json('meta.locations'));
        $this->assertSame(
            [['id' => $gp2->id, 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-2', 'interval_months' => 12], ['id' => $gp6->id, 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 'interval_months' => 12]],
            $res->json('meta.positions'),
        );
    }

    public function test_customer_missing_from_erp_customers_is_shown_by_number(): void
    {
        $position = $this->position('PRZEGLĄD GAŚNICY', 12);
        $this->due(777, $position, '2026-10-15');

        Sanctum::actingAs($this->user(['inspections.view']));
        $this->getJson('/api/inspections')->assertOk()
            ->assertJsonPath('data.0.customer', [
                'xl_gid' => 777, 'acronym' => 'Klient XL 777', 'name' => null, 'nip' => null, 'city' => null,
                'emails' => [], 'archived' => false, 'known' => false,
                'street' => null, 'address_line2' => null, 'postal_code' => null, 'voivodeship' => null, 'phones' => [], 'contacts' => [],
                'account_manager' => null, 'main_operator' => null, 'last_sale_on' => null, 'client_id' => null, 'details_synced_at' => null,
            ]);
        $this->getJson('/api/inspections/customers/777')->assertOk()->assertJsonPath('customer.known', false);
        $this->getJson('/api/inspections/customers/778')->assertNotFound();
    }

    public function test_customer_details_show_card_contacts_manager_client_link_and_unreadable_columns(): void
    {
        $position = $this->position('PRZEGLĄD GAŚNICY', 12);
        $gid = $this->customer('ALFA', 'Alfa Sp. z o.o.', city: 'Rybnik', emails: ['biuro@alfa.pl']);
        DB::table('erp_customers')->where('xl_gid', $gid)->update([
            'street' => 'ul. Krótka 2', 'postal_code' => '44-200', 'voivodeship' => 'śląskie', 'phone' => '32 422 00 00',
            'contacts' => json_encode([['name' => 'Jan Nowak', 'position' => 'kierownik BHP', 'email' => 'jan@alfa.pl']]),
            'account_manager' => 'Anna Kowal', 'account_manager_email' => 'anna@supon.pl', 'last_sale_at' => '2026-09-01',
            'main_operator' => 'KOKR', 'details_synced_at' => now(),
        ]);
        $clientId = DB::table('clients')->insertGetId(['name' => 'Alfa', 'xl_gid' => $gid, 'source' => 'erp_xl', 'created_at' => now(), 'updated_at' => now()]);
        $this->due($gid, $position, '2026-10-10');
        Cache::forever(InspectionCustomerDetails::UNAVAILABLE_KEY, ['contact_phone']);

        Sanctum::actingAs($this->user(['inspections.view']));
        $res = $this->getJson('/api/inspections/customers/'.$gid)->assertOk();

        $res->assertJsonPath('customer.street', 'ul. Krótka 2')
            ->assertJsonPath('customer.postal_code', '44-200')
            ->assertJsonPath('customer.phones', ['32 422 00 00'])
            ->assertJsonPath('customer.contacts', [['name' => 'Jan Nowak', 'position' => 'kierownik BHP', 'email' => 'jan@alfa.pl', 'phone' => null, 'mobile' => null]])
            ->assertJsonPath('customer.account_manager', ['name' => 'Anna Kowal', 'email' => 'anna@supon.pl'])
            ->assertJsonPath('customer.main_operator', 'KOKR')
            ->assertJsonPath('customer.last_sale_on', '2026-09-01')
            ->assertJsonPath('customer.client_id', $clientId)
            ->assertJsonPath('details_unavailable', ['contact_phone']);
    }

    public function test_filters_location_position_query_email_and_mine(): void
    {
        $gp6 = $this->position('PRZEGLĄD GAŚNICY GP-6', 12);
        $hydrant = $this->position('PRZEGLĄD HYDRANTU', 12);
        $a = $this->customer('ALFA', name: 'Alfa Serwis', nip: '813-000-11-22', city: 'Rzeszów', emails: ['alfa@x.pl']);
        $b = $this->customer('BETA', name: 'Beta Handel', nip: '9990001111', city: 'Kraków');
        $this->due($a, $gp6, '2026-10-10', ['location' => '01', 'operator_ident' => 'NOMA']);
        $this->due($b, $hydrant, '2026-10-12', ['location' => '15', 'operator_ident' => 'KOWA']);

        Sanctum::actingAs($this->user(['inspections.view']));
        $this->assertSame(['BETA'], $this->acronyms($this->getJson('/api/inspections?location=15')->json('data')));
        $this->assertSame(['ALFA'], $this->acronyms($this->getJson('/api/inspections?position_id='.$gp6->id)->json('data')));
        $this->assertSame(['ALFA'], $this->acronyms($this->getJson('/api/inspections?with_email=1')->json('data')));
        $this->assertSame(['BETA'], $this->acronyms($this->getJson('/api/inspections?q=krak')->json('data')));
        $this->assertSame(['ALFA'], $this->acronyms($this->getJson('/api/inspections?q=alfa%20serwis')->json('data')));
        // NIP bez kresek pasuje do NIP-u zapisanego z kreskami
        $this->assertSame(['ALFA'], $this->acronyms($this->getJson('/api/inspections?q=8130001122')->json('data')));
        $this->assertSame([], $this->acronyms($this->getJson('/api/inspections?q=gamma')->json('data')));
        $this->getJson('/api/inspections?location=Rz')->assertUnprocessable();

        // konto bez operatora XL — „moi klienci” niedostępni
        $this->getJson('/api/inspections?mine=1')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.mine_unavailable', true);

        $user = $this->user(['inspections.view']);
        $user->forceFill(['erp_operator_ident' => 'noma'])->save();
        Sanctum::actingAs($user);
        $this->getJson('/api/inspections?mine=1')->assertOk()->assertJsonPath('meta.mine_unavailable', false);
        $this->assertSame(['ALFA'], $this->acronyms($this->getJson('/api/inspections?mine=1')->json('data')));
    }

    public function test_dismissals_hide_customer_or_position_until_they_expire(): void
    {
        $gp6 = $this->position('PRZEGLĄD GAŚNICY GP-6', 12);
        $gp2 = $this->position('PRZEGLĄD GAŚNICY GP-2', 12);
        $forever = $this->customer('FOREVER');
        $expired = $this->customer('EXPIRED');
        $untilToday = $this->customer('TODAY');
        $partial = $this->customer('PARTIAL');
        foreach ([$forever, $expired, $untilToday] as $gid) {
            $this->due($gid, $gp6, '2026-10-10');
        }
        $this->due($partial, $gp6, '2026-10-10');
        $this->due($partial, $gp2, '2026-10-11');
        $admin = User::factory()->withRole('admin')->create(['name' => 'Jan Nowak']);
        $this->dismissal($forever, null, null, $admin);
        $this->dismissal($expired, null, '2026-10-05', $admin);
        // „do dziś” jeszcze obowiązuje
        $this->dismissal($untilToday, null, '2026-10-06', $admin);
        $this->dismissal($partial, $gp6->id, null, $admin);

        Sanctum::actingAs($this->user(['inspections.view']));
        $res = $this->getJson('/api/inspections')->assertOk();
        $this->assertSame(['EXPIRED', 'PARTIAL'], $this->acronyms($res->json('data')));
        $partialRow = collect($res->json('data'))->firstWhere('customer.acronym', 'PARTIAL');
        $this->assertSame([$gp2->id], array_column($partialRow['positions'], 'position_id'));
        $this->assertSame('2026-10-11', $partialRow['due_on']);

        $res = $this->getJson('/api/inspections?dismissed=1')->assertOk();
        // remis terminu — po numerze klienta XL
        $this->assertSame(['FOREVER', 'EXPIRED', 'TODAY', 'PARTIAL'], $this->acronyms($res->json('data')));
        $foreverRow = collect($res->json('data'))->firstWhere('customer.acronym', 'FOREVER');
        $this->assertSame('skip', $foreverRow['dismissal']['reason']);
        $this->assertNull($foreverRow['dismissal']['until_on']);
        $this->assertSame('Jan Nowak', $foreverRow['dismissal']['user_name']);
        // wygasłe pominięcie nie jest pokazywane jako aktywne
        $this->assertNull(collect($res->json('data'))->firstWhere('customer.acronym', 'EXPIRED')['dismissal']);
        $partialRow = collect($res->json('data'))->firstWhere('customer.acronym', 'PARTIAL');
        $this->assertNull($partialRow['dismissal']);
        $this->assertSame($gp6->id, $partialRow['positions'][0]['position_id']);
        $this->assertNotNull($partialRow['positions'][0]['dismissal']);
        $this->assertNull($partialRow['positions'][1]['dismissal']);
    }

    public function test_pagination_by_customers_and_sorting(): void
    {
        $position = $this->position('PRZEGLĄD GAŚNICY', 12);
        $second = $this->position('PRZEGLĄD HYDRANTU', 12);
        $c = $this->customer('CHARLIE');
        $a = $this->customer('ALFA');
        $b = $this->customer('BRAVO');
        $this->due($c, $position, '2026-10-08', ['last_net' => 50]);
        $this->due($c, $second, '2026-10-30', ['last_net' => 500]);
        $this->due($a, $position, '2026-10-20', ['last_net' => 300]);
        $this->due($b, $position, '2026-10-12', ['last_net' => null]);

        Sanctum::actingAs($this->user(['inspections.view']));
        $res = $this->getJson('/api/inspections?per_page=2')->assertOk();
        $this->assertSame(['CHARLIE', 'BRAVO'], $this->acronyms($res->json('data')));
        $this->assertSame(3, $res->json('meta.total'));
        $this->assertSame(2, $res->json('meta.per_page'));
        // klient na stronie ma wszystkie swoje pozycje, choć strona liczy klientów
        $this->assertCount(2, $res->json('data.0.positions'));
        $res = $this->getJson('/api/inspections?per_page=2&page=2')->assertOk();
        $this->assertSame(['ALFA'], $this->acronyms($res->json('data')));
        $this->assertSame(2, $res->json('meta.page'));

        $this->assertSame(['ALFA', 'BRAVO', 'CHARLIE'], $this->acronyms($this->getJson('/api/inspections?sort=customer')->json('data')));
        // wartość = suma netto ostatnich przeglądów pozycji klienta; bez wartości na końcu
        $this->assertSame(['CHARLIE', 'ALFA', 'BRAVO'], $this->acronyms($this->getJson('/api/inspections?sort=value')->json('data')));
        $this->getJson('/api/inspections?per_page=201')->assertUnprocessable();
    }

    public function test_last_offer_is_newest_sent_inspection_offer_by_anyone(): void
    {
        $position = $this->position('PRZEGLĄD GAŚNICY', 12);
        $gid = $this->customer('FIRMA');
        $this->due($gid, $position, '2026-10-10');
        $jan = User::factory()->create(['name' => 'Jan Nowak']);
        $ewa = User::factory()->create(['name' => 'Ewa Lis']);
        Offer::query()->create(['user_id' => $jan->id, 'kind' => Offer::KIND_INSPECTION, 'customer_xl_gid' => $gid, 'subject' => 'a', 'last_sent_at' => '2026-08-01 10:00:00']);
        $newest = Offer::query()->create(['user_id' => $ewa->id, 'kind' => Offer::KIND_INSPECTION, 'customer_xl_gid' => $gid, 'subject' => 'b', 'last_sent_at' => '2026-09-01 10:00:00']);
        // szkic (niewysłany) i oferta produktów się nie liczą
        Offer::query()->create(['user_id' => $jan->id, 'kind' => Offer::KIND_INSPECTION, 'customer_xl_gid' => $gid, 'subject' => 'c']);
        Offer::query()->create(['user_id' => $jan->id, 'kind' => Offer::KIND_PRODUCTS, 'customer_xl_gid' => $gid, 'subject' => 'd', 'last_sent_at' => '2026-10-01 10:00:00']);

        Sanctum::actingAs($this->user(['inspections.view']));
        $this->getJson('/api/inspections')->assertOk()
            ->assertJsonPath('data.0.last_offer', [
                'offer_id' => $newest->id,
                'code' => 'OF-'.str_pad((string) $newest->id, 4, '0', STR_PAD_LEFT),
                'sent_at' => '2026-09-01T10:00:00+00:00',
                'user_name' => 'Ewa Lis',
            ]);
    }

    public function test_customer_details_with_all_positions_history_offers_and_dismissals(): void
    {
        $goods = $this->position('GAŚNICA PROSZ.GP-6X ABC', 12, type: InspectionPosition::TYPE_GOODS, gid: 4001, renewedBy: 4737);
        $service = $this->position('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 12, gid: 4737, code: 'UPRGP6');
        DB::table('erp_services')->insert(['xl_gid' => 4737, 'xl_type' => 4, 'code' => 'UPRGP6', 'name' => 'PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', 'created_at' => now(), 'updated_at' => now()]);
        $gid = $this->customer('FIRMA');
        // termin poza oknem i stary zaległy — w szczegółach są wszystkie
        $this->due($gid, $goods, '2027-05-01');
        $this->due($gid, $service, '2022-01-01');
        $this->saleLine($gid, 4001, '2025-01-10', 'FS-1/25', 1, 5, 500);
        $this->saleLine($gid, 4737, '2026-02-01', 'FS-9/26', 1, 5, 45.5, location: '01', operator: 'NOMA');
        $this->saleLine($gid, 4737, '2026-02-15', 'FSK-1/26', 1, -1, -9.1);
        // inna usługa (spoza pozycji) i inny klient — poza historią
        $this->saleLine($gid, 9999, '2026-03-01', 'FS-10/26', 1, 1, 10);
        $this->saleLine($gid + 1, 4737, '2026-03-01', 'FS-11/26', 1, 1, 10);
        $user = User::factory()->create(['name' => 'Jan Nowak']);
        $offer = Offer::query()->create(['user_id' => $user->id, 'kind' => Offer::KIND_INSPECTION, 'customer_xl_gid' => $gid, 'subject' => 'x']);
        $this->dismissal($gid, $service->id, '2026-01-01', $user);

        Sanctum::actingAs($this->user(['inspections.view']));
        $res = $this->getJson('/api/inspections/customers/'.$gid)->assertOk();
        $this->assertSame('FIRMA', $res->json('customer.acronym'));
        $this->assertSame([$service->id, $goods->id], array_column($res->json('positions'), 'position_id'));
        $this->assertSame(['FSK-1/26', 'FS-9/26', 'FS-1/25'], array_column($res->json('history'), 'document_number'));
        $this->assertTrue($res->json('history.0.is_correction'));
        $this->assertEquals(-1, $res->json('history.0.quantity'));
        $this->assertSame('UPRGP6', $res->json('history.1.code'));
        $this->assertSame('PRZEGLĄD GAŚNICY PROSZKOWEJ GP-6', $res->json('history.1.name'));
        $this->assertSame('01', $res->json('history.1.location'));
        $this->assertSame('NOMA', $res->json('history.1.operator_ident'));
        $this->assertSame('GAŚNICA PROSZ.GP-6X ABC', $res->json('history.2.name'));
        $this->assertSame([['offer_id' => $offer->id, 'code' => $offer->fresh()->code, 'sent_at' => null, 'user_name' => 'Jan Nowak']], $res->json('offers'));
        $this->assertCount(1, $res->json('dismissals'));
        $this->assertFalse($res->json('dismissals.0.active'));
        $this->assertSame($service->id, $res->json('dismissals.0.position_id'));
    }

    public function test_export_returns_xlsx(): void
    {
        $position = $this->position('PRZEGLĄD GAŚNICY', 12);
        $a = $this->customer('ALFA', emails: ['a@x.pl']);
        $b = $this->customer('BETA');
        $this->due($a, $position, '2026-10-10');
        $this->due($b, $position, '2026-10-12');

        Sanctum::actingAs($this->user(['inspections.view']));
        $res = $this->get('/api/inspections/export?customer_xl_gids[]='.$a)->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('przeglady-2026-10-06.xlsx', (string) $res->headers->get('Content-Disposition'));
        $content = file_get_contents($res->getFile()->getPathname());
        $this->assertIsString($content);
        $this->assertStringStartsWith('PK', $content);
    }

    public function test_export_of_selected_customers_ignores_list_filters(): void
    {
        // zaznaczenie przeżywa zmianę filtrów — klient zaznaczony przy szerszym oknie nie może po cichu wypaść z pliku
        $position = $this->position('PRZEGLĄD GAŚNICY', 12);
        $a = $this->customer('ALFA');
        $b = $this->customer('BETA');
        $this->due($a, $position, '2026-12-20');
        $this->due($b, $position, '2026-10-10');

        Sanctum::actingAs($this->user(['inspections.view']));
        $res = $this->get('/api/inspections/export?days=7&q=BETA&customer_xl_gids[]='.$a)->assertOk();
        $sheet = IOFactory::load($res->getFile()->getPathname())->getSheet(0);
        $this->assertSame(2, $sheet->getHighestDataRow());
        $this->assertSame('ALFA', (string) $sheet->getCell('A2')->getValue());

        $this->getJson('/api/inspections/export?'.http_build_query(['customer_xl_gids' => range(1, 201)]))
            ->assertStatus(422)->assertJsonValidationErrors('customer_xl_gids');
    }

    public function test_report_returns_pdf_and_requires_customers(): void
    {
        $position = $this->position('PRZEGLĄD GAŚNICY', 12);
        $a = $this->customer('ALFA', name: 'Żółta Gęś Sp. z o.o.');
        $this->due($a, $position, '2026-10-10', ['last_net' => 99.5]);

        Sanctum::actingAs($this->user(['inspections.view']));
        $res = $this->get('/api/inspections/report?customer_xl_gids[]='.$a.'&customer_xl_gids[]=12345')->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());

        $this->getJson('/api/inspections/report')->assertUnprocessable()
            ->assertJsonPath('errors.customer_xl_gids.0', 'Zaznacz co najmniej jednego klienta.');
        $this->getJson('/api/inspections/report?'.http_build_query(['customer_xl_gids' => range(1, 201)]))->assertUnprocessable()
            ->assertJsonPath('errors.customer_xl_gids.0', 'Raport mieści najwyżej 200 klientów — zaznacz mniej.');
    }

    public function test_dismissal_create_overwrite_validate_and_delete(): void
    {
        $position = $this->position('PRZEGLĄD GAŚNICY', 12);
        $gid = $this->customer('FIRMA');
        $this->due($gid, $position, '2026-10-10');
        Sanctum::actingAs($this->user(['inspections.manage'], 'Ewa Lis'));

        $res = $this->postJson('/api/inspections/dismissals', ['customer_xl_gid' => $gid, 'position_id' => null, 'until_on' => '2027-01-01', 'reason' => 'other_company', 'note' => ' robi u konkurencji '])
            ->assertCreated();
        $this->assertSame('2027-01-01', $res->json('dismissal.until_on'));
        $this->assertSame('robi u konkurencji', $res->json('dismissal.note'));
        $this->assertSame('Ewa Lis', $res->json('dismissal.user_name'));
        $this->assertNull($res->json('dismissal.position_id'));

        // ten sam klient i zakres — nadpisuje
        $this->postJson('/api/inspections/dismissals', ['customer_xl_gid' => $gid, 'reason' => 'resigned'])->assertCreated()
            ->assertJsonPath('dismissal.until_on', null);
        $this->assertSame(1, InspectionDismissal::query()->count());
        $this->assertSame('resigned', InspectionDismissal::query()->first()?->reason);
        // pominięcie jednej pozycji to osobny zakres
        $this->postJson('/api/inspections/dismissals', ['customer_xl_gid' => $gid, 'position_id' => $position->id, 'reason' => 'skip'])->assertCreated();
        $this->assertSame(2, InspectionDismissal::query()->count());

        $this->postJson('/api/inspections/dismissals', ['customer_xl_gid' => $gid, 'until_on' => '2026-10-06', 'reason' => 'skip'])
            ->assertUnprocessable()->assertJsonPath('errors.until_on.0', 'Data „pomiń do” musi być późniejsza niż dziś.');
        $this->postJson('/api/inspections/dismissals', ['customer_xl_gid' => $gid, 'reason' => 'bad'])
            ->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Wybierz powód pominięcia z listy.');
        $this->postJson('/api/inspections/dismissals', ['customer_xl_gid' => 424242, 'reason' => 'skip'])
            ->assertUnprocessable()->assertJsonPath('errors.customer_xl_gid.0', 'Nie ma takiego klienta w przeglądach — odśwież stronę.');
        $this->postJson('/api/inspections/dismissals', ['customer_xl_gid' => $gid, 'position_id' => 999, 'reason' => 'skip'])
            ->assertUnprocessable()->assertJsonPath('errors.position_id.0', 'Tej pozycji nie ma już na liście przeglądów — odśwież stronę.');

        $id = (int) InspectionDismissal::query()->whereNull('inspection_position_id')->value('id');
        $this->deleteJson('/api/inspections/dismissals/'.$id)->assertNoContent();
        $this->deleteJson('/api/inspections/dismissals/'.$id)->assertNotFound();
        $this->assertSame(1, InspectionDismissal::query()->count());
    }

    /** @param  list<string>  $permissions */
    private function user(array $permissions, string $name = 'Tester'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function position(string $name, int $interval, int $type = InspectionPosition::TYPE_SERVICE, ?int $gid = null, ?string $code = null, ?int $renewedBy = null): InspectionPosition
    {
        return InspectionPosition::query()->create([
            'xl_gid' => $gid ?? ++$this->gid,
            'xl_type' => $type,
            'code' => $code ?? 'KOD'.$this->gid,
            'name' => $name,
            'unit' => 'szt',
            'interval_months' => $interval,
            'renewed_by_xl_gid' => $renewedBy,
        ]);
    }

    /** @param  list<string>  $emails */
    private function customer(string $acronym, ?string $name = null, ?string $nip = null, ?string $city = null, array $emails = []): int
    {
        $gid = ++$this->gid;
        DB::table('erp_customers')->insert([
            'xl_gid' => $gid,
            'acronym' => $acronym,
            'name' => $name,
            'nip' => $nip,
            'city' => $city,
            'emails' => $emails === [] ? null : json_encode($emails),
            'archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $gid;
    }

    /** @param  array<string, mixed>  $extra */
    private function due(int $customer, InspectionPosition $position, string $dueOn, array $extra = []): void
    {
        DB::table('inspection_due')->insert([
            'customer_xl_gid' => $customer,
            'inspection_position_id' => $position->id,
            'due_on' => $dueOn,
            'open_count' => 1,
            'open_quantity' => 2,
            'last_on' => '2025-10-01',
            'last_quantity' => 2,
            'last_net' => 20,
            'last_documents' => '[]',
            'first_on' => '2024-10-01',
            'same_nip_newer' => false,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$extra,
        ]);
    }

    private function dismissal(int $customer, ?int $positionId, ?string $until, User $user): void
    {
        InspectionDismissal::query()->create([
            'customer_xl_gid' => $customer,
            'inspection_position_id' => $positionId,
            'until_on' => $until,
            'reason' => 'skip',
            'user_id' => $user->id,
        ]);
    }

    private function saleLine(int $customer, int $itemGid, string $issued, string $number, int $line, float $quantity, float $net, ?string $location = null, ?string $operator = null): void
    {
        $type = str_starts_with($number, 'FSK') ? 2041 : 2033;
        DB::table('inspection_sale_lines')->insert([
            'document_type' => $type,
            'document_id' => crc32($number) % 1000000,
            'line' => $line,
            'document_number' => $number,
            'issued_on' => $issued,
            'sold_on' => $issued,
            'customer_xl_gid' => $customer,
            'xl_item_gid' => $itemGid,
            'xl_item_type' => 4,
            'quantity' => $quantity,
            'net_value' => $net,
            'location' => $location,
            'operator_ident' => $operator,
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @return list<string>
     */
    private function acronyms(array $data): array
    {
        return array_map(static fn (array $row): string => (string) $row['customer']['acronym'], $data);
    }
}
