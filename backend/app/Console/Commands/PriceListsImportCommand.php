<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PriceLists\Importers\PriceListFormatChanged;
use App\Services\PriceLists\IntakeNotReady;
use App\Services\PriceLists\PriceListIntakeRunner;
use App\Services\PriceLists\ReadOnlyViolation;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import pliku cennika importerem. Bez --apply: podgląd (jak price-lists:preview, bez zapisów). --apply: zapis kart,
 * status pliku i zadanie mapy kart; --describe: po mapie kolejka opisów dla nowych/zmienionych kart i kart bez opisu.
 */
final class PriceListsImportCommand extends Command
{
    protected $signature = 'price-lists:import
                            {lista : Numer cennika albo manufacturer_key}
                            {--file= : Numer pliku cennika (domyślnie najnowszy niezastąpiony)}
                            {--apply : Zapisz import (bez tego tylko podgląd)}
                            {--describe : Po mapie kart zleć opisy}';

    protected $description = 'Import pliku cennika importerem (bez --apply tylko podgląd)';

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

        if (! $this->option('apply')) {
            try {
                $view = $runner->preview($list, $file);
            } catch (IntakeNotReady|PriceListFormatChanged|ReadOnlyViolation $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            PriceListsPreviewCommand::render($this, 'PODGLĄD (bez zapisu, --apply zapisuje) — '.$list->manufacturer.' (#'.$list->id.'), plik #'.$file->id.' '.$file->original_name, $view);

            return self::SUCCESS;
        }

        $user = $this->user($list->imported_by);
        if ($user === null) {
            $this->error('Brak użytkownika, na którego zapisać import (administrator).');

            return self::FAILURE;
        }
        try {
            $result = $runner->import($list, $file, $user, (bool) $this->option('describe'));
        } catch (IntakeNotReady|PriceListFormatChanged $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Zaimportowano plik #'.$file->id.' do cennika #'.$list->id.': nowe '.$result['created'].', zaktualizowane '.$result['updated']
            .', pominięte '.$result['skipped'].', zmiany cen '.count($result['price_changes']).'. Mapa kart: w kolejce.');
        foreach (array_slice($result['errors'], 0, 20) as $note) {
            $this->comment($note);
        }

        return self::SUCCESS;
    }

    private function user(mixed $importedBy): ?User
    {
        try {
            $admin = User::role('admin')->orderBy('id')->first();
        } catch (Throwable) {
            $admin = null;
        }
        if ($admin instanceof User) {
            return $admin;
        }

        return $importedBy !== null ? User::query()->find((int) $importedBy) : null;
    }
}
