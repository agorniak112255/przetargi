<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpSearchSuggester;
use Illuminate\Console\Command;

class ErpSuggestCommand extends Command
{
    protected $signature = 'erp:suggest
                            {--limit=2000 : Najwięcej towarów w przebiegu (najpierw nieprzeszukane, potem największy stan)}
                            {--recheck-days=30 : Przeszukane wcześniej wracają po tylu dniach (0 = przeszukaj ponownie wszystkie)}';

    protected $description = 'Propozycje kart z wyszukiwarki (bez modelu) dla towarów ERP XL bez kodu — tylko do decyzji na ekranie';

    public function handle(ErpSearchSuggester $suggester): int
    {
        $stats = $suggester->run(
            max(1, (int) $this->option('limit')),
            max(0, (int) $this->option('recheck-days')),
            function (int $done): void {
                $this->output->write("\rPrzeszukane: ".$done);
            },
        );
        $this->newLine();
        $this->info(sprintf(
            'Przeszukane towary: %d, z propozycjami: %d, propozycji: %d.',
            $stats['checked'], $stats['with_suggestions'], $stats['suggestions'],
        ));

        return self::SUCCESS;
    }
}
