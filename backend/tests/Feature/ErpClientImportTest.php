<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\Erp\ErpClientImport;
use App\Services\Erp\ErpXlGateway;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeErpXlGateway;
use Tests\TestCase;

/** Zakładka Klienci z ERP XL: próg zakupów w roku, pełna karta, osoby, opiekun, odświeżanie i klienci ręczni. */
final class ErpClientImportTest extends TestCase
{
    use RefreshDatabase;

    private FakeErpXlGateway $xl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00'));
        $this->xl = new FakeErpXlGateway;
        $this->app->instance(ErpXlGateway::class, $this->xl);
    }

    public function test_imports_customers_from_threshold_with_full_card_contacts_and_manager(): void
    {
        $this->xl->salesTotalRows = [
            FakeErpXlGateway::salesTotal(10, 3000.00, $this->d('2026-09-15'), 4),
            // tuż pod progiem — pomijany
            FakeErpXlGateway::salesTotal(20, 2999.99, $this->d('2026-03-01')),
            FakeErpXlGateway::salesTotal(30, 125000.50, $this->d('2026-10-01'), 40),
        ];
        $this->xl->cardRows = [
            FakeErpXlGateway::card(10, 'ACME', [
                'name' => 'ACME Sp. z o.o.', 'nip' => '813-000-00-01', 'regon' => '123456789', 'address_line2' => 'lok. 5',
                'county' => 'Rzeszów', 'commune' => 'Rzeszów', 'phone2' => '600 100 200', 'fax' => '17 850 00 01',
                'email' => 'Biuro@Acme.pl; handel@acme.pl', 'website' => 'www.acme.pl',
            ]),
            FakeErpXlGateway::card(20, 'MALY'),
            FakeErpXlGateway::card(30, 'MITTAL', ['nip' => '6340000000', 'archived' => true, 'phone' => null]),
        ];
        $this->xl->addressEmails = [
            ['gid' => 10, 'email' => 'biuro@acme.pl'],
            ['gid' => 10, 'email' => 'magazyn@acme.pl'],
            ['gid' => 99, 'email' => 'obcy@firma.pl'],
        ];
        $this->xl->contactRows = [
            ['customer_gid' => 10, 'name' => 'Jan Kowalski', 'position' => 'Zaopatrzenie', 'email' => 'jan@acme.pl', 'phone' => null, 'mobile' => '601 000 000'],
            // pusta osoba — pomijana
            ['customer_gid' => 10, 'name' => null, 'position' => null, 'email' => null, 'phone' => null, 'mobile' => null],
            ['customer_gid' => 20, 'name' => 'Spoza progu', 'position' => null, 'email' => null, 'phone' => null, 'mobile' => null],
        ];
        $this->xl->managerRows = [
            ['customer_gid' => 10, 'first_name' => 'Anna', 'last_name' => 'Nowak', 'acronym' => 'NOAN', 'email' => 'anna@supon.pl'],
            ['customer_gid' => 10, 'first_name' => 'Drugi', 'last_name' => 'Opiekun', 'acronym' => 'DROP', 'email' => null],
            ['customer_gid' => 30, 'first_name' => null, 'last_name' => null, 'acronym' => 'CHLU', 'email' => null],
        ];

        $stats = app(ErpClientImport::class)->run(2026, 3000.0);

        $this->assertSame(['qualifying' => 2, 'created' => 2, 'updated' => 0, 'linked_by_nip' => 0, 'without_card' => 0, 'unavailable' => []], $stats);
        $this->assertSame([$this->d('2026-01-01'), $this->d('2026-12-31')], $this->xl->salesTotalsPeriod);
        $this->assertSame([[10, 30]], $this->xl->cardCalls);
        $this->assertSame(0, Client::query()->where('xl_gid', 20)->count());

        $acme = Client::query()->where('xl_gid', 10)->sole();
        $this->assertSame(Client::SOURCE_ERP_XL, $acme->source);
        $this->assertSame(
            ['ACME Sp. z o.o.', 'ACME', '813-000-00-01', 'PL', '123456789', 'ul. Długa 1', 'lok. 5', '35-001', 'Rzeszów', 'Rzeszów', 'Rzeszów', 'podkarpackie', 'Polska', '17 850 00 00', '600 100 200', '17 850 00 01', 'www.acme.pl'],
            [$acme->name, $acme->acronym, $acme->nip, $acme->nip_prefix, $acme->regon, $acme->street, $acme->address_line2, $acme->postal_code, $acme->city, $acme->county, $acme->commune, $acme->voivodeship, $acme->country, $acme->phone, $acme->phone2, $acme->fax, $acme->website],
        );
        $this->assertSame(['biuro@acme.pl', 'handel@acme.pl', 'magazyn@acme.pl'], $acme->emails);
        $this->assertSame([['name' => 'Jan Kowalski', 'position' => 'Zaopatrzenie', 'email' => 'jan@acme.pl', 'mobile' => '601 000 000']], $acme->contacts);
        // pierwszy opiekun z XL (kolejność: główny pierwszy)
        $this->assertSame(['Anna Nowak', 'anna@supon.pl'], [$acme->account_manager, $acme->account_manager_email]);
        $this->assertSame(['3000.00', 4, '2026-09-15', 2026], [$acme->sales_net, $acme->sale_documents, $acme->last_sale_at->toDateString(), $acme->sales_year]);
        $this->assertNull($acme->owner_id);
        $this->assertFalse($acme->xl_archived);

        $mittal = Client::query()->where('xl_gid', 30)->sole();
        $this->assertTrue($mittal->xl_archived);
        $this->assertNull($mittal->phone);
        $this->assertNull($mittal->contacts);
        // opiekun bez imienia i nazwiska — akronim pracownika
        $this->assertSame('CHLU', $mittal->account_manager);
        $this->assertSame('125000.50', $mittal->sales_net);
    }

    public function test_second_run_refreshes_linked_clients_below_threshold_without_duplicates(): void
    {
        $this->xl->salesTotalRows = [FakeErpXlGateway::salesTotal(10, 5000.0, $this->d('2026-09-15'), 2)];
        $this->xl->cardRows = [FakeErpXlGateway::card(10, 'ACME', ['phone' => '17 111 11 11'])];
        app(ErpClientImport::class)->run(2026, 3000.0);
        $manual = Client::query()->create(['name' => 'Ręczny bez NIP', 'city' => 'Krosno']);

        // nowy rok: klient bez zakupów (zostaje, zakupy 0), zmieniony telefon w XL
        $this->xl->salesTotalRows = [];
        $this->xl->cardRows = [FakeErpXlGateway::card(10, 'ACME', ['phone' => '17 222 22 22'])];
        $stats = app(ErpClientImport::class)->run(2027, 3000.0);

        $this->assertSame(['qualifying' => 0, 'created' => 0, 'updated' => 1, 'linked_by_nip' => 0, 'without_card' => 0, 'unavailable' => []], $stats);
        $acme = Client::query()->where('xl_gid', 10)->sole();
        $this->assertSame(['17 222 22 22', 2027, '0.00', 0, null], [$acme->phone, $acme->sales_year, $acme->sales_net, $acme->sale_documents, $acme->last_sale_at]);
        $this->assertSame(2, Client::query()->count());
        $this->assertSame(['Ręczny bez NIP', 'Krosno', 'manual', null], [$manual->fresh()->name, $manual->fresh()->city, $manual->fresh()->source, $manual->fresh()->xl_gid]);

        // kontrahent zniknął z XL — klient zostaje bez zmian
        $this->xl->cardRows = [];
        $this->assertSame(1, app(ErpClientImport::class)->run(2027, 3000.0)['without_card']);
        $this->assertSame('17 222 22 22', $acme->fresh()->phone);
    }

    public function test_manual_client_with_same_nip_is_linked_and_keeps_typed_data(): void
    {
        $owner = User::factory()->create();
        $manual = Client::query()->create(['name' => 'Huta (wpisana ręcznie)', 'nip' => 'PL 634 000 00 00', 'city' => null, 'owner_id' => $owner->id]);
        // dwa ręczne wpisy z tym samym NIP-em — nie zgadujemy, którego powiązać
        Client::query()->create(['name' => 'Dubel A', 'nip' => '5170000000']);
        Client::query()->create(['name' => 'Dubel B', 'nip' => '517-000-00-00']);
        $this->xl->salesTotalRows = [
            FakeErpXlGateway::salesTotal(30, 9000.0, $this->d('2026-08-01')),
            FakeErpXlGateway::salesTotal(40, 4000.0, $this->d('2026-08-01')),
        ];
        $this->xl->cardRows = [
            FakeErpXlGateway::card(30, 'HUTA', ['name' => 'Huta S.A.', 'nip' => '6340000000', 'city' => 'Stalowa Wola', 'email' => 'huta@huta.pl']),
            FakeErpXlGateway::card(40, 'DUBEL', ['nip' => '5170000000']),
        ];
        $this->xl->managerRows = [['customer_gid' => 30, 'first_name' => 'Anna', 'last_name' => 'Nowak', 'acronym' => 'NOAN', 'email' => null]];

        $stats = app(ErpClientImport::class)->run(2026, 3000.0);

        $this->assertSame([1, 1], [$stats['linked_by_nip'], $stats['created']]);
        $manual->refresh();
        $this->assertSame(
            [30, 'manual', 'Huta (wpisana ręcznie)', 'PL 634 000 00 00', 'Stalowa Wola', ['huta@huta.pl'], 'Anna Nowak', '9000.00', $owner->id],
            [$manual->xl_gid, $manual->source, $manual->name, $manual->nip, $manual->city, $manual->emails, $manual->account_manager, $manual->sales_net, $manual->owner_id],
        );
        // ręczny powiązany + dwa duble bez powiązania + nowy z XL
        $this->assertSame(4, Client::query()->count());
        $this->assertNull(Client::query()->where('name', 'Dubel A')->value('xl_gid'));
        $this->assertSame('erp_xl', Client::query()->where('xl_gid', 40)->value('source'));
    }

    public function test_columns_without_read_permission_do_not_overwrite_saved_values(): void
    {
        $this->xl->salesTotalRows = [FakeErpXlGateway::salesTotal(10, 5000.0, $this->d('2026-09-15'))];
        $this->xl->cardRows = [FakeErpXlGateway::card(10, 'ACME', ['phone' => '17 111 11 11', 'street' => 'ul. Krótka 2'])];
        app(ErpClientImport::class)->run(2026, 3000.0);

        $this->xl->cardUnavailable = ['phone', 'street'];
        $this->xl->contactUnavailable = ['phone', 'mobile'];
        $this->xl->cardRows = [FakeErpXlGateway::card(10, 'ACME', ['postal_code' => '35-002'])];
        $stats = app(ErpClientImport::class)->run(2026, 3000.0);

        $this->assertSame(['phone', 'street', 'contacts.phone', 'contacts.mobile'], $stats['unavailable']);
        $acme = Client::query()->where('xl_gid', 10)->sole();
        $this->assertSame(['17 111 11 11', 'ul. Krótka 2', '35-002'], [$acme->phone, $acme->street, $acme->postal_code]);
    }

    public function test_dry_run_saves_nothing_and_command_reports_counts(): void
    {
        $this->xl->salesTotalRows = [FakeErpXlGateway::salesTotal(10, 5000.0, $this->d('2026-09-15'))];
        $this->xl->cardRows = [FakeErpXlGateway::card(10, 'ACME')];
        $this->xl->cardUnavailable = ['regon'];

        $this->assertSame(0, Artisan::call('erp:clients', ['--dry-run' => true]));
        $this->assertSame(0, Client::query()->count());
        $output = Artisan::output();
        $this->assertStringContainsString('[bez zapisu] Rok 2026, próg 3 000,00 zł: kontrahentów od progu 1; nowych 1', $output);
        $this->assertStringContainsString('nie ma prawa odczytu pól: regon', $output);

        $this->assertSame(0, Artisan::call('erp:clients', ['--min' => '10000']));
        $this->assertSame(0, Client::query()->count());

        $this->assertSame(0, Artisan::call('erp:clients'));
        $this->assertSame(1, Client::query()->count());
    }

    public function test_clients_api_returns_short_list_by_default_and_full_data_on_request(): void
    {
        Client::query()->create([
            'name' => 'ACME', 'nip' => '8130000001', 'city' => 'Rzeszów', 'source' => 'erp_xl', 'xl_gid' => 10, 'phone' => '17 850 00 00',
            'contacts' => [['name' => 'Jan Kowalski']], 'emails' => ['biuro@acme.pl'], 'sales_year' => 2026, 'sales_net' => 5000,
        ]);
        Sanctum::actingAs($this->userWith(['clients.view']));

        $short = $this->getJson('/api/clients')->assertOk()->json('0');
        $this->assertSame(['id', 'name', 'nip', 'city', 'owner_id', 'tenders_count', 'owner'], array_keys($short));

        $full = $this->getJson('/api/clients?details=1')->assertOk()->json('0');
        $this->assertSame(['17 850 00 00', [['name' => 'Jan Kowalski']], ['biuro@acme.pl'], '5000.00', 10], [$full['phone'], $full['contacts'], $full['emails'], $full['sales_net'], $full['xl_gid']]);
    }

    private function d(string $date): int
    {
        return ClarionDate::fromDate(CarbonImmutable::parse($date));
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('cl-'.Str::random(6), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
