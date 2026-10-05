<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProcurementNotice;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Zakładka „Ogłoszenia” ma własne uprawnienie notices.view (05.10.2026): bez niego tenders.create ani
 * tenders.view_all nie otwierają ogłoszeń, a założenie przetargu z ogłoszenia wymaga obu uprawnień.
 * Migracja nadaje je rolom, które dotąd widziały zakładkę.
 */
final class NoticesViewPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_catalog_and_default_roles(): void
    {
        $this->assertContains('notices.view', PermissionCatalog::ALL);
        $this->assertSame('Przetargi', PermissionCatalog::definitions()['notices.view']['group']);
        foreach (PermissionCatalog::ROLES as $role) {
            $this->assertContains('notices.view', PermissionCatalog::rolePermissions()[$role], $role);
        }
    }

    public function test_tender_permissions_without_notices_view_do_not_open_notices(): void
    {
        $notice = $this->notice();
        Role::findByName('handlowiec', 'web')->revokePermissionTo('notices.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $this->getJson('/api/notices')->assertForbidden();
        $this->getJson("/api/notices/{$notice->id}")->assertForbidden();
        $this->getJson("/api/notices/{$notice->id}/items")->assertForbidden();
        $this->postJson("/api/notices/{$notice->id}/skip")->assertForbidden();
        $this->deleteJson("/api/notices/{$notice->id}/skip")->assertForbidden();
        $this->postJson("/api/notices/{$notice->id}/tender")->assertForbidden();
        $this->assertDatabaseCount('tenders', 0);
    }

    public function test_notices_view_alone_opens_list_but_not_tender_creation(): void
    {
        $notice = $this->notice();
        Role::findOrCreate('tylko_ogloszenia', 'web')->givePermissionTo('notices.view');

        Sanctum::actingAs(User::factory()->withRole('tylko_ogloszenia')->create());
        $this->getJson('/api/notices')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/notices/{$notice->id}/skip")->assertOk();
        $this->deleteJson("/api/notices/{$notice->id}/skip")->assertOk();
        $this->postJson("/api/notices/{$notice->id}/tender")->assertForbidden();
        $this->assertDatabaseCount('tenders', 0);
    }

    public function test_migration_grants_permission_to_roles_that_had_access(): void
    {
        Permission::findByName('notices.view', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('tylko_tworzenie', 'web')->givePermissionTo('tenders.create');
        Role::findOrCreate('tylko_wglad', 'web')->givePermissionTo('tenders.view_all');
        Role::findOrCreate('bez_przetargow', 'web')->givePermissionTo('products.view');

        $migration = require database_path('migrations/2026_10_05_210000_add_notices_view_permission.php');
        $migration->up();

        $with = Role::query()->with('permissions')->get()
            ->filter(fn (Role $role): bool => $role->permissions->contains('name', 'notices.view'))
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(
            ['admin', 'dyrektor', 'handlowiec', 'kierownik', 'przetargi', 'tylko_tworzenie', 'tylko_wglad'],
            $with,
        );

        $migration->down();
        $this->assertNull(Permission::query()->where('name', 'notices.view')->first());
    }

    private function notice(): ProcurementNotice
    {
        return ProcurementNotice::query()->create([
            'source' => 'bzp',
            'notice_type' => ProcurementNotice::TYPE_CONTRACT,
            'notice_number' => '2026/BZP 00500001/01',
            'bzp_number' => '2026/BZP 00500001',
            'object_id' => 'obj-1',
            'published_at' => now()->subDay(),
            'submitting_offers_at' => now()->addDays(10),
            'order_object' => 'Dostawa rękawic',
            'cpv_codes' => [['code' => '18141000-9', 'name' => 'Rękawice robocze']],
            'organization_name' => 'Gmina Testowa',
            'organization_city' => 'Rzeszów',
            'organization_province' => 'PL18',
            'organization_nip' => null,
            'parsed' => ['has_lots' => false, 'lots' => []],
            'parser_version' => 1,
            'fetched_at' => now()->subHours(2),
        ]);
    }
}
