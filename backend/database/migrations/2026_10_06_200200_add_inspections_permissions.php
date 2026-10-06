<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Moduł „Przeglądy” (06.10.2026): inspections.view (lista terminów, raport, eksport), inspections.manage (pozycje
 * i interwały, podpowiedzi), inspections.offer (oferty przeglądu, pomijanie klientów). Na start tylko administrator;
 * innym rolom nadaje właściciel w edycji ról.
 */
return new class extends Migration
{
    private const NAMES = ['inspections.view', 'inspections.manage', 'inspections.offer'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        foreach (self::NAMES as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            $admin?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (self::NAMES as $name) {
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
