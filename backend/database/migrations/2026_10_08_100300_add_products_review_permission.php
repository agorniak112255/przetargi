<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Przegląd opisów kart (products.review, 08.10.2026): lista „Do przeglądu” w Cennikach i decyzje zatwierdź / odrzuć /
 * podaj adres / przywróć wersję. Automat zapisuje opis zawsze, a sprawdzają go po fakcie handlowcy (decyzja właściciela
 * 07.10.2026) — uprawnienie dostają role handlowiec, przetargi, kierownik, admin i każda rola z importem cenników
 * (także role dopisane w panelu), bo ta dotąd pobierała opisy.
 */
return new class extends Migration
{
    private const NAME = 'products.review';

    private const ROLES = ['admin', 'handlowiec', 'przetargi', 'kierownik'];

    private const PREVIOUS = 'price_lists.import';

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permission = Permission::findOrCreate(self::NAME, 'web');
        $previous = Permission::query()->where('name', self::PREVIOUS)->where('guard_name', 'web')->exists();
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            $grant = in_array($role->name, self::ROLES, true) || ($previous && $role->hasPermissionTo(self::PREVIOUS));
            if ($grant && ! $role->hasPermissionTo($permission)) {
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
