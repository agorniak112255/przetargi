<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\NetworkAccessPolicy;
use Illuminate\Console\Command;

/**
 * Wyjście awaryjne, gdy administrator nie może się zalogować (np. biuro dostało nowy adres IP, a jego konto albo grupa
 * ma „tylko z sieci lokalnej”): ustawia dostęp z sieci jednego konta bez panelu i bez kontroli z panelu.
 * Bez --apply tylko pokazuje, co obowiązuje teraz.
 */
final class UserNetworkAccessCommand extends Command
{
    protected $signature = 'users:network-access
                            {email : E-mail konta}
                            {mode? : any = z każdej sieci, local = tylko z sieci lokalnej, group = jak w grupie}
                            {--apply : Zapisz zmianę (bez tej flagi tylko podgląd)}';

    protected $description = 'Pokazuje albo ustawia dostęp z sieci jednego konta (wyjście awaryjne przy zablokowanym logowaniu)';

    public function handle(NetworkAccessPolicy $policy): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($user === null) {
            $this->error('Nie ma konta z tym adresem e-mail.');

            return self::FAILURE;
        }

        $this->line('Teraz: '.$this->describe($policy, $user));
        $this->line('Adresy sieci lokalnej: '.(implode(', ', $policy->addresses()) ?: 'brak'));

        $mode = $this->argument('mode');
        if ($mode === null) {
            return self::SUCCESS;
        }
        $map = ['any' => NetworkAccessPolicy::ANY, 'local' => NetworkAccessPolicy::LOCAL, 'group' => null];
        if (! array_key_exists((string) $mode, $map)) {
            $this->error('Tryb: any, local albo group.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->warn('Podgląd — dopisz --apply, żeby zapisać.');

            return self::SUCCESS;
        }

        $user->forceFill(['network_access' => $map[(string) $mode]])->save();
        $this->info('Zapisano: '.$this->describe($policy, $user->fresh() ?? $user));

        return self::SUCCESS;
    }

    private function describe(NetworkAccessPolicy $policy, User $user): string
    {
        $effective = $policy->effective($user);
        $mode = $effective['mode'] === NetworkAccessPolicy::LOCAL ? 'tylko z sieci lokalnej' : 'z każdej sieci';
        $source = match ($effective['source']) {
            'user' => 'ustawienie konta',
            'role' => $effective['role'] !== null ? 'grupa '.$effective['role'] : 'grupa',
            default => 'domyślnie',
        };

        return $mode.' ('.$source.')';
    }
}
