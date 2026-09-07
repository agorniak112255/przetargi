<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Presta\PrestaAccessoryReader;
use App\Services\Presta\PrestaShopCatalogClient;
use App\Services\ProductAccessorySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SyncPrestaAccessoriesCommand extends Command
{
    protected $signature = 'presta:sync-accessories
                            {--host= : Host MySQL Presty}
                            {--port=3306 : Port}
                            {--database= : Baza Presty (np. lokalny zrzut)}
                            {--username= : Użytkownik MySQL}
                            {--password= : Hasło MySQL}';

    protected $description = 'Pobiera powiązania „Warianty produktu i akcesoria” z Presty i dopasowuje do katalogu';

    public function handle(
        ProductAccessorySyncService $sync,
        PrestaAccessoryReader $reader,
        PrestaShopCatalogClient $catalog,
    ): int {
        $override = $this->connectionOverride();
        if ($override !== []) {
            $reader->useConnection($override);
            $this->info('Czytam Presta z '.$override['database'].' @ '.$override['host']);
        } elseif (! $this->configuredConnectionWorks($catalog)) {
            $local = $this->localDumpOverride();
            if ($local === []) {
                $this->error('Brak połączenia z Presta. Podaj --database= (lokalny zrzut) albo popraw ustawienia sklepu.');

                return self::FAILURE;
            }
            $reader->useConnection($local);
            $this->info('Ustawienia Presty nie łączą — czytam lokalny zrzut '.$local['database']);
        }

        try {
            $result = $sync->syncFromPresta();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(
            "Rodzice: {$result['parents']}, powiązań: {$result['links']}"
            .", dopasowanych: {$result['matched']}, bez karty w katalogu: {$result['unmatched']}."
        );

        return self::SUCCESS;
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private function connectionOverride(): array
    {
        $database = trim((string) $this->option('database'));
        if ($database === '') {
            return [];
        }

        return [
            'host' => trim((string) ($this->option('host') ?: '127.0.0.1')),
            'port' => (int) ($this->option('port') ?: 3306),
            'database' => $database,
            'username' => trim((string) ($this->option('username') ?: 'root')),
            'password' => (string) ($this->option('password') ?? ''),
        ];
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private function localDumpOverride(): array
    {
        try {
            $exists = DB::connection('mysql')->select("SHOW DATABASES LIKE 'supon_presta'");
        } catch (Throwable) {
            return [];
        }
        if ($exists === []) {
            return [];
        }

        return [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'supon_presta',
            'username' => 'root',
            'password' => '',
        ];
    }

    private function configuredConnectionWorks(PrestaShopCatalogClient $catalog): bool
    {
        if (! $catalog->configured()) {
            return false;
        }
        $ping = $catalog->ping();

        return (bool) ($ping['ok'] ?? false);
    }
}
