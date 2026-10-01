<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Podgląd kampanii (campaigns.view): cudze kampanie po starcie wysyłki tylko do odczytu, test tylko na własny adres.
 * Na start tylko administrator; innym rolom nadaje się w edycji ról (01.10.2026).
 */
return new class extends Migration
{
    private const NAME = 'campaigns.view';

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permission = Permission::findOrCreate(self::NAME, 'web');
        Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permission = Permission::query()->where('name', self::NAME)->where('guard_name', 'web')->first();
        if ($permission === null) {
            return;
        }
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if ($role->hasPermissionTo($permission)) {
                $role->revokePermissionTo($permission);
            }
        }
        $permission->delete();
    }
};
