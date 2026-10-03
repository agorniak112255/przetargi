<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Pobieranie ogłoszeń o zamówieniu i o wyniku z Biuletynu Zamówień Publicznych (config/bzp.php) i łączenie
 * ich z przetargami. ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień B.
 */
final class BzpFetchCommand extends Command
{
    protected $signature = 'bzp:fetch
                            {--days=7 : Ile dni wstecz (data publikacji) pobrać}
                            {--from= : Początek zakresu dat publikacji (RRRR-MM-DD), zamiast --days}
                            {--to= : Koniec zakresu dat publikacji (RRRR-MM-DD), domyślnie dziś}
                            {--reparse : Odczytaj od nowa zapisane ogłoszenia starszą wersją parsera}';

    protected $description = 'Pobiera ogłoszenia o zamówieniu i o wyniku z Biuletynu Zamówień Publicznych i łączy je z przetargami';

    public function handle(): int
    {
        $this->info('Pobieranie z Biuletynu Zamówień Publicznych jeszcze niegotowe.');

        return self::SUCCESS;
    }
}
