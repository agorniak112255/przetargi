<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\B2bAccount;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bSizePriceMerger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * „Scal rozmiary” z panelu (Cenniki → B2B, 28.09.2026): podgląd albo scalanie kart rozbitych według ceny rozmiaru
 * w tle, porcjami po BUDGET_SECONDS (worker kolejki default ma limit 180 s, a lista Mascot to 2557 wyrobów). Stan w
 * cache (state()), porcja kończy się zapisem przesunięcia i zleceniem następnej z tym samym znacznikiem — nowe
 * uruchomienie (inny znacznik) unieważnia stare porcje. Lista musi pochodzić z tego samego przebiegu co na starcie
 * (przesunięcie liczy się w niej); nowy przebieg w trakcie = błąd z prośbą o ponowne uruchomienie.
 */
class MergeB2bSizePricesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 170;

    public const QUEUE = 'default';

    private const BUDGET_SECONDS = 120;

    private const LINES_KEPT = 300;

    private const TTL_SECONDS = 14 * 24 * 3600;

    /** Zadanie „w kolejce/trwa” bez znaku życia dłużej niż tyle minut uznajemy za przerwane. */
    public const STALE_MINUTES = 30;

    public function __construct(public int $accountId, public string $token)
    {
        $this->onQueue(self::QUEUE);
    }

    public static function cacheKey(int $accountId): string
    {
        return 'b2b-size-merge:'.$accountId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function state(int $accountId): ?array
    {
        $state = Cache::get(self::cacheKey($accountId));

        return is_array($state) ? $state : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveState(int $accountId, array $state): void
    {
        Cache::put(self::cacheKey($accountId), [...$state, 'updated_at' => now()->toIso8601String()], self::TTL_SECONDS);
    }

    public function handle(B2bSizePriceMerger $merger): void
    {
        B2bAccountSyncRunner::raiseMemoryLimit();
        $state = self::state($this->accountId);
        if ($state === null || ($state['token'] ?? null) !== $this->token || ! in_array($state['status'] ?? null, ['queued', 'running'], true)) {
            return;
        }
        $account = B2bAccount::query()->find($this->accountId);
        if ($account === null) {
            $this->finish($state, 'failed', 'Konto B2B zostało usunięte.');

            return;
        }
        $spread = $merger->spread($account);
        if ($spread['reason'] !== null || $spread['run']?->id !== ($state['run_id'] ?? null)) {
            $this->finish($state, 'failed', $spread['reason'] !== null
                ? ucfirst($spread['reason']).'.'
                : 'W trakcie scalania skończył się nowy przebieg synchronizacji — lista się zmieniła, uruchom scalanie ponownie.');

            return;
        }

        $state['status'] = 'running';
        self::saveState($this->accountId, $state);
        $apply = ($state['mode'] ?? 'preview') === 'apply';
        $result = $merger->process(
            $account,
            $spread['groups'],
            (int) ($state['processed'] ?? 0),
            $apply,
            (bool) ($state['with_tenders'] ?? false),
            isset($state['limit']) ? (int) $state['limit'] : null,
            (int) ($state['to_merge'] ?? 0),
            $apply ? (string) $state['backup_path'] : null,
            microtime(true) + self::BUDGET_SECONDS,
        );

        foreach (['to_merge', 'merged', 'sizes', 'tenders', 'sku_renamed'] as $key) {
            $state[$key] = (int) ($state[$key] ?? 0) + $result[$key];
        }
        $skipped = is_array($state['skipped'] ?? null) ? $state['skipped'] : [];
        foreach ($result['skipped'] as $reason => $count) {
            $skipped[$reason] = ($skipped[$reason] ?? 0) + $count;
        }
        $state['skipped'] = $skipped;
        $state['lines'] = array_slice([...(is_array($state['lines'] ?? null) ? $state['lines'] : []), ...$result['lines']], -self::LINES_KEPT);
        $state['processed'] = $result['offset'];

        if ($result['stop'] !== null) {
            $this->finish($state, 'failed', $result['stop']);

            return;
        }
        if ($result['done']) {
            $this->finish($state, 'done', null);

            return;
        }
        self::saveState($this->accountId, $state);
        self::dispatch($this->accountId, $this->token);
    }

    public function failed(?Throwable $e): void
    {
        $state = self::state($this->accountId);
        if ($state !== null && ($state['token'] ?? null) === $this->token) {
            $this->finish($state, 'failed', 'Zadanie w tle przerwane: '.($e?->getMessage() ?? 'nieznany błąd'));
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function finish(array $state, string $status, ?string $error): void
    {
        self::saveState($this->accountId, [...$state, 'status' => $status, 'error' => $error, 'finished_at' => now()->toIso8601String()]);
    }
}
