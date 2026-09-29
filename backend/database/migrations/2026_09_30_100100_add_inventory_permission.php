<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Zakładka „Zapasy”: towary z ERP XL ze stanem bez sprzedaży od N miesięcy, z wartością księgową zapasu.
 * Kwoty zamrożonego towaru — na start tylko administrator (decyzja użytkownika 29.09.2026).
 */
return new class extends Migration
{
    private const PERMISSION = 'inventory.view';

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
