<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_returns_roles_and_permissions(): void
    {
        $user = User::factory()->withRole('admin')->create([
            'email' => 'admin@test.local',
            'password' => 'password',
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonFragment(['admin.access'])
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'role', 'roles', 'permissions'],
            ]);
    }

    public function test_handlowiec_cannot_access_ai_settings(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/ai-settings')->assertForbidden();
    }

    public function test_handlowiec_cannot_approve_substitutes(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $main = Product::query()->create([
            'sku' => 'MAIN-1',
            'name' => 'Main',
            'manufacturer' => 'X',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        $sub = Product::query()->create([
            'sku' => 'SUB-1',
            'name' => 'Sub',
            'manufacturer' => 'X',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 1,
        ]);
        $substitute = ProductSubstitute::query()->create([
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'tanszy',
            'match_percent' => 80,
            'approval_status' => 'oczekuje',
        ]);

        $this->patchJson("/api/substitutes/{$substitute->id}/approve", [
            'approval_status' => 'zatwierdzony',
        ])->assertForbidden();
    }

    public function test_kierownik_can_approve_substitutes(): void
    {
        Sanctum::actingAs(User::factory()->withRole('kierownik')->create());

        $main = Product::query()->create([
            'sku' => 'MAIN-2',
            'name' => 'Main',
            'manufacturer' => 'X',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        $sub = Product::query()->create([
            'sku' => 'SUB-2',
            'name' => 'Sub',
            'manufacturer' => 'X',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
            'stock' => 1,
        ]);
        $substitute = ProductSubstitute::query()->create([
            'main_product_id' => $main->id,
            'substitute_product_id' => $sub->id,
            'type' => 'tanszy',
            'match_percent' => 80,
            'approval_status' => 'oczekuje',
        ]);

        $this->patchJson("/api/substitutes/{$substitute->id}/approve", [
            'approval_status' => 'zatwierdzony',
        ])->assertOk()
            ->assertJsonPath('approval_status', 'zatwierdzony');
    }

    public function test_handlowiec_cannot_transition_to_zatwierdzona(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $client = Client::query()->create(['name' => 'Klient Test']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/2026/9999',
            'title' => 'Test',
            'client_id' => $client->id,
            'owner_id' => $user->id,
            'status' => 'akceptacja_dyrektor',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);

        $this->postJson("/api/tenders/{$tender->id}/transition", [
            'status' => 'zatwierdzona',
        ])->assertStatus(422);
    }

    public function test_admin_can_manage_users_and_roles(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/admin/users')->assertOk();
        $this->getJson('/api/admin/roles')
            ->assertOk()
            ->assertJsonStructure(['roles', 'all_permissions']);

        $created = $this->postJson('/api/admin/users', [
            'name' => 'Nowy',
            'email' => 'nowy@test.local',
            'password' => 'password123',
            'role' => 'handlowiec',
        ])->assertCreated()
            ->assertJsonPath('role', 'handlowiec');

        $id = $created->json('id');

        $this->patchJson("/api/admin/users/{$id}", [
            'role' => 'kierownik',
        ])->assertOk()
            ->assertJsonPath('role', 'kierownik');

        $this->putJson('/api/admin/roles/handlowiec', [
            'permissions' => ['dashboard.view', 'products.view'],
        ])->assertOk()
            ->assertJsonPath('permissions', ['dashboard.view', 'products.view']);

        $this->postJson('/api/admin/roles', [
            'name' => 'handel-krakow',
            'display_name' => 'Handel Kraków',
            'copy_from' => 'handlowiec',
        ])->assertCreated()
            ->assertJsonPath('name', 'handel-krakow')
            ->assertJsonPath('label', 'Handel Kraków');

        $this->postJson('/api/admin/users', [
            'name' => 'Krakowczyk',
            'email' => 'krakow@test.local',
            'password' => 'password123',
            'role' => 'handel-krakow',
        ])->assertCreated()
            ->assertJsonPath('role', 'handel-krakow');
    }

    public function test_handlowiec_cannot_access_admin(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/admin/users')->assertForbidden();
    }
}
