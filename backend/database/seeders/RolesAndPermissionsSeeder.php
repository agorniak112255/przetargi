<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (PermissionCatalog::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $labels = PermissionCatalog::roleLabels();

        foreach (PermissionCatalog::rolePermissions() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->display_name = $labels[$roleName] ?? $roleName;
            $role->save();
            $role->syncPermissions($permissions);
        }

        $knownRoles = Role::query()->where('guard_name', 'web')->pluck('name')->all();

        User::query()->whereNotNull('role')->each(function (User $user) use ($knownRoles): void {
            if (! in_array($user->role, $knownRoles, true)) {
                return;
            }

            $user->syncRoles([$user->role]);
        });
    }
}
