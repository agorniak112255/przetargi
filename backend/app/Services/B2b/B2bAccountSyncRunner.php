<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\PriceList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Jeden przebieg synchronizacji konta: status na koncie (running/ok/failed/cancelled), wpis w
 * b2b_sync_runs z postępem i dziennikiem oraz aktualizacja stałego wpisu konta w Cennikach
 * (poza --dry-run) + wynik.
 */
final class B2bAccountSyncRunner
{
    public const ALREADY_RUNNING = 'Pobieranie tego konta już trwa.';

    /**
     * Pełny cennik (tysiące kart, strony produktu po ~150 KB) nie mieści się w domyślnych 128 MB z php.ini —
     * przebieg UVEX ginął w połowie listy na „Allowed memory size exhausted”. Limit podnosimy tylko w górę.
     */
    private const MEMORY_LIMIT_BYTES = 512 * 1024 * 1024;

    /** Błędy, po których PHP kończy proces — przebieg trzeba domknąć w shutdown, bo catch się nie wykona. */
    private const FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    public function __construct(
        private readonly B2bConnectorRegistry $connectors,
        private readonly B2bCatalogSync $sync,
        private readonly B2bAccountPriceList $priceLists,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onProduct
     * @param  string  $trigger  B2bSyncRun::TRIGGER_* — skąd przebieg ruszył
     * @param  B2bConnector|null  $connector  gotowy łącznik (testy); domyślnie z rejestru wg konta
     * @return array<string, mixed> wynik B2bCatalogSync::run + sync_run_id i price_list_id (null przy --dry-run)
     *
     * @throws RuntimeException gdy konto ma już przebieg w toku (ALREADY_RUNNING)
     */
    public function run(
        B2bAccount $account,
        ?int $limit = null,
        bool $dryRun = false,
        ?bool $withImages = null,
        int $delayMs = 150,
        ?callable $onProduct = null,
        string $trigger = B2bSyncRun::TRIGGER_CLI,
        ?B2bConnector $connector = null,
    ): array {
        self::raiseMemoryLimit();

        $progress = null;
        $live = true;
        if (! $dryRun) {
            $progress = $this->claim($account, $trigger);
            $this->closeRunOnFatalError($account, $progress, $live);
            $this->closeRunOnSignal($account, $progress, $live);
        }
        $priceList = null;
        $priceListCreated = false;

        try {
            $connector ??= $this->connectors->make($account, $delayMs);
            if ($progress !== null) {
                [$priceList, $priceListCreated] = $this->priceLists->resolve($account, $connector);
            }
            $result = $this->sync->run(
                $account,
                $connector,
                limit: $limit,
                dryRun: $dryRun,
                withImages: $withImages ?? (bool) ($account->sync_images ?? true),
                onProduct: $onProduct,
                progress: $progress,
                priceListId: $priceList?->id,
            );
        } catch (Throwable $e) {
            if (! $dryRun) {
                $message = mb_substr($e->getMessage(), 0, 2000);
                $account->forceFill([
                    'last_sync_status' => 'failed',
                    'last_sync_finished_at' => now(),
                    'last_sync_message' => $message,
                ])->save();
                $progress?->log('error', $message);
                if ($progress !== null && $priceList !== null) {
                    $run = $progress->run();
                    if ((int) ($run->created ?? 0) + (int) ($run->updated ?? 0) > 0) {
                        $this->recordPriceList($priceList, $account, $progress);
                    } elseif ($priceListCreated) {
                        $this->discardPriceList($priceList, $account);
                    }
                }
                $progress?->finish(B2bSyncRun::STATUS_FAILED, $message);
            }

            throw $e;
        } finally {
            // przebieg jest już domknięty (albo poleciał wyjątek) — shutdown nie ma czego sprzątać
            $live = false;
        }

        if (! $dryRun) {
            $summary = $this->summary($result);
            $status = $result['cancelled'] ? B2bSyncRun::STATUS_CANCELLED : B2bSyncRun::STATUS_OK;
            $variants = ($result['progress_unit'] ?? null) === B2bSyncRun::UNIT_VARIANTS;
            $message = match (true) {
                $result['cancelled'] => sprintf(
                    'Zatrzymano ręcznie po %d z %d%s',
                    $result['processed'],
                    $result['progress_total'],
                    $variants ? ' wersji' : '',
                ),
                // limit czasu łącznika: status ok, resztę dokończy następny przebieg (znaki najdawniej sprawdzane)
                $result['partial'] => sprintf(
                    'Częściowy: %d/%d %s — kontynuacja w następnym przebiegu',
                    $result['processed'],
                    $result['progress_total'],
                    $variants ? 'wersji' : 'produktów',
                )."\n".$summary,
                default => $summary,
            };
            $message = mb_substr($message, 0, 2000);
            if ($progress !== null && $priceList !== null) {
                $this->recordPriceList($priceList, $account, $progress);
            }
            $account->forceFill([
                'last_sync_status' => $status,
                'last_sync_finished_at' => now(),
                'last_sync_message' => $message,
            ])->save();
            if ($result['cancelled'] || $result['partial']) {
                $progress?->log('warn', strtok($message, "\n") ?: $message);
            }
            $progress?->log('info', strtok($summary, "\n") ?: $summary);
            $progress?->finish($status, $message);
        }

        return [...$result, 'sync_run_id' => $progress?->run()->id, 'price_list_id' => $priceList?->id];
    }

    /**
     * Limit pamięci procesu — tylko w górę. Tutaj, a nie w poleceniu: ten sam przebieg rusza z b2b:sync,
     * z b2b:sync-due (na Windows i w testach bez osobnego procesu) i z panelu.
     */
    private static function raiseMemoryLimit(): void
    {
        if (! function_exists('ini_set')) {
            return;
        }
        $current = self::memoryLimitBytes((string) ini_get('memory_limit'));
        // null = bez limitu (-1) albo zapis nie do odczytania — nie ruszamy
        if ($current === null || $current >= self::MEMORY_LIMIT_BYTES) {
            return;
        }
        ini_set('memory_limit', (string) self::MEMORY_LIMIT_BYTES);
    }

    /** „128M” → bajty; null = bez limitu albo nieznany zapis. */
    public static function memoryLimitBytes(string $value): ?int
    {
        if (preg_match('/^(\d+)([KMG]?)$/i', trim($value), $m) !== 1) {
            return null;
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };
    }

    /**
     * Fatal (np. „Allowed memory size exhausted”) kończy proces bez catch i bez finally — przebieg zostawałby
     * „w toku”, a panel dopiero po B2bSyncRun::STALE_MINUTES pokazywał „brak postępu ponad 30 min”, bez prawdziwej
     * przyczyny. Shutdown domyka przebieg i konto komunikatem z błędem PHP i ostatnim produktem.
     * sweepStaleRuns zostaje zapasem na wypadek, gdy proces ginie bez szansy na shutdown (kill -9, restart serwera).
     *
     * @param  bool  $live  przez referencję: false = przebieg domknięty normalnie, shutdown nie ma czego sprzątać
     */
    private function closeRunOnFatalError(B2bAccount $account, B2bSyncProgress $progress, bool &$live): void
    {
        register_shutdown_function(function () use ($account, $progress, &$live): void {
            $error = error_get_last();
            if (! $live || ! self::isFatalError($error)) {
                return;
            }
            $this->failRunAfterFatal($account, $progress, $error);
        });
    }

    /**
     * Błąd, po którym PHP kończy proces — catch ani finally już się nie wykonają.
     *
     * @param  array<string, mixed>|null  $error  z error_get_last()
     */
    public static function isFatalError(?array $error): bool
    {
        return $error !== null && in_array($error['type'] ?? null, self::FATAL_ERRORS, true);
    }

    /**
     * Proces zatrzymany z zewnątrz (Ctrl+C, zamknięcie terminala, kill) — bez tego wpis przebiegu zostawał
     * „w toku”, a panel pokazywał „Zatrzymywanie…” aż do sprzątania po B2bSyncRun::STALE_MINUTES.
     * Pobranie uruchomione z panelu idzie w tle i sygnału nie dostaje.
     *
     * @param  bool  $live  przez referencję: false = przebieg domknięty, nie ma czego sprzątać
     */
    private function closeRunOnSignal(B2bAccount $account, B2bSyncProgress $progress, bool &$live): void
    {
        if (PHP_SAPI !== 'cli' || ! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $handler = function (int $signal) use ($account, $progress, &$live): void {
            if ($live) {
                $live = false;
                $this->cancelRunAfterSignal($account, $progress, $signal);
            }
            // kod wyjścia jak w powłoce: 128 + numer sygnału
            exit(128 + $signal);
        };
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, $handler);
        }
    }

    /**
     * Zatrzymanie z zewnątrz to nie błąd — przebieg kończy się jako „zatrzymany”, tak samo jak po „Zatrzymaj”
     * w panelu; zapisane do tej pory produkty i ceny zostają.
     */
    public function cancelRunAfterSignal(B2bAccount $account, B2bSyncProgress $progress, int $signal): void
    {
        // numery zamiast stałych SIG* — te istnieją tylko z rozszerzeniem pcntl (lokalnie na Windows go nie ma)
        $name = match ($signal) {
            2 => 'Ctrl+C',
            1 => 'zamknięcie terminala',
            15 => 'polecenie zatrzymania procesu',
            default => 'sygnał '.$signal,
        };
        $sku = $progress->run()->current_sku;
        $message = 'Zatrzymane — proces przerwany ('.$name.')'
            .($sku !== null ? ', ostatni produkt: '.$sku : ', przed pierwszym produktem')
            .'. Pobranie uruchomione z panelu działa w tle i nie ginie po zamknięciu terminala.';
        try {
            $progress->log('warn', $message);
            $progress->finish(B2bSyncRun::STATUS_CANCELLED, $message);
            $account->forceFill([
                'last_sync_status' => B2bSyncRun::STATUS_CANCELLED,
                'last_sync_finished_at' => now(),
                'last_sync_message' => mb_substr($message, 0, 2000),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('B2B: nie zapisano przebiegu przerwanego sygnałem', [
                'b2b_account_id' => $account->id,
                'signal' => $signal,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{type: int, message: string, file: string, line: int}  $error  z error_get_last()
     */
    public function failRunAfterFatal(B2bAccount $account, B2bSyncProgress $progress, array $error): void
    {
        // po wyczerpaniu pamięci nie ma z czego zapisać przebiegu
        if (function_exists('ini_set')) {
            ini_set('memory_limit', '-1');
        }
        $message = sprintf(
            'Przebieg przerwany błędem PHP: %s (%s:%d). Ostatni produkt: %s. Szczyt pamięci: %d MB.',
            $error['message'],
            basename($error['file']),
            $error['line'],
            $progress->run()->current_sku ?? 'brak',
            (int) round(memory_get_peak_usage(true) / 1024 / 1024),
        );
        try {
            $progress->log('error', $message);
            $progress->finish(B2bSyncRun::STATUS_FAILED, $message);
            $account->forceFill([
                'last_sync_status' => 'failed',
                'last_sync_finished_at' => now(),
                'last_sync_message' => mb_substr($message, 0, 2000),
            ])->save();
        } catch (Throwable $e) {
            // nie ma jak zapisać — zostaje sweepStaleRuns w b2b:sync-due
            Log::warning('B2B: nie zapisano przebiegu przerwanego błędem PHP', [
                'b2b_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Błąd zapisu wpisu w Cennikach nie zmienia wyniku przebiegu — produkty i ceny są już zapisane;
     * następny przebieg nadpisze wpis.
     */
    private function recordPriceList(PriceList $priceList, B2bAccount $account, B2bSyncProgress $progress): void
    {
        try {
            $this->priceLists->record($priceList, $account, $progress);
        } catch (Throwable $e) {
            $progress->log('warn', 'Nie zaktualizowano wpisu konta w Cennikach: '.$e->getMessage());
            Log::warning('B2B: nie zaktualizowano wpisu konta w Cennikach', [
                'b2b_account_id' => $account->id,
                'price_list_id' => $priceList->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function discardPriceList(PriceList $priceList, B2bAccount $account): void
    {
        try {
            $this->priceLists->discardNew($priceList, $account);
        } catch (Throwable $e) {
            Log::warning('B2B: nie usunięto pustego wpisu konta w Cennikach', [
                'b2b_account_id' => $account->id,
                'price_list_id' => $priceList->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Zajęcie konta jednym warunkowym UPDATE (harmonogram, „Sprawdź teraz” i CLI nie pobierają naraz tego samego
     * konta) i wpis przebiegu w tej samej transakcji — nie zostaje konto „running” bez przebiegu, którego
     * b2b:sync-due nie umiałby uznać za przerwany.
     */
    private function claim(B2bAccount $account, string $trigger): B2bSyncProgress
    {
        return DB::transaction(function () use ($account, $trigger): B2bSyncProgress {
            $claimed = B2bAccount::query()
                ->whereKey($account->id)
                ->where(static function ($query): void {
                    $query->whereNull('last_sync_status')->orWhere('last_sync_status', '<>', 'running');
                })
                ->update([
                    'last_sync_status' => 'running',
                    'last_sync_started_at' => now(),
                    'last_sync_finished_at' => null,
                    'last_sync_message' => null,
                    'sync_requested_at' => null,
                ]);
            if ($claimed === 0) {
                throw new RuntimeException(self::ALREADY_RUNNING);
            }
            $account->refresh();

            return B2bSyncProgress::start($account, $trigger);
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function summary(array $result): string
    {
        $variants = ($result['progress_unit'] ?? null) === B2bSyncRun::UNIT_VARIANTS;
        // przy wersjach total_remote to szacunek liczby znaków — pewna jest liczba wersji z listy dostawcy
        $text = sprintf(
            '%s: %d · sprawdzone: %d · nowe: %d · zaktualizowane: %d · bez zmian: %d · pominięte: %d · zmiany cen: %d · nowe opisy: %d · zdjęcia: %d · pliki: %d · karty ze sklepu: %d',
            $variants ? 'Wersji w B2B' : 'W B2B',
            $variants ? $result['progress_total'] : $result['total_remote'],
            $result['seen'],
            $result['created'],
            $result['updated'],
            $result['unchanged'],
            $result['skipped'],
            $result['prices_changed'],
            $result['descriptions'],
            $result['images'],
            $result['documents'] ?? 0,
            $result['shop_fields'] ?? 0,
        );
        if (($result['excluded'] ?? 0) > 0) {
            $text .= sprintf(' · wyłączone w oknie „Producenci”: %d', $result['excluded']);
        }
        if (($result['progress_unit'] ?? null) === B2bSyncRun::UNIT_VARIANTS) {
            $text .= sprintf(
                ' · wersje: %d/%d · wycofane wersje: %d',
                $result['processed'],
                $result['progress_total'],
                $result['variants_removed'],
            );
        }
        if ($result['errors'] !== []) {
            $text .= "\nPrzykładowe problemy: ".implode('; ', array_slice($result['errors'], 0, 3));
        }

        return mb_substr($text, 0, 2000);
    }
}
