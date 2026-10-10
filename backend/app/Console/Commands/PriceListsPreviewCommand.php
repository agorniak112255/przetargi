<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PriceLists\Importers\PriceListFormatChanged;
use App\Services\PriceLists\IntakeNotReady;
use App\Services\PriceLists\PriceListIntakeRunner;
use App\Services\PriceLists\ReadOnlyViolation;
use Illuminate\Console\Command;

/**
 * Podgląd importu pliku cennika importerem — bez zapisów (ReadOnlyGuard: sesja READ ONLY na MySQL, transakcja
 * wycofywana, kolejka bez wykonania). Programista uruchamia go na produkcji przed importem.
 */
final class PriceListsPreviewCommand extends Command
{
    protected $signature = 'price-lists:preview
                            {lista : Numer cennika albo manufacturer_key}
                            {--file= : Numer pliku cennika (domyślnie najnowszy niezastąpiony)}
                            {--live-fetch : Pobieraj strony przy mapie kart (pamięć podręczna tylko w tablicy)}
                            {--json : Wynik jako JSON (PreviewView)}
                            {--limit=200 : Ile kart mapować w podglądzie}';

    protected $description = 'Tylko odczyt: podgląd importu pliku cennika importerem (karty, ceny, mapa stron)';

    public function handle(PriceListIntakeRunner $runner): int
    {
        $list = $runner->findList((string) $this->argument('lista'));
        if ($list === null) {
            $this->error('Nie ma cennika „'.$this->argument('lista').'”.');

            return self::FAILURE;
        }
        $file = $runner->findFile($list, $this->option('file') !== null ? (string) $this->option('file') : null);
        if ($file === null) {
            $this->error('Cennik #'.$list->id.' nie ma pliku'.($this->option('file') !== null ? ' #'.$this->option('file') : '').'.');

            return self::FAILURE;
        }
        try {
            $view = $runner->previewWith($list, $file, max(0, (int) $this->option('limit')), (bool) $this->option('live-fetch'));
        } catch (IntakeNotReady|PriceListFormatChanged|ReadOnlyViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($view, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        self::render($this, $list->manufacturer.' (#'.$list->id.'), plik #'.$file->id.' '.$file->original_name, $view);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $view  PreviewView */
    public static function render(Command $command, string $title, array $view): void
    {
        $command->info($title.' — importer '.$view['importer']['key'].' v'.$view['importer']['version']);
        $command->line('Wiersze pliku: '.$view['rows_total']
            .' · nowe karty: '.$view['rows']['create'].' · aktualizacje: '.$view['rows']['update']
            .' · pominięte: '.$view['rows']['skip'].' · zablokowane: '.$view['rows']['blocked']);
        $sources = [];
        foreach ($view['sources'] as $kind => $count) {
            $sources[] = [$kind, $count];
        }
        $command->table(['Źródło opisu', 'Kart'], $sources);
        if ($view['samples'] !== []) {
            $command->table(['Kod', 'Nazwa', 'Akcja', 'Adres', 'Rodzaj', 'Dopasowanie'], array_map(static fn (array $s): array => [
                $s['sku'], mb_strimwidth((string) $s['name'], 0, 40, '…'), $s['action'], $s['url'] ?? '—', $s['source_kind'] ?? '—', $s['match_kind'] ?? '—',
            ], $view['samples']));
        }
        foreach (array_slice($view['unresolved'], 0, 20) as $row) {
            $command->line('Bez strony: '.$row['sku'].' — '.$row['reason']);
        }
        foreach (array_slice($view['skipped'], 0, 20) as $row) {
            $command->line('Pominięty: '.($row['ref'] !== '' ? $row['ref'].' ' : '').($row['sku'] ?? '').' — '.$row['reason']);
        }
        foreach (array_slice($view['price_changes'], 0, 20) as $row) {
            $command->line('Zmiana ceny: '.$row['sku'].' '.$row['old'].' → '.$row['new']);
        }
        foreach ([...$view['notes'], ...$view['not_in_preview']] as $note) {
            $command->comment($note);
        }
    }
}
