<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Kampanie: każdy sprzedający robi własne kampanie (campaigns.use), administrator widzi wszystkie i prowadzi wspólne
 * grupy oraz listę wypisanych (campaigns.manage). Decyzja właściciela 30.09.2026.
 */
return new class extends Migration
{
    private const GRANTS = [
        'campaigns.use' => ['handlowiec', 'przetargi', 'kierownik', 'dyrektor', 'admin'],
        'campaigns.manage' => ['admin'],
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (Role::query()->whereIn('name', $roles)->where('guard_name', 'web')->get() as $role) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (array_keys(self::GRANTS) as $name) {
            $permission = Permission::query()->where('name', $name)->where('guard_name', 'web')->first();
            if ($permission === null) {
                continue;
            }
            foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
                if ($role->hasPermissionTo($permission)) {
                    $role->revokePermissionTo($permission);
                }
            }
            $permission->delete();
        }
    }
};
