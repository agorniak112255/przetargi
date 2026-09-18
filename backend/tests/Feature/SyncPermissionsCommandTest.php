<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `permissions:sync` — dosypywanie uprawnień, które doszły w kodzie.
 *
 * Skąd to się wzięło: wdrożenie na serwer robi tylko migracje, a seeder rol
 * (z `syncPermissions`) skasowałby ręczne zmiany w panelu „Role”. Nowe
 * uprawnienie istniało więc w kodzie, ale na serwerze nie miał go nikt —
 * także admin. To polecenie dosypuje wyłącznie nowe i nie odbiera niczego.
 */
final class SyncPermissionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function forget(string $permission): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', $permission)->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_new_permission_lands_on_the_roles_from_the_catalog(): void
    {
        // Stan „uprawnienie dopisane w kodzie, w bazie jeszcze go nie ma”.
        $this->forget('inquiries.view_all');
        // hasPermissionTo rzuca wyjątkiem, gdy uprawnienia nie ma w bazie —
        // sprawdzamy więc to, co rola faktycznie ma przypisane.
        $this->assertNotContains(
            'inquiries.view_all',
            Role::findByName('admin', 'web')->permissions->pluck('name')->all(),
        );

        $this->artisan('permissions:sync', ['--apply' => true])->assertExitCode(0);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Admin domyślnie widzi wszystkie zapytania — tak stoi w katalogu.
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('inquiries.view_all'));
        $this->assertTrue(Role::findByName('handlowiec', 'web')->hasPermissionTo('inquiries.view_all'));
    }

    public function test_preview_changes_nothing(): void
    {
        $this->forget('inquiries.view_all');

        $this->artisan('permissions:sync')->assertExitCode(0);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->assertFalse(Permission::query()->where('name', 'inquiries.view_all')->exists());
    }

    public function test_manual_change_in_the_roles_panel_survives(): void
    {
        // Kierownik ma odebrane uprawnienie decyzją człowieka…
        $kierownik = Role::findByName('kierownik', 'web');
        $kierownik->revokePermissionTo('inquiries.view_all');
        // …a w kodzie doszło zupełnie inne, nowe uprawnienie.
        $this->forget('products.delete');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->artisan('permissions:sync', ['--apply' => true])->assertExitCode(0);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Nowe wchodzi tam, gdzie ma, ale odebrane zostaje odebrane.
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('products.delete'));
        $this->assertFalse(Role::findByName('kierownik', 'web')->hasPermissionTo('inquiries.view_all'));
    }

    public function test_second_run_has_nothing_to_do(): void
    {
        $this->artisan('permissions:sync', ['--apply' => true])
            ->expectsOutputToContain('nie ma czego dosypywać')
            ->assertExitCode(0);
    }

    public function test_permission_to_see_all_inquiries_is_offered_in_the_roles_editor(): void
    {
        // Uprawnienie musi mieć opis w katalogu, inaczej nie da się go włączyć
        // w panelu „Role” — tam widać tylko to, co ma definicję.
        $keys = array_column(PermissionCatalog::definitionsList(), 'key');
        $this->assertContains('inquiries.view_all', $keys);

        $definition = collect(PermissionCatalog::definitionsList())
            ->firstWhere('key', 'inquiries.view_all');
        $this->assertSame('Zapytania', $definition['group']);

        $admin = User::factory()->withRole('admin')->create();
        $this->assertTrue($admin->can('inquiries.view_all'));
    }
}
