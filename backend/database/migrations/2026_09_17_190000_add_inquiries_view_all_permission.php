<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Podgląd zapytań mailowych wszystkich pracowników, nie tylko własnych.
 *
 * Dostają go kierownik i dyrektor; admin ma komplet uprawnień z katalogu.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $roles = ['admin', 'kierownik', 'dyrektor'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::findOrCreate('inquiries.view_all', 'web');

        foreach ($this->roles as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', 'inquiries.view_all')
            ->where('guard_name', 'web')
            ->first();

        if ($permission === null) {
            return;
        }

        foreach ($this->roles as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->revokePermissionTo($permission);
        }

        $permission->delete();
    }
};
