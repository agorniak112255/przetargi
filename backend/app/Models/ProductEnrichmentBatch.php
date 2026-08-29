<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class ProductEnrichmentBatch extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const SCOPE_PRODUCT = 'product';

    public const SCOPE_PRICE_LIST = 'price_list';

    public const SCOPE_PRODUCTS = 'products';

    protected $fillable = [
        'scope',
        'scope_id',
        'total',
        'done',
        'failed',
        'status',
        'created_by',
        'force',
        'current_sku',
        'current_name',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'done' => 'integer',
            'failed' => 'integer',
            'force' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductEnrichmentBatchItem::class, 'batch_id');
    }

    public const HALT_CACHE_KEY = 'enrichment:halt-all';

    public static function cancelCacheKey(int $batchId): string
    {
        return 'enrich_batch_cancelled:'.$batchId;
    }

    public static function haltAllWorkers(): void
    {
        Cache::put(self::HALT_CACHE_KEY, now()->getTimestamp(), now()->addHours(6));
    }

    public function isCancelled(): bool
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return true;
        }

        if (Cache::has(self::cancelCacheKey((int) $this->id))) {
            return true;
        }

        $haltAt = Cache::get(self::HALT_CACHE_KEY);
        if (is_numeric($haltAt) && $this->created_at !== null && $this->created_at->getTimestamp() < (int) $haltAt) {
            return true;
        }

        return false;
    }

    public function markCancelledFlag(): void
    {
        Cache::put(self::cancelCacheKey((int) $this->id), true, now()->addDay());
    }

    public function refreshStatus(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $processed = $this->done + $this->failed;
        if ($processed >= $this->total && $this->total > 0) {
            $this->status = $this->failed >= $this->total && $this->done === 0
                ? self::STATUS_FAILED
                : self::STATUS_DONE;
            $this->save();

            return;
        }

        if ($this->status === self::STATUS_QUEUED && $processed > 0) {
            $this->status = self::STATUS_RUNNING;
            $this->save();
        }
    }
}
