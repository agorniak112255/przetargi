<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ekran „Statystyki AI” (koszt i przebieg wyszukiwań AI) jako osobne uprawnienie — listy odstających pozycji
 * pokazują treść zapytań, także z maili klientów. Domyślnie tylko administrator; innym rolom nadaje się je
 * w edycji ról.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['admin.ai_stats.view'];

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
