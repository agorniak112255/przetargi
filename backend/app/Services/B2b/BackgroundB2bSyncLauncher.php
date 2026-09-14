<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use RuntimeException;

/**
 * Osobny, odłączony proces `artisan b2b:sync {id} --trigger=…` na konto — żyje dłużej niż b2b:sync-due, więc
 * harmonogram kończy się w kilka sekund, a konta różnych dostawców pobierają się równolegle.
 *
 * Linux: powłoka przez exec() z `nohup … &`, wyjście dopisywane do storage/logs/b2b-sync.log, stdin z /dev/null —
 * exec() wraca od razu, bo potomek nie trzyma rury wyjścia. Bez Symfony Process (zatrzymuje proces w __destruct).
 * Postęp i dziennik przebiegu są w b2b_sync_runs (panel); plik łapie tylko to, czego nie zapisze baza (np. fatal).
 * Windows (lokalny XAMPP): bez odłączania — przebieg w bieżącym procesie (InlineB2bSyncLauncher).
 * Tylko z CLI: PHP_BINARY pod php-fpm wskazywałby php-fpm.
 */
final class BackgroundB2bSyncLauncher implements B2bSyncLauncher
{
    public function __construct(
        private readonly InlineB2bSyncLauncher $inline,
        private readonly string $phpBinary,
        private readonly string $artisan,
        private readonly string $logPath,
        private readonly bool $windows = PHP_OS_FAMILY === 'Windows',
    ) {}

    public function launch(B2bAccount $account, string $trigger): bool
    {
        if ($this->windows) {
            return $this->inline->launch($account, $trigger);
        }
        if (! function_exists('exec')) {
            throw new RuntimeException('Funkcja exec() jest wyłączona w PHP CLI — przebieg w tle nie ruszył.');
        }

        // Nagłówek z PHP, nie z powłoki: błąd zapisu widać tutaj. Gdy plik jest niezapisywalny, przekierowanie
        // w powłoce i tak by się nie udało, a potomek nie wystartowałby wcale — bez śladu.
        $header = sprintf(
            "\n[%s] b2b:sync konto #%d (%s), trigger=%s — uruchomione przez b2b:sync-due\n",
            now()->toIso8601String(),
            $account->id,
            $account->username,
            $trigger,
        );
        if (@file_put_contents($this->logPath, $header, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Nie można zapisać dziennika {$this->logPath} — przebieg w tle nie ruszył. Sprawdź uprawnienia storage/logs.");
        }

        $output = [];
        $code = 0;
        exec($this->command((int) $account->id, $trigger), $output, $code);
        if ($code !== 0) {
            throw new RuntimeException("Nie udało się uruchomić przebiegu w tle (kod powłoki {$code}).");
        }

        return false;
    }

    /**
     * Polecenie powłoki POSIX (sh). Cytowanie własne zamiast escapeshellarg — to samo polecenie na każdym systemie
     * (escapeshellarg na Windows cytuje po swojemu).
     */
    public function command(int $accountId, string $trigger): string
    {
        return sprintf(
            'nohup %s %s b2b:sync %d %s >> %s 2>&1 < /dev/null &',
            self::quote($this->phpBinary),
            self::quote($this->artisan),
            $accountId,
            self::quote('--trigger='.$trigger),
            self::quote($this->logPath),
        );
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
