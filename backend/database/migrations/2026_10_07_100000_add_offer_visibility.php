<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Widoczność ofert (decyzja właściciela 06.10.2026): oferta widoczna tylko dla autora, chyba że rola ma
 * offers.view_all (wszystkie oferty) albo offers.view_selected (oferty osób wybranych w ustawieniach roli —
 * roles.offer_visible_user_ids). Cudze oferty tylko do podglądu — wysyła i zmienia autor (mail z jego skrzynki).
 * Na start uprawnienia tylko administrator; innym rolom nadaje właściciel w edycji ról.
 */
return new class extends Migration
{
    private const NAMES = ['offers.view_all', 'offers.view_selected'];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            // numery użytkowników, których oferty widzi rola z offers.view_selected
            $table->json('offer_visible_user_ids')->nullable();
        });

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
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('offer_visible_user_ids');
        });
    }
};
