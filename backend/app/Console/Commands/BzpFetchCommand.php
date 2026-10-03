<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProcurementNotice;
use App\Services\Bzp\BzpClient;
use App\Services\Bzp\BzpNoticeParser;
use App\Services\Bzp\BzpNoticeStore;
use App\Services\Bzp\BzpTenderLinker;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Pobieranie ogłoszeń o zamówieniu i o wyniku z Biuletynu Zamówień Publicznych (config/bzp.php) i łączenie
 * ich z przetargami (BzpTenderLinker).
 *
 * Pamięć (serwer CLI ma 128 MB): strony po 50 ogłoszeń, każde ogłoszenie zapisywane i zwalniane od razu, bez
 * dziennika zapytań; ogłoszenie zapisane już tą samą wersją parsera jest pomijane (to samo ogłoszenie przychodzi
 * pod kilkoma kodami CPV). Błąd jednego zapytania (kod CPV) nie przerywa pozostałych — polecenie kończy się
 * błędem dopiero na końcu, żeby zapis przebiegu pokazał problem.
 */
final class BzpFetchCommand extends Command
{
    protected $signature = 'bzp:fetch
                            {--days=7 : Ile dni wstecz (data publikacji) pobrać}
                            {--from= : Początek zakresu dat publikacji (RRRR-MM-DD), zamiast --days}
                            {--to= : Koniec zakresu dat publikacji (RRRR-MM-DD), domyślnie dziś}
                            {--reparse : Bez pobierania: odczytaj od nowa zapisane ogłoszenia starszą wersją parsera i połącz z przetargami}';

    protected $description = 'Pobiera ogłoszenia o zamówieniu i o wyniku z Biuletynu Zamówień Publicznych i łączy je z przetargami';

    public function handle(BzpClient $client, BzpNoticeParser $parser, BzpNoticeStore $store, BzpTenderLinker $linker): int
    {
        DB::disableQueryLog();

        if ($this->option('reparse')) {
            $this->reparse($parser, $store);

            return $this->link($linker) ? self::SUCCESS : self::FAILURE;
        }

        try {
            [$from, $to] = $this->range();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $stats = ['received' => 0, 'saved' => 0, 'skipped' => 0, 'invalid' => 0];
        $failures = [];
        /** @var array<string, true> $seen numery ogłoszeń już obsłużone w tym przebiegu */
        $seen = [];

        foreach ((array) config('bzp.notice_types', []) as $type) {
            foreach ((array) config('bzp.cpv_codes', []) as $cpv) {
                try {
                    foreach ($client->notices((string) $type, (string) $cpv, $from, $to) as $item) {
                        $stats['received']++;
                        $this->handleItem($item, $parser, $store, $seen, $stats);
                        unset($item);
                    }
                } catch (RuntimeException $e) {
                    $failures[] = $type.' '.$cpv.': '.$e->getMessage();
                    $this->warn('Nie pobrano '.$type.' '.$cpv.': '.$e->getMessage());
                }
            }
        }

        $this->info(sprintf(
            'Biuletyn %s – %s: zapytań %d, ogłoszeń w odpowiedziach %d, zapisanych %d, bez zmian %d, nieczytelnych %d.',
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $client->requests(),
            $stats['received'],
            $stats['saved'],
            $stats['skipped'],
            $stats['invalid'],
        ));

        $linked = $this->link($linker);

        if ($failures !== []) {
            $this->error('Nieudane zapytania: '.count($failures).'. Pierwsze: '.$failures[0]);

            return self::FAILURE;
        }

        return $linked ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, true>  $seen
     * @param  array<string, int>  $stats
     */
    private function handleItem(array $item, BzpNoticeParser $parser, BzpNoticeStore $store, array &$seen, array &$stats): void
    {
        $number = is_string($item['noticeNumber'] ?? null) ? trim($item['noticeNumber']) : '';
        if ($number !== '' && isset($seen[$number])) {
            $stats['skipped']++;

            return;
        }
        $seen[$number] = true;

        try {
            $record = $parser->parse($item);
        } catch (InvalidArgumentException $e) {
            $stats['invalid']++;
            $this->warn($e->getMessage());

            return;
        }
        $seen[$record['notice_number']] = true;
        if ($store->storedVersion($record['notice_number']) === BzpNoticeParser::VERSION) {
            $stats['skipped']++;

            return;
        }

        try {
            $store->upsert($record);
            $stats['saved']++;
        } catch (Throwable $e) {
            $stats['invalid']++;
            $this->warn('Nie zapisano ogłoszenia '.$record['notice_number'].': '.$e->getMessage());
        }
        unset($record);
    }

    /**
     * Zapisane ogłoszenia z pełną treścią odczytane starszą wersją parsera — od nowa, porcjami.
     */
    private function reparse(BzpNoticeParser $parser, BzpNoticeStore $store): void
    {
        $done = 0;
        $failed = 0;
        ProcurementNotice::query()
            ->where('parser_version', '<', BzpNoticeParser::VERSION)
            ->whereNotNull('html_body')
            ->select(['id'])
            ->chunkById(50, function (Collection $chunk) use ($parser, $store, &$done, &$failed): void {
                foreach ($chunk as $row) {
                    $notice = ProcurementNotice::query()->find($row->id);
                    $item = $notice !== null ? BzpNoticeParser::toApiItem($notice) : null;
                    unset($notice);
                    if ($item === null) {
                        continue;
                    }
                    try {
                        $store->upsert($parser->parse($item));
                        $done++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->warn('Nie odczytano ogłoszenia #'.$row->id.': '.$e->getMessage());
                    }
                    unset($item);
                }
            });
        $skipped = ProcurementNotice::query()->where('parser_version', '<', BzpNoticeParser::VERSION)->whereNull('html_body')->count();

        $this->info(sprintf('Odczytano od nowa: %d, błędy: %d, bez pełnej treści (nie da się odczytać): %d.', $done, $failed, $skipped));
    }

    /**
     * Łączenie przetargów z ogłoszeniami; false = przy części przetargów wystąpił błąd (szczegóły w dzienniku
     * błędów) — pozostałe przetargi zostały połączone.
     */
    private function link(BzpTenderLinker $linker): bool
    {
        $stats = $linker->linkAll();
        $this->info(sprintf(
            'Przetargi z numerem ogłoszenia Biuletynu: %d, z odnalezionym ogłoszeniem: %d, uzupełnione części: %d.',
            $stats['tenders'],
            $stats['linked'],
            $stats['changed_lots'],
        ));
        if ($stats['failed'] > 0) {
            $this->error('Nie udało się połączyć z ogłoszeniami przetargów: '.$stats['failed'].' (szczegóły w dzienniku błędów).');

            return false;
        }

        return true;
    }

    /**
     * Zakres dat publikacji: --from/--to (RRRR-MM-DD) albo ostatnie --days dni do dziś (czas polski).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(): array
    {
        $today = PolishTime::today();
        $to = $this->dateOption('to') ?? $today;
        $from = $this->dateOption('from');
        if ($from === null) {
            $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
            if ($days === false || $days < 1 || $days > 366) {
                throw new InvalidArgumentException('--days musi być liczbą od 1 do 366.');
            }
            $from = $to->subDays($days - 1);
        }
        if ($from->gt($to)) {
            throw new InvalidArgumentException('Początek zakresu (--from) jest późniejszy niż koniec (--to).');
        }

        return [$from, $to];
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, PolishTime::TIMEZONE)
            : false;
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('--'.$name.' musi mieć postać RRRR-MM-DD.');
        }

        return $date;
    }
}
