<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pracownicy ERP XL (opiekunowie klientów) w Administracji → Użytkownicy: GET /api/admin/erp-employees
 * i przypisanie do konta przez PATCH /api/admin/users/{user} (erp_employee_gid).
 */
final class ErpEmployeeApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole('admin')->create(['email' => 'admin@example.com']);
    }

    public function test_only_users_manager_sees_the_list(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/admin/erp-employees')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/erp-employees')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_list_from_clients_with_counts_assignment_and_email_suggestion(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create(['name' => 'Anna Nowak', 'email' => 'Anna.Nowak@supon.pl']);
        $piotr = User::factory()->withRole('handlowiec')->create(['name' => 'Piotr Wiśniewski', 'email' => 'piotr@supon.pl']);
        $piotr->forceFill(['erp_employee_gid' => 502])->save();
        $marek = User::factory()->withRole('handlowiec')->create(['name' => 'Marek', 'email' => 'marek@supon.pl']);
        $marek->forceFill(['erp_employee_gid' => 777])->save(); // przypisany pracownik bez klientów

        $this->client('A', 501, 'Anna Nowak', 'anna.nowak@supon.pl');
        $this->client('B', 501, 'Anna Nowak', 'anna.nowak@supon.pl');
        $this->client('C', 501, 'Anna Kowalska-Nowak', 'anna.nowak@supon.pl');
        // pracownik Piotra — e-mail w XL ten sam co konto Marka, ale już przypisany: bez podpowiedzi
        $this->client('D', 502, 'Piotr Wiśniewski', 'marek@supon.pl');
        // e-mail Marka, ale Marek ma już innego pracownika — bez podpowiedzi
        $this->client('E', 503, 'Ktoś', 'marek@supon.pl');
        // bez nazwiska i e-maila w XL
        $this->client('F', 504, null, null);
        Client::query()->create(['name' => 'Bez opiekuna']);

        Sanctum::actingAs($this->admin);
        $data = collect($this->getJson('/api/admin/erp-employees')->assertOk()->json('data'))->keyBy('gid');

        $this->assertSame([501, 502, 503, 504, 777], $data->keys()->sort()->values()->all());
        $this->assertSame(501, $this->getJson('/api/admin/erp-employees')->json('data.0.gid'), 'Najwięcej klientów pierwszy.');
        $this->assertSame([
            'gid' => 501,
            'name' => 'Anna Nowak',
            'email' => 'anna.nowak@supon.pl',
            'clients' => 3,
            'user' => null,
            'suggested_user' => ['id' => $anna->id, 'name' => 'Anna Nowak'],
        ], $data[501]);
        $this->assertSame(['id' => $piotr->id, 'name' => 'Piotr Wiśniewski'], $data[502]['user']);
        $this->assertNull($data[502]['suggested_user']);
        $this->assertNull($data[503]['suggested_user']);
        $this->assertSame(['gid' => 504, 'name' => null, 'email' => null, 'clients' => 1, 'user' => null, 'suggested_user' => null], $data[504]);
        $this->assertSame(0, $data[777]['clients']);
        $this->assertSame(['id' => $marek->id, 'name' => 'Marek'], $data[777]['user']);
        // podpowiedź niczego nie przypisuje
        $this->assertNull($anna->fresh()->erp_employee_gid);
    }

    public function test_admin_assigns_clears_and_cannot_take_an_employee_twice(): void
    {
        $anna = User::factory()->withRole('handlowiec')->create();
        $piotr = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/admin/users/{$anna->id}", ['erp_employee_gid' => 501])
            ->assertOk()
            ->assertJsonPath('erp_employee_gid', 501);
        $this->assertSame(501, $anna->fresh()->erp_employee_gid);

        // ten sam pracownik dla drugiego konta — 422, nic się nie zmienia
        $this->patchJson("/api/admin/users/{$piotr->id}", ['erp_employee_gid' => 501, 'name' => 'Inne imię'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('erp_employee_gid');
        $this->assertNull($piotr->fresh()->erp_employee_gid);
        $this->assertNotSame('Inne imię', $piotr->fresh()->name);

        // zapis bez tego klucza nie zdejmuje przypisania; ponowny zapis tego samego numeru u tej samej osoby — w porządku
        $this->patchJson("/api/admin/users/{$anna->id}", ['name' => 'Anna Nowak'])->assertOk()->assertJsonPath('erp_employee_gid', 501);
        $this->patchJson("/api/admin/users/{$anna->id}", ['erp_employee_gid' => 501])->assertOk();

        $this->patchJson("/api/admin/users/{$anna->id}", ['erp_employee_gid' => 'abc'])->assertUnprocessable();
        $this->patchJson("/api/admin/users/{$anna->id}", ['erp_employee_gid' => 0])->assertUnprocessable();

        // null zdejmuje — numer wolny dla innego konta
        $this->patchJson("/api/admin/users/{$anna->id}", ['erp_employee_gid' => null])->assertOk()->assertJsonPath('erp_employee_gid', null);
        $this->patchJson("/api/admin/users/{$piotr->id}", ['erp_employee_gid' => 501])->assertOk();

        $listed = collect($this->getJson('/api/admin/users')->assertOk()->json())->keyBy('id');
        $this->assertSame(501, $listed[$piotr->id]['erp_employee_gid']);
        $this->assertNull($listed[$anna->id]['erp_employee_gid']);
    }

    private function client(string $name, int $gid, ?string $manager, ?string $email): void
    {
        $client = Client::query()->create([
            'name' => $name,
            'xl_manager_gid' => $gid,
            'account_manager' => $manager,
            'account_manager_email' => $email,
        ]);
        $client->forceFill(['xl_gid' => 9000 + $client->id])->save();
    }
}
