<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Raport zapasów dla zarządu: osobne uprawnienie, żeby zarząd widział tylko raport (bez list Zapasów). Na start
 * administrator; rolę dla zarządu nadaje się w ustawieniach ról.
 */
return new class extends Migration
{
    private const PERMISSION = 'inventory.report.view';

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');
        Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->first();
        if ($permission === null) {
            return;
        }
        Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()?->revokePermissionTo($permission);
        $permission->delete();
    }
};
