<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpItemMatcher;
use Illuminate\Console\Command;

class ErpMatchCommand extends Command
{
    protected $signature = 'erp:match
                            {--csv= : Zapisz decyzję dla każdego towaru XL do tego pliku CSV}';

    protected $description = 'Łączy towary ERP XL z kartami po kodzie producenta (auto tylko z dowodem; potwierdzonych i odrzuconych nie rusza)';

    public function handle(ErpItemMatcher $matcher): int
    {
        $csv = trim((string) $this->option('csv'));
        $handle = null;
        if ($csv !== '') {
            $handle = @fopen($csv, 'wb');
            if ($handle === false) {
                $this->error('Nie można zapisać pliku '.$csv.'.');

                return self::FAILURE;
            }
            fwrite($handle, "\xEF\xBB\xBF");
        }
        $header = false;
        $stats = $matcher->refresh($handle === null ? null : function (array $row) use ($handle, &$header): void {
            if (! $header) {
                fputcsv($handle, array_keys($row), ';');
                $header = true;
            }
            fputcsv($handle, array_values($row), ';');
        });
        if ($handle !== null) {
            fclose($handle);
            $this->line('Raport: '.$csv);
        }

        $this->table(['wynik', 'towarów XL'], [
            ['połączone automatycznie', $stats['auto']],
            ['jedna karta bez dowodu (do sprawdzenia)', $stats['suggested']],
            ['kilka kart (do wyboru)', $stats['ambiguous']],
            ['kod w nazwie karty (propozycje)', $stats['name_suggested']],
            ['propozycje z wyszukiwarki (bez zmian)', $stats['search_suggested']],
            ['propozycje odrzucone ręcznie', $stats['rejected']],
            ['kod trafia w inny rodzaj wyrobu (odrzucone)', $stats['family_conflict']],
            ['kod bez karty w katalogu', $stats['no_match']],
            ['bez kodu w nazwie', $stats['no_code']],
            ['potwierdzone ręcznie (bez zmian)', $stats['confirmed_kept']],
        ]);
        $this->line('Towarów: '.$stats['items'].', usunięte nieaktualne powiązania: '.$stats['removed_links'].'.');

        return self::SUCCESS;
    }
}
