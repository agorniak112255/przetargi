<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dosypuje do bazy uprawnienia, które doszły w kodzie — bez ruszania tego,
 * co ustawił człowiek w panelu „Role”.
 *
 * Skąd to polecenie: `RolesAndPermissionsSeeder` robi na rolach systemowych
 * `syncPermissions`, czyli ustawia je dokładnie tak, jak w katalogu. Na
 * działającej instalacji skasowałoby to każdą ręczną zmianę (odebrane
 * uprawnienie wróciłoby, dodane zniknęło), dlatego seeder nie chodzi przy
 * wdrożeniu. Skutek był taki, że nowe uprawnienie istniało w kodzie, a na
 * serwerze nie miał go nikt — także admin.
 *
 * Zasada: ruszamy **tylko uprawnienia, których w bazie jeszcze nie było**.
 * Skoro dopiero powstają, nikt nie mógł ich świadomie odebrać, więc nadanie
 * ich rolom z katalogu nie kasuje niczyjej decyzji. Uprawnień już istniejących
 * nie dotykamy w żadną stronę.
 */
final class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Dodaje brakujące uprawnienia z katalogu i nadaje nowe rolom systemowym (podgląd bez --apply)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $fresh = array_values(array_diff(PermissionCatalog::ALL, $existing));

        if ($fresh === []) {
            $this->info('Baza zna wszystkie uprawnienia z katalogu — nie ma czego dosypywać.');

            return self::SUCCESS;
        }

        $this->line('Nowe uprawnienia: '.implode(', ', $fresh));

        $plan = [];
        foreach (PermissionCatalog::rolePermissions() as $roleName => $permissions) {
            $toGrant = array_values(array_intersect($fresh, $permissions));
            if ($toGrant !== []) {
                $plan[$roleName] = $toGrant;
            }
        }

        foreach ($plan as $roleName => $toGrant) {
            $this->line('  '.$roleName.' ← '.implode(', ', $toGrant));
        }

        if (! $apply) {
            $this->warn('Podgląd — nic nie zapisano. Powtórz z --apply.');

            return self::SUCCESS;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ($fresh as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach ($plan as $roleName => $toGrant) {
            $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->first();
            if ($role === null) {
                // Rola systemowa, której na tej instalacji nie ma — tworzy ją seeder,
                // nie to polecenie; tu tylko dosypujemy uprawnienia.
                $this->warn('Brak roli '.$roleName.' — pominięta.');

                continue;
            }
            // givePermissionTo dodaje, nigdy nie odbiera — ręczne zmiany zostają.
            $role->givePermissionTo($toGrant);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->info('Gotowe: dosypano '.count($fresh).' uprawnień.');

        return self::SUCCESS;
    }
}
