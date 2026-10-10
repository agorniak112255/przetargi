<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PriceLists\Importers\PriceListImporterRegistry;
use App\Services\PriceLists\PriceListIntakeRunner;
use Illuminate\Console\Command;

/**
 * Wiązanie cennika z importerem (price_lists.importer_key) — po kluczu importera, nigdy po id cennika w kodzie
 * (różne id lokalnie i na produkcji). --unbind zdejmuje importer.
 */
final class PriceListsBindCommand extends Command
{
    protected $signature = 'price-lists:bind
                            {lista : Numer cennika albo manufacturer_key}
                            {klucz? : Klucz importera z rejestru (PriceListImporterRegistry)}
                            {--unbind : Zdejmij importer z cennika}';

    protected $description = 'Przypisz cennikowi importer pliku (albo zdejmij go --unbind)';

    public function handle(PriceListIntakeRunner $runner, PriceListImporterRegistry $registry): int
    {
        $list = $runner->findList((string) $this->argument('lista'));
        if ($list === null) {
            $this->error('Nie ma cennika „'.$this->argument('lista').'”.');

            return self::FAILURE;
        }
        if ($this->option('unbind')) {
            $list->update(['importer_key' => null]);
            $this->info('Cennik #'.$list->id.' ('.$list->manufacturer.') bez importera.');

            return self::SUCCESS;
        }
        $key = trim((string) $this->argument('klucz'));
        if ($key === '') {
            $this->error('Podaj klucz importera albo --unbind.');

            return self::FAILURE;
        }
        $class = $registry->classFor($key);
        if ($class === null) {
            $this->error('Nie ma importera o kluczu „'.$key.'”. Dostępne: '.(implode(', ', array_column($registry->options(), 'key')) ?: 'brak'));

            return self::FAILURE;
        }
        if ($class::manufacturerKeys() !== [] && ! in_array((string) $list->manufacturer_key, $class::manufacturerKeys(), true)) {
            $this->warn('Importer „'.$key.'” powstał dla: '.implode(', ', $class::manufacturerKeys()).' — cennik ma klucz '.$list->manufacturer_key.'.');
        }
        $list->update(['importer_key' => $key]);
        $this->info('Cennik #'.$list->id.' ('.$list->manufacturer.') → importer '.$key.' (wersja '.$class::version().').');
        if (! $list->usesIntake()) {
            $this->warn('Cennik nie ma source_policy — opisy kart idą jeszcze dawnym sposobem (ustaw w Cenniki → Z pliku → Edytuj ustawienia).');
        }

        return self::SUCCESS;
    }
}
