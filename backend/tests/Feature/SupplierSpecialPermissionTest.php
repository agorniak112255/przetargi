<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Services\Pricing\SupplierSpecialMask;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Uprawnienie prices.supplier_special.view: w katalogu tylko admin (ALL) i dyrektor; migracja nadaje je tym dwóm
 * rolom na działającej instalacji, bez ruszania pozostałych.
 */
final class SupplierSpecialPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_30_210000_add_supplier_special_view_permission.php';

    public function test_catalog_grants_permission_only_to_admin_and_dyrektor(): void
    {
        $this->assertSame('prices.supplier_special.view', SupplierSpecialMask::PERMISSION);
        $this->assertContains(SupplierSpecialMask::PERMISSION, PermissionCatalog::ALL);
        $roles = PermissionCatalog::rolePermissions();
        $this->assertContains(SupplierSpecialMask::PERMISSION, $roles['admin']);
        $this->assertContains(SupplierSpecialMask::PERMISSION, $roles['dyrektor']);
        foreach (['handlowiec', 'przetargi', 'kierownik'] as $role) {
            $this->assertNotContains(SupplierSpecialMask::PERMISSION, $roles[$role], $role);
        }

        $definition = PermissionCatalog::definitions()[SupplierSpecialMask::PERMISSION];
        $this->assertSame('Ceny specjalne B2B — podgląd', $definition['label']);
        $this->assertSame('Produkty i cenniki', $definition['group']);
        $this->assertStringContainsString('cenę standardową', $definition['description']);
        $this->assertStringContainsString('Eksport do Presty', $definition['description']);
    }

    public function test_migration_grants_admin_and_dyrektor_and_down_removes_it(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        // stan sprzed migracji: role są, uprawnienia jeszcze nie ma
        $this->forgetPermission();
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->assertSame(['admin', 'dyrektor'], $this->rolesWithPermission());

        // drugi przebieg nic nie psuje
        $migration->up();
        $this->assertSame(['admin', 'dyrektor'], $this->rolesWithPermission());

        $migration->down();
        $this->assertFalse(Permission::query()->where('name', SupplierSpecialMask::PERMISSION)->exists());
    }

    public function test_migration_without_roles_only_creates_permission(): void
    {
        $this->forgetPermission();
        $migration = require database_path(self::MIGRATION);

        $migration->up();

        $this->assertTrue(Permission::query()->where('name', SupplierSpecialMask::PERMISSION)->exists());
        $this->assertSame([], $this->rolesWithPermission());
    }

    public function test_seeded_roles_and_permissions_sync_preview(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertSame(['admin', 'dyrektor'], $this->rolesWithPermission());

        // baza zna uprawnienie — podgląd nie ma czego dosypywać
        $this->artisan('permissions:sync')
            ->expectsOutputToContain('nie ma czego dosypywać')
            ->assertExitCode(0);

        // instalacja bez uprawnienia: podgląd pokazuje je przy adminie i dyrektorze, nic nie zapisuje
        $this->forgetPermission();
        $this->artisan('permissions:sync')
            ->expectsOutputToContain('Nowe uprawnienia: '.SupplierSpecialMask::PERMISSION)
            ->expectsOutputToContain('dyrektor ← '.SupplierSpecialMask::PERMISSION)
            ->assertExitCode(0);
        $this->assertFalse(Permission::query()->where('name', SupplierSpecialMask::PERMISSION)->exists());
    }

    private function forgetPermission(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', SupplierSpecialMask::PERMISSION)->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function rolesWithPermission(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->whereHas('permissions', static fn ($q) => $q->where('name', SupplierSpecialMask::PERMISSION))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
