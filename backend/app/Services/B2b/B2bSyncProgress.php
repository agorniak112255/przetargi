<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;

/**
 * Postęp przebiegu w b2b_sync_runs. Zapis do bazy co kilka sekund / kilka produktów, nie przy każdym
 * produkcie (pełny cennik to tysiące pozycji); przy tym samym zapisie sprawdzamy prośbę o zatrzymanie.
 */
final class B2bSyncProgress
{
    private const FLUSH_EVERY_PRODUCTS = 10;

    private const FLUSH_EVERY_SECONDS = 2.0;

    private const COUNTERS = ['processed', 'created', 'updated', 'unchanged', 'skipped', 'prices_changed', 'descriptions', 'images'];

    /** @var list<array{at: string, level: string, text: string}> */
    private array $log = [];

    /** @var list<array<string, mixed>> */
    private array $priceChanges = [];

    /**
     * Szczegóły ostatniego przebiegu dla wpisu konta w Cennikach (B2bAccountPriceList) — tylko w pamięci.
     *
     * @var list<array<string, mixed>>
     */
    private array $updatedProducts = [];

    /** @var list<array<string, mixed>> */
    private array $skippedDetails = [];

    /** @var list<string> */
    private array $errors = [];

    private int $sinceFlush = 0;

    private float $lastFlushAt;

    private bool $cancelRequested = false;

    private bool $advanced = false;

    private function __construct(private readonly B2bSyncRun $run)
    {
        $this->lastFlushAt = microtime(true);
    }

    public static function start(B2bAccount $account, string $trigger): self
    {
        return new self(B2bSyncRun::query()->create([
            'b2b_account_id' => $account->id,
            'status' => B2bSyncRun::STATUS_RUNNING,
            'trigger' => $trigger,
            'progress_unit' => B2bSyncRun::UNIT_PRODUCTS,
            'started_at' => now(),
            'log' => [],
            'price_changes' => [],
        ]));
    }

    public function run(): B2bSyncRun
    {
        return $this->run;
    }

    public function log(string $level, string $text): void
    {
        $this->log[] = [
            'at' => now()->toIso8601String(),
            'level' => $level,
            'text' => mb_substr($text, 0, 1000),
        ];
        if (count($this->log) > B2bSyncRun::LOG_LIMIT) {
            $this->log = array_slice($this->log, -B2bSyncRun::LOG_LIMIT);
        }
    }

    /**
     * Pierwsze PRICE_CHANGES_LIMIT zmian w kolejności wystąpienia; licznik prices_changed liczy wszystkie.
     *
     * @param  array<string, mixed>  $change
     */
    public function priceChange(array $change): void
    {
        if (count($this->priceChanges) < B2bSyncRun::PRICE_CHANGES_LIMIT) {
            $this->priceChanges[] = $change;
        }
    }

    /** @return list<array<string, mixed>> */
    public function priceChanges(): array
    {
        return $this->priceChanges;
    }

    /**
     * @param  array<string, mixed>  $summary  kształt PriceListImportService::summarizeUpdate
     */
    public function updatedProduct(array $summary): void
    {
        if (count($this->updatedProducts) < B2bAccountPriceList::UPDATED_PRODUCTS_LIMIT) {
            $this->updatedProducts[] = $summary;
        }
    }

    /** @return list<array<string, mixed>> */
    public function updatedProducts(): array
    {
        return $this->updatedProducts;
    }

    /**
     * @param  array{reason: string, row: int|null, sheet: string|null, sku: string|null, name: string|null}  $detail
     */
    public function skipped(array $detail): void
    {
        if (count($this->skippedDetails) < B2bAccountPriceList::SKIPPED_DETAILS_LIMIT) {
            $this->skippedDetails[] = $detail;
        }
    }

    /** @return list<array<string, mixed>> */
    public function skippedDetails(): array
    {
        return $this->skippedDetails;
    }

    public function error(string $message): void
    {
        if (count($this->errors) < B2bAccountPriceList::ERRORS_LIMIT) {
            $this->errors[] = mb_substr($message, 0, 1000);
        }
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function setTotal(int $total): void
    {
        $this->run->total = $total;
    }

    /** B2bSyncRun::UNIT_* — w czym liczone są total i processed (zapis przy najbliższym flush). */
    public function setUnit(string $unit): void
    {
        $this->run->progress_unit = $unit;
    }

    /**
     * Po każdym produkcie; zapisuje od razu pierwszy (panel szybko zna liczbę produktów), potem z przerwami.
     *
     * @param  array<string, int>  $counters
     */
    public function advance(string $sku, array $counters): void
    {
        $this->run->current_sku = mb_substr($sku, 0, 255);
        foreach (self::COUNTERS as $key) {
            if (isset($counters[$key])) {
                $this->run->{$key} = $counters[$key];
            }
        }
        $first = ! $this->advanced;
        $this->advanced = true;
        $this->sinceFlush++;

        // „pierwszy” = pierwsze wywołanie, nie processed === 1: przy wersjach processed skacze o liczbę wersji znaku
        if ($first
            || $this->sinceFlush >= self::FLUSH_EVERY_PRODUCTS
            || microtime(true) - $this->lastFlushAt >= self::FLUSH_EVERY_SECONDS) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->run->forceFill([
            'log' => $this->log,
            'price_changes' => $this->priceChanges,
            'updated_at' => now(),
        ])->save();
        $this->cancelRequested = B2bSyncRun::query()
            ->whereKey($this->run->id)
            ->whereNotNull('cancel_requested_at')
            ->exists();
        $this->sinceFlush = 0;
        $this->lastFlushAt = microtime(true);
    }

    /** Stan z ostatniego zapisu — bez dodatkowego zapytania. */
    public function cancelRequested(): bool
    {
        return $this->cancelRequested;
    }

    public function finish(string $status, ?string $message): void
    {
        $this->run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'current_sku' => null,
            'message' => $message !== null ? mb_substr($message, 0, 2000) : null,
        ]);
        $this->flush();
    }
}
