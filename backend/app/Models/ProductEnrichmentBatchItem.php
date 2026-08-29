<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductEnrichmentBatchItem extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_MANUAL = 'manual';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    /** Kolejność w logu: najpierw problemy, na końcu zakończone. */
    public const STATUS_SORT = [
        self::STATUS_FAILED => 0,
        self::STATUS_MANUAL => 1,
        self::STATUS_RUNNING => 2,
        self::STATUS_QUEUED => 3,
        self::STATUS_DONE => 4,
        self::STATUS_SKIPPED => 5,
        self::STATUS_CANCELLED => 6,
    ];

    protected $fillable = [
        'batch_id',
        'product_id',
        'sku',
        'name',
        'status',
        'message',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductEnrichmentBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
