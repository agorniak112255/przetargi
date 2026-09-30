<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErpCustomer;
use App\Models\User;
use App\Services\Erp\ErpCustomerSync;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Operatorzy ERP XL w panelu admina i powiązanie użytkownika z operatorem (users.erp_operator_ident). */
final class ErpOperatorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_lists_main_operators_with_names_counts_and_assigned_user(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->customer(1, 'TAIZ');
        $this->customer(2, 'TAIZ');
        $this->customer(3, 'NOMA');
        $this->customer(4, 'ABCD');
        // zniknięty z XL i bez operatora — nie liczą się
        $this->customer(5, 'NOMA', removed: true);
        $this->customer(6, null);
        Cache::forever(ErpCustomerSync::OPERATORS_CACHE_KEY, ['TAIZ' => 'Izabela Tarsała', 'NOMA' => 'Norbert Mazur']);
        $seller = User::factory()->withRole('handlowiec')->create(['name' => 'Iza']);
        $seller->forceFill(['erp_operator_ident' => 'taiz '])->save();

        $this->getJson('/api/admin/erp-operators')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['ident' => 'TAIZ', 'name' => 'Izabela Tarsała', 'customers' => 2, 'user' => ['id' => $seller->id, 'name' => 'Iza']],
                ['ident' => 'ABCD', 'name' => null, 'customers' => 1, 'user' => null],
                ['ident' => 'NOMA', 'name' => 'Norbert Mazur', 'customers' => 1, 'user' => null],
            ]]);
    }

    public function test_lists_operator_assigned_to_user_without_customers(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->customer(1, 'TAIZ');
        Cache::forever(ErpCustomerSync::OPERATORS_CACHE_KEY, ['NOWY' => 'Nowy Handlowiec']);
        // nowy handlowiec: operator przypisany, ale jeszcze nie jest głównym operatorem żadnego klienta
        $seller = User::factory()->withRole('handlowiec')->create(['name' => 'Nowy']);
        $seller->forceFill(['erp_operator_ident' => 'nowy'])->save();

        $this->getJson('/api/admin/erp-operators')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['ident' => 'TAIZ', 'name' => null, 'customers' => 1, 'user' => null],
                ['ident' => 'NOWY', 'name' => 'Nowy Handlowiec', 'customers' => 0, 'user' => ['id' => $seller->id, 'name' => 'Nowy']],
            ]]);
    }

    public function test_operators_list_requires_user_management(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/admin/erp-operators')->assertForbidden();
    }

    public function test_admin_sets_and_clears_operator_of_user(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $user = User::factory()->withRole('handlowiec')->create();

        $this->patchJson("/api/admin/users/{$user->id}", ['erp_operator_ident' => ' taiz '])
            ->assertOk()
            ->assertJsonPath('erp_operator_ident', 'TAIZ');
        $this->assertSame('TAIZ', $user->fresh()->erp_operator_ident);

        $listed = collect($this->getJson('/api/admin/users')->assertOk()->json())->firstWhere('id', $user->id);
        $this->assertSame('TAIZ', $listed['erp_operator_ident']);

        // zmiana innych pól bez klucza nie rusza operatora
        $this->patchJson("/api/admin/users/{$user->id}", ['name' => 'Iza Tarsała'])
            ->assertOk()
            ->assertJsonPath('erp_operator_ident', 'TAIZ');

        $this->patchJson("/api/admin/users/{$user->id}", ['erp_operator_ident' => str_repeat('A', 21)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('erp_operator_ident');
        $this->assertSame('TAIZ', $user->fresh()->erp_operator_ident);

        $this->patchJson("/api/admin/users/{$user->id}", ['erp_operator_ident' => '   '])
            ->assertOk()
            ->assertJsonPath('erp_operator_ident', null);
        $this->assertNull($user->fresh()->erp_operator_ident);

        $this->patchJson("/api/admin/users/{$user->id}", ['erp_operator_ident' => 'NOMA']);
        $this->patchJson("/api/admin/users/{$user->id}", ['erp_operator_ident' => null])
            ->assertOk()
            ->assertJsonPath('erp_operator_ident', null);
    }

    private function customer(int $gid, ?string $operator, bool $removed = false): void
    {
        ErpCustomer::query()->create([
            'xl_gid' => $gid,
            'acronym' => 'K'.$gid,
            'main_operator' => $operator,
            'removed_at' => $removed ? now() : null,
        ]);
    }
}
