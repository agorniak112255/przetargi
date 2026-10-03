<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Stan systemu (admin.system.view): ekran z przebiegami zadań nocnych, kontami dostawców, kolejką analiz zapytań,
 * alertami i brakami w danych oraz e-mail przy błędzie zadania nocnego albo konta dostawcy.
 * Na start tylko administrator; innym rolom nadaje się w edycji ról (03.10.2026).
 */
return new class extends Migration
{
    private const NAME = 'admin.system.view';

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
