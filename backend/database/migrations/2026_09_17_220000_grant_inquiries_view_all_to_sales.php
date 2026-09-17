<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Handlowcy i przetargi też widzą zapytania całego zespołu.
 *
 * Ten sam mail od klienta trafia do kilku osób (wysyłka na kilka adresów albo
 * przekierowanie ze skrzynki ogólnej). Bez wglądu w cudze zapytania dwie–trzy
 * osoby przygotowują tę samą ofertę, nie wiedząc o sobie.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $roles = ['handlowiec', 'przetargi'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::findOrCreate('inquiries.view_all', 'web');

        foreach ($this->roles as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', 'inquiries.view_all')
            ->where('guard_name', 'web')
            ->first();

        if ($permission === null) {
            return;
        }

        foreach ($this->roles as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->revokePermissionTo($permission);
        }
    }
};
