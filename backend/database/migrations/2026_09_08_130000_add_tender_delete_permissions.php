<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $deleteItems = Permission::findOrCreate('tenders.delete_items', 'web');
        $deleteTender = Permission::findOrCreate('tenders.delete', 'web');

        foreach (['admin', 'dyrektor'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($deleteItems);
        }

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        $admin?->givePermissionTo($deleteTender);
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['tenders.delete_items', 'tenders.delete'] as $name) {
            $permission = Permission::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->first();
            if ($permission === null) {
                continue;
            }
            foreach (['admin', 'dyrektor'] as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                $role?->revokePermissionTo($permission);
            }
            $permission->delete();
        }
    }
};
