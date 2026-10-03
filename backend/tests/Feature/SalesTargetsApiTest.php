<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ErpSaleDocument;
use App\Models\SalesTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Raporty → „Cele handlowców” (GET /api/reports/targets, PUT /api/reports/targets/{RRRR-MM}).
 * Teraz = sobota 3.10.2026, 9:14 w Polsce — październik ma 22 dni robocze, minęły 2 (1 i 2 października).
 */
final class SalesTargetsApiTest extends TestCase
{
    use RefreshDatabase;

    private int $documentId = 1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-03 09:14:00', 'Europe/Warsaw'));
        $this->admin = User::factory()->withRole('admin')->create(['name' => 'Zarząd']);
    }

    public function test_needs_reports_view_and_targets_permission(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/reports/targets')->assertForbidden();
        $this->putJson('/api/reports/targets/2026-10', ['targets' => []])->assertForbidden();

        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());
        $this->getJson('/api/reports/targets')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/reports/targets')->assertOk()->assertJsonPath('month', '2026-10');
    }

    public function test_month_window_and_format(): void
    {
        Sanctum::actingAs($this->admin);

        // okno: następny miesiąc (cele z wyprzedzeniem), bieżący i 11 poprzednich
        $json = $this->getJson('/api/reports/targets?month=2026-09')->assertOk()->json();
        $this->assertCount(13, $json['months']);
        $this->assertSame(['key' => '2026-11', 'label' => 'Listopad 2026'], $json['months'][0]);
        $this->assertSame(['key' => '2026-10', 'label' => 'Październik 2026'], $json['months'][1]);
        $this->assertSame(['key' => '2025-11', 'label' => 'Listopad 2025'], $json['months'][12]);

        $this->getJson('/api/reports/targets?month=2025-10')->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->getJson('/api/reports/targets?month=2026-11')->assertOk();
        $this->getJson('/api/reports/targets?month=2026-12')->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->putJson('/api/reports/targets/2026-12', ['targets' => []])->assertUnprocessable();
        $this->getJson('/api/reports/targets?month=2026-13')->assertUnprocessable();
        $this->getJson('/api/reports/targets?month=pazdziernik')->assertUnprocessable();
        $this->putJson('/api/reports/targets/2025-10', ['targets' => []])->assertUnprocessable();
        $this->putJson('/api/reports/targets/2026-00', ['targets' => []])->assertUnprocessable();
    }

    public function test_put_sets_and_null_removes_only_sent_targets(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Nowak']);
        $piotr = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Wiśniewski']);
        Sanctum::actingAs($this->admin);

        $json = $this->putJson('/api/reports/targets/2026-10', ['targets' => [
            ['user_id' => $anna->id, 'amount' => 420000],
            ['user_id' => $piotr->id, 'amount' => '380000.50'],
        ]])->assertOk()->json();
        $rows = collect($json['rows'])->keyBy('user_id');
        $this->assertSame('420000.00', $rows[$anna->id]['target']);
        $this->assertSame('380000.50', $rows[$piotr->id]['target']);
        $this->assertSame($this->admin->id, SalesTarget::query()->where('user_id', $anna->id)->value('set_by'));
        $this->assertSame('800000.50', $json['total']['target']);

        // cel innego miesiąca jest osobny
        $this->putJson('/api/reports/targets/2026-09', ['targets' => [['user_id' => $anna->id, 'amount' => 100]]])->assertOk();

        // null usuwa cel Anny w październiku; cel Piotra (nie wysłany) zostaje
        $json = $this->putJson('/api/reports/targets/2026-10', ['targets' => [['user_id' => $anna->id, 'amount' => null]]])->assertOk()->json();
        $rows = collect($json['rows'])->keyBy('user_id');
        $this->assertNull($rows[$anna->id]['target']);
        $this->assertNull($rows[$anna->id]['percent']);
        $this->assertSame('380000.50', $rows[$piotr->id]['target']);
        $this->assertSame(2, SalesTarget::query()->count());
        $this->assertSame('100.00', (string) SalesTarget::query()->where('month', 9)->value('amount'));

        // zero, brak kwoty, nieznana osoba, dwa razy ta sama osoba — błędy, nic się nie zmienia
        $this->putJson('/api/reports/targets/2026-10', ['targets' => [['user_id' => $piotr->id, 'amount' => 0]]])->assertUnprocessable();
        $this->putJson('/api/reports/targets/2026-10', ['targets' => [['user_id' => $piotr->id]]])->assertUnprocessable();
        $this->putJson('/api/reports/targets/2026-10', ['targets' => [['user_id' => 999999, 'amount' => 5]]])->assertUnprocessable();
        $this->putJson('/api/reports/targets/2026-10', ['targets' => [
            ['user_id' => $piotr->id, 'amount' => 5],
            ['user_id' => $piotr->id, 'amount' => 6],
        ]])->assertUnprocessable();
        $this->putJson('/api/reports/targets/2026-10', [])->assertUnprocessable();
        $this->assertSame('380000.50', (string) SalesTarget::query()->where('user_id', $piotr->id)->where('month', 10)->value('amount'));
    }

    public function test_sales_after_corrections_by_assignment_xl_then_app_then_unassigned(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Nowak']);
        $anna->forceFill(['erp_employee_gid' => 501])->save();
        $piotr = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Wiśniewski']);

        // pracownik XL Anny wygrywa z opiekunem w aplikacji
        $byXl = $this->client('Szpital', 501, $piotr->id);
        // pracownik XL bez konta — liczy się opiekun w aplikacji
        $byApp = $this->client('Gmina', 999, $piotr->id);
        $nobody = $this->client('Ciepłownia', 999, null);

        $this->doc($byXl, 2033, '2026-10-01', '1000.00');
        $this->doc($byXl, 2041, '2026-10-02', '-200.00');
        $this->doc($byXl, 2034, '2026-10-31', '50.25');
        $this->doc($byApp, 2037, '2026-10-02', '300.00');
        $this->doc($nobody, 2033, '2026-10-02', '700.00');
        // poza miesiącem i bez klienta z zakładki Klienci — nie liczą się
        $this->doc($byXl, 2033, '2026-09-30', '5000.00');
        $this->doc($byXl, 2033, '2026-11-01', '5000.00');
        $this->doc(null, 2033, '2026-10-02', '9999.00');

        SalesTarget::query()->create(['user_id' => $anna->id, 'year' => 2026, 'month' => 10, 'amount' => '1000.00']);

        Sanctum::actingAs($this->admin);
        $json = $this->getJson('/api/reports/targets?month=2026-10')->assertOk()->json();
        $rows = collect($json['rows'])->keyBy('user_id');

        $this->assertSame('850.25', $rows[$anna->id]['sales']);
        $this->assertSame(85.0, (float) $rows[$anna->id]['percent']);
        $this->assertSame(['xl' => '850.25', 'app' => '0.00'], $rows[$anna->id]['by_source']);
        $this->assertTrue($rows[$anna->id]['has_employee']);
        $this->assertSame(1, $rows[$anna->id]['clients_bought']);

        $this->assertSame('300.00', $rows[$piotr->id]['sales']);
        $this->assertSame(['xl' => '0.00', 'app' => '300.00'], $rows[$piotr->id]['by_source']);
        $this->assertNull($rows[$piotr->id]['target']);
        $this->assertNull($rows[$piotr->id]['percent']);
        $this->assertFalse($rows[$piotr->id]['has_employee']);

        $this->assertSame(['sales' => '700.00', 'clients_bought' => 1, 'new_clients' => 1], $json['unassigned']);
        // realizacja działu liczy tylko osoby z celem; suma sprzedaży — wszystkie osoby (bez „bez opiekuna”)
        $this->assertSame('1150.25', $json['total']['sales']);
        $this->assertSame('1000.00', $json['total']['target']);
        $this->assertSame(85.0, (float) $json['total']['percent']);
        $this->assertStringContainsString('zakładki Klienci', $json['rule']);
        $this->assertStringContainsString('dzisiejszego', $json['rule']);
        // administrator bez roli handlowca, bez celu i bez sprzedaży — nie ma go w tabeli
        $this->assertFalse($rows->has($this->admin->id));
    }

    public function test_assignment_follows_todays_mapping(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna']);
        $anna->forceFill(['erp_employee_gid' => 501])->save();
        $client = $this->client('Szpital', 501, null);
        $this->doc($client, 2033, '2026-09-10', '100.00');

        Sanctum::actingAs($this->admin);
        $rows = collect($this->getJson('/api/reports/targets?month=2026-09')->json('rows'))->keyBy('user_id');
        $this->assertSame('100.00', $rows[$anna->id]['sales']);

        // przypisanie zdjęte dziś — wrzesień też liczy się już bez opiekuna
        $anna->forceFill(['erp_employee_gid' => null])->save();
        $json = $this->getJson('/api/reports/targets?month=2026-09')->json();
        $this->assertSame('0.00', collect($json['rows'])->keyBy('user_id')[$anna->id]['sales']);
        $this->assertSame('100.00', $json['unassigned']['sales']);
    }

    public function test_bought_and_new_clients_within_24_months(): void
    {
        $owner = User::factory()->withRole('handlowiec')->create(['name' => 'Anna']);
        $returning = $this->client('Stały', null, $owner->id);
        $this->doc($returning, 2033, '2024-10-01', '10.00'); // dokładnie 24 miesiące przed październikiem — w oknie
        $this->doc($returning, 2033, '2026-10-02', '100.00');

        $lapsed = $this->client('Wraca po przerwie', null, $owner->id);
        $this->doc($lapsed, 2033, '2024-09-30', '10.00'); // dzień przed oknem 24 miesięcy
        $this->doc($lapsed, 2041, '2025-05-01', '-5.00'); // sama korekta to nie zakup
        $this->doc($lapsed, 2034, '2026-10-02', '100.00');

        $fresh = $this->client('Pierwszy raz', null, $owner->id);
        $this->doc($fresh, 2037, '2026-10-01', '100.00');

        // w miesiącu tylko korekta — nie „kupił”
        $onlyCorrection = $this->client('Tylko korekta', null, $owner->id);
        $this->doc($onlyCorrection, 2041, '2026-10-02', '-50.00');

        Sanctum::actingAs($this->admin);
        $row = collect($this->getJson('/api/reports/targets?month=2026-10')->json('rows'))->keyBy('user_id')[$owner->id];

        $this->assertSame(3, $row['clients_bought']);
        $this->assertSame(2, $row['new_clients']);
        $this->assertSame('250.00', $row['sales']);
    }

    public function test_current_month_in_progress_with_business_days_and_closed_month(): void
    {
        $this->doc($this->client('X', null, null), 2033, '2026-10-01', '1.00', '2026-10-03 05:41:00');

        Sanctum::actingAs($this->admin);
        $json = $this->getJson('/api/reports/targets')->assertOk()->json();
        $this->assertFalse($json['closed']);
        $this->assertSame(['total' => 22, 'elapsed' => 2], $json['workdays']);
        $this->assertNotNull($json['data_until']);
        $this->assertSame('2026-10-03T05:41:00', substr((string) $json['data_until'], 0, 19));

        $json = $this->getJson('/api/reports/targets?month=2026-09')->assertOk()->json();
        $this->assertTrue($json['closed']);
        $this->assertFalse($json['upcoming']);
        $this->assertNull($json['workdays']);
    }

    public function test_next_month_targets_set_in_advance_without_sales_or_workdays(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Nowak']);
        $client = $this->client('Szpital', null, $anna->id);
        // dokument z datą w listopadzie nie może dać „realizacji” przed początkiem miesiąca
        $this->doc($client, 2033, '2026-11-02', '500.00');
        $this->doc($client, 2033, '2026-10-02', '100.00');

        Sanctum::actingAs($this->admin);
        $json = $this->putJson('/api/reports/targets/2026-11', ['targets' => [['user_id' => $anna->id, 'amount' => 400000]]])
            ->assertOk()->json();

        $this->assertSame('2026-11', $json['month']);
        $this->assertFalse($json['closed']);
        $this->assertTrue($json['upcoming']);
        $this->assertNull($json['workdays']);
        $row = collect($json['rows'])->keyBy('user_id')[$anna->id];
        $this->assertSame('400000.00', $row['target']);
        $this->assertSame('0.00', $row['sales']);
        $this->assertNull($row['percent']);
        $this->assertSame(0, $row['clients_bought']);
        $this->assertSame(['sales' => '0.00', 'clients_bought' => 0, 'new_clients' => 0], $json['unassigned']);
        $this->assertSame(['target' => '400000.00', 'sales' => '0.00', 'percent' => null], $json['total']);
        $this->assertSame(11, (int) SalesTarget::query()->where('user_id', $anna->id)->where('year', 2026)->value('month'));

        // bieżący miesiąc bez zmian: sprzedaż liczona, dni robocze w toku, cel listopada go nie dotyczy
        $json = $this->getJson('/api/reports/targets?month=2026-10')->assertOk()->json();
        $this->assertFalse($json['upcoming']);
        $this->assertSame(['total' => 22, 'elapsed' => 2], $json['workdays']);
        $row = collect($json['rows'])->keyBy('user_id')[$anna->id];
        $this->assertSame('100.00', $row['sales']);
        $this->assertNull($row['target']);
    }

    public function test_rows_include_salespeople_and_mapped_employees_without_sales(): void
    {
        $seller = User::factory()->withRole('handlowiec')->create(['name' => 'Bez sprzedaży']);
        $mapped = User::factory()->withRole('kierownik')->create(['name' => 'Kierownik z klientami']);
        $mapped->forceFill(['erp_employee_gid' => 777])->save();
        $targetOnly = User::factory()->withRole('kierownik')->create(['name' => 'Kierownik z celem']);
        SalesTarget::query()->create(['user_id' => $targetOnly->id, 'year' => 2026, 'month' => 10, 'amount' => '10.00']);

        Sanctum::actingAs($this->admin);
        $rows = collect($this->getJson('/api/reports/targets')->json('rows'))->keyBy('user_id');

        $this->assertTrue($rows->has($seller->id));
        $this->assertTrue($rows->has($mapped->id));
        $this->assertTrue($rows->has($targetOnly->id));
        $this->assertSame(['sales' => '0.00', 'clients_bought' => 0, 'new_clients' => 0], array_intersect_key($rows[$seller->id], array_flip(['sales', 'clients_bought', 'new_clients'])));
        $this->assertSame(0.0, (float) $rows[$targetOnly->id]['percent']);
    }

    private function client(string $name, ?int $managerGid, ?int $ownerId): Client
    {
        $client = Client::query()->create(['name' => $name, 'xl_manager_gid' => $managerGid, 'owner_id' => $ownerId]);
        $client->forceFill(['xl_gid' => 10_000 + $client->id, 'source' => Client::SOURCE_ERP_XL])->save();

        return $client;
    }

    private function doc(?Client $client, int $type, string $date, string $net, string $syncedAt = '2026-10-03 03:41:00'): void
    {
        ErpSaleDocument::query()->create([
            'document_type' => $type,
            'document_id' => $this->documentId++,
            'document_number' => 'FS-'.$this->documentId.'/2026',
            'kind' => ErpSaleDocument::TYPE_KIND[$type],
            'issued_at' => $date,
            'customer_xl_gid' => $client?->xl_gid ?? 99_999,
            'client_id' => $client?->id,
            'net_value' => $net,
            'synced_at' => $syncedAt,
        ]);
    }
}
