<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ceny specjalne kont B2B tylko z uprawnieniem (decyzja właściciela 30.09.2026): bez niego karta z ceną specjalną
 * pokazuje wszędzie cenę standardową (App\Services\Pricing\SupplierSpecialMask). Na start admin i dyrektor —
 * pozostałe role dostają je w Administracja → Role.
 */
return new class extends Migration
{
    private const PERMISSION = 'prices.supplier_special.view';

    private const ROLES = ['admin', 'dyrektor'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');
        foreach (self::ROLES as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if ($role === null) {
                // świeża baza bez ról — nada je seeder z PermissionCatalog. Migration nie ma $this->command
                // (odczyt rzuciłby ErrorException), więc ostrzeżenie idzie do logu.
                Log::warning('Brak roli '.$name.' — uprawnienie '.self::PERMISSION.' nie zostało jej nadane.');

                continue;
            }
            $role->givePermissionTo($permission);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->first();
        if ($permission === null) {
            return;
        }
        foreach (self::ROLES as $name) {
            Role::query()->where('name', $name)->where('guard_name', 'web')->first()?->revokePermissionTo($permission);
        }
        $permission->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
