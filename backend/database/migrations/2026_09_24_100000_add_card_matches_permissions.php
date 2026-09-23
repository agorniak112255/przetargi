<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ekran „Łączenie kart” z własnymi uprawnieniami (decyzja użytkownika 24.09.2026) zamiast products.view /
 * products.delete — podgląd i decyzje nadawane rolom w Administracja → Role. Na start tylko administrator.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['card_matches.view', 'card_matches.decide'];

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
