<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jeden przebieg pobierania cennika z konta B2B: liczniki, bieżący produkt i dziennik (ostatnie wpisy).
 * updated_at to sygnał życia przebiegu.
 */
class B2bSyncRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_CLI = 'cli';

    /** Tyle ostatnich wpisów dziennika trzymamy przy przebiegu. */
    public const LOG_LIMIT = 200;

    /** Tyle zmian cen zapisujemy przy przebiegu (pełna historia jest w product_price_history). */
    public const PRICE_CHANGES_LIMIT = 1000;

    /** Przebieg „running” bez postępu dłużej niż tyle minut uznajemy za przerwany. */
    public const STALE_MINUTES = 30;

    /** Ostatnie uruchomienie harmonogramu (schedule:run) — panel pokazuje, czy cron serwera działa. */
    public const SCHEDULER_HEARTBEAT_KEY = 'b2b:scheduler_last_seen_at';

    protected $fillable = [
        'b2b_account_id',
        'status',
        'trigger',
        'started_at',
        'finished_at',
        'total',
        'processed',
        'created',
        'updated',
        'unchanged',
        'skipped',
        'prices_changed',
        'descriptions',
        'images',
        'current_sku',
        'message',
        'cancel_requested_at',
        'log',
        'price_changes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'total' => 'integer',
            'processed' => 'integer',
            'created' => 'integer',
            'updated' => 'integer',
            'unchanged' => 'integer',
            'skipped' => 'integer',
            'prices_changed' => 'integer',
            'descriptions' => 'integer',
            'images' => 'integer',
            'log' => 'array',
            'price_changes' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(B2bAccount::class, 'b2b_account_id');
    }
}
