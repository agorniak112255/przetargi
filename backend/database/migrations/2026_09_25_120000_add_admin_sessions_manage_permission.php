<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Wylogowanie sesji nieużywanych od 30 dni (ekran „Aktywne sesje”) jako osobne uprawnienie — sam podgląd
 * sesji nikogo nie wylogowuje. Domyślnie tylko administrator; pozostałym rolom nadaje się je w edycji ról.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['admin.sessions.manage'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            $admin?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::query()->where('name', $name)->where('guard_name', 'web')->first();
            if ($permission === null) {
                continue;
            }
            $admin?->revokePermissionTo($permission);
            $permission->delete();
        }
    }
};
