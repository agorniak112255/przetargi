<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Uprawnienie products.review (08.10.2026): opisy sprawdzają po fakcie handlowcy — w katalogu dla handlowca, przetargów,
 * kierownika i admina; migracja nadaje je tym rolom i każdej roli z importem cenników.
 */
final class ProductsReviewPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_catalog_and_default_roles(): void
    {
        $this->assertContains('products.review', PermissionCatalog::ALL);
        $this->assertSame('Produkty i cenniki', PermissionCatalog::definitions()['products.review']['group']);
        $roles = PermissionCatalog::rolePermissions();
        foreach (['handlowiec', 'przetargi', 'kierownik', 'admin'] as $role) {
            $this->assertContains('products.review', $roles[$role], $role);
        }
        $this->assertNotContains('products.review', $roles['dyrektor']);
    }

    public function test_migration_grants_permission_to_listed_roles_and_price_list_importers(): void
    {
        Permission::findByName('products.review', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('importer_cennikow', 'web')->givePermissionTo('price_lists.import');
        Role::findOrCreate('tylko_podglad', 'web')->givePermissionTo('products.view');

        $migration = require database_path('migrations/2026_10_08_100300_add_products_review_permission.php');
        $migration->up();

        $with = Role::query()->with('permissions')->get()
            ->filter(fn (Role $role): bool => $role->permissions->contains('name', 'products.review'))
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['admin', 'handlowiec', 'importer_cennikow', 'kierownik', 'przetargi'], $with);

        $migration->down();
        $this->assertNull(Permission::query()->where('name', 'products.review')->first());
    }
}
