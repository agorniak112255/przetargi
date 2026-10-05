<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ogłoszenia z Biuletynu (notices.view): zakładka „Ogłoszenia” dostaje własne uprawnienie w edycji ról (05.10.2026).
 * Dotąd wchodziło się do niej z tenders.create albo tenders.view_all — na start uprawnienie dostaje każda rola, która
 * ma jedno z nich (także role dopisane w panelu), więc nikt nie traci ani nie zyskuje dostępu.
 */
return new class extends Migration
{
    private const NAME = 'notices.view';

    private const PREVIOUS = ['tenders.create', 'tenders.view_all'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permission = Permission::findOrCreate(self::NAME, 'web');
        $previous = Permission::query()->whereIn('name', self::PREVIOUS)->where('guard_name', 'web')->pluck('name')->all();
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            $hadAccess = $role->name === 'admin';
            foreach ($previous as $name) {
                $hadAccess = $hadAccess || $role->hasPermissionTo($name);
            }
            if ($hadAccess && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
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
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
