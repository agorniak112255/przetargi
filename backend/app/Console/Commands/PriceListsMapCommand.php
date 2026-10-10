<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MapPriceListSourcesJob;
use App\Services\PriceLists\PriceListIntakeRunner;
use App\Services\PriceLists\ReadOnlyGuard;
use App\Services\PriceLists\ReadOnlyViolation;
use Illuminate\Console\Command;

/**
 * Mapa kart cennika z importerem (product_source_pins). Bez --apply: liczy z pobieraniem stron i pokazuje zmiany,
 * nic nie zapisuje (ReadOnlyGuard). --apply: zapis zmienionych pinów od razu; --queue: zadanie w kolejce.
 */
final class PriceListsMapCommand extends Command
{
    protected $signature = 'price-lists:map
                            {lista : Numer cennika albo manufacturer_key}
                            {--id=* : Tylko te karty (id)}
                            {--apply : Zapisz zmienione piny}
                            {--queue : Zleć zadanie mapy w kolejce (MapPriceListSourcesJob)}';

    protected $description = 'Mapa kart cennika z importerem: podgląd zmian albo zapis (--apply / --queue)';

    public function handle(PriceListIntakeRunner $runner, ReadOnlyGuard $guard): int
    {
        $list = $runner->findList((string) $this->argument('lista'));
        if ($list === null) {
            $this->error('Nie ma cennika „'.$this->argument('lista').'”.');

            return self::FAILURE;
        }
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id')), static fn (int $id): bool => $id > 0));
        $job = new MapPriceListSourcesJob((int) $list->id, false, $ids !== [] ? $ids : null);

        if ($this->option('queue')) {
            dispatch($job);
            $this->info('Zadanie mapy kart cennika #'.$list->id.' w kolejce.');

            return self::SUCCESS;
        }
        $apply = (bool) $this->option('apply');
        try {
            $result = $apply ? $job->run(true) : $guard->run(static fn (): array => $job->run(false));
        } catch (ReadOnlyViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($apply ? 'Zapisano' : 'Podgląd (bez zapisu)').' — cennik #'.$list->id.' '.$list->manufacturer);
        $this->line('Kart: '.$result['cards'].' · przypięte: '.$result['pinned'].' · bez strony: '.$result['unresolved']
            .' · zmienione: '.$result['changed'].' · bez zmian: '.$result['unchanged'].($apply ? ' · zapisane: '.$result['written'] : ''));
        if ($result['changes'] !== []) {
            $this->table(['Karta', 'Kod', 'Było', 'Będzie', 'Powód braku'], array_map(static fn (array $c): array => [
                $c['product_id'], $c['sku'], $c['old_url'] ?? '—', $c['new_url'] ?? '—', $c['reason'] ?? '',
            ], array_slice($result['changes'], 0, 100)));
        }

        return self::SUCCESS;
    }
}
