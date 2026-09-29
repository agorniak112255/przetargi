<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ekran „Powiązania z ERP XL” (Administracja): podgląd towarów XL z ich powiązaniami i decyzje (potwierdź, odrzuć,
 * wybierz kartę). Ekran pokazuje ceny zakupu i dostawców z XL. Na start tylko administrator.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['admin.erp_links.view', 'admin.erp_links.manage'];

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
