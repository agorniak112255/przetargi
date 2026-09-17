<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Presta\PrestaCategoryRewriteService;
use Illuminate\Console\Command;

/**
 * Grupa na karcie to kategoria produktu, a ta przychodzi z cennika dostawcy i bywa bez sensu —
 * w cenniku ARTRY kolumna „kat.” to numer pozycji w katalogu, więc powstało 112 „grup” o nazwach
 * w rodzaju 223. Polecenie przepisuje kategorie na drzewo z Presty, czyli na własną, opisaną
 * taksonomię. Domyślnie tylko podgląd — zmiana dotyczy wszystkich kart producenta naraz.
 */
final class RewritePrestaCategoriesCommand extends Command
{
    protected $signature = 'presta:rewrite-categories
                            {--manufacturer= : Tylko karty tego producenta (domyślnie cały katalog)}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}
                            {--samples=15 : Ile przykładów pokazać}';

    protected $description = 'Przepisuje kategorie kart na drzewo Presty; śmieciowe kategorie z cenników czyści';

    public function handle(PrestaCategoryRewriteService $rewrite): int
    {
        $manufacturer = trim((string) $this->option('manufacturer'));
        $apply = (bool) $this->option('apply');
        $samples = max(0, (int) $this->option('samples'));

        $result = $rewrite->rewrite($manufacturer !== '' ? $manufacturer : null, $apply, $samples);

        if ($result['samples'] !== []) {
            $this->table(
                ['Kod', 'Grupa teraz', 'Grupa po zmianie'],
                array_map(
                    static fn (array $row): array => [$row['sku'], $row['from'], $row['to']],
                    $result['samples'],
                ),
            );
        }

        $this->info(sprintf(
            '%s: do przepisania %d, do wyczyszczenia %d, bez zmian %d.',
            $manufacturer !== '' ? $manufacturer : 'Cały katalog',
            $result['updated'],
            $result['cleared'],
            $result['skipped'],
        ));

        if (! $apply) {
            $this->warn('Podgląd — nic nie zapisano. Zapis: --apply.');
        }

        return self::SUCCESS;
    }
}
