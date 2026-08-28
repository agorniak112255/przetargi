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

        $permission = Permission::findOrCreate('inquiries.use', 'web');

        foreach (['admin', 'handlowiec', 'przetargi', 'kierownik', 'dyrektor'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', 'inquiries.use')
            ->where('guard_name', 'web')
            ->first();

        if ($permission === null) {
            return;
        }

        foreach (['admin', 'handlowiec', 'przetargi', 'kierownik', 'dyrektor'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->revokePermissionTo($permission);
        }

        $permission->delete();
    }
};
