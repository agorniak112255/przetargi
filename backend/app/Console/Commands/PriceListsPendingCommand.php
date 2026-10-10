<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Services\PriceLists\Importers\PriceListImporterRegistry;
use Illuminate\Console\Command;

/**
 * Tylko odczyt: cenniki przyjmowane nowym sposobem (source_policy albo importer_key) ze stanem przyjęcia
 * (PriceList::intakeStatus) i podpowiedzią importera z rejestru (manufacturerKeys) — lista pracy programisty.
 */
final class PriceListsPendingCommand extends Command
{
    protected $signature = 'price-lists:pending {--all : Także cenniki już zaimportowane}';

    protected $description = 'Tylko odczyt: cenniki z plików czekające na importer, plik albo import (nowy sposób)';

    public function handle(PriceListImporterRegistry $registry): int
    {
        $lists = PriceList::query()
            ->where(static fn ($q) => $q->whereNotNull('source_policy')->orWhereNotNull('importer_key'))
            ->with('files')
            ->orderBy('id')
            ->get();
        $rows = [];
        foreach ($lists as $list) {
            $status = $list->intakeStatus();
            if (! $this->option('all') && $status === PriceList::INTAKE_IMPORTED) {
                continue;
            }
            $latest = $list->files->first();
            $suggested = [];
            foreach ($registry->options() as $option) {
                if (in_array((string) $list->manufacturer_key, $option['manufacturer_keys'], true)) {
                    $suggested[] = $option['key'];
                }
            }
            $rows[] = [
                $list->id,
                $list->manufacturer,
                $list->manufacturer_key,
                $status,
                $list->importer_key ?? '—',
                $latest !== null ? '#'.$latest->id.' '.$latest->original_name.' ('.$latest->status.')' : '—',
                $suggested !== [] ? implode(', ', $suggested) : '—',
                $list->importer_notes !== null ? mb_strimwidth((string) $list->importer_notes, 0, 60, '…') : '',
            ];
        }
        if ($rows === []) {
            $this->info('Brak cenników czekających na importer, plik albo import.');

            return self::SUCCESS;
        }
        $this->table(['ID', 'Producent', 'Klucz', 'Stan', 'Importer', 'Najnowszy plik', 'Importer z rejestru', 'Uwagi'], $rows);

        return self::SUCCESS;
    }
}
