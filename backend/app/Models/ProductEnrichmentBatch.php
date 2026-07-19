<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductEnrichmentBatch extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

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

    public function refreshStatus(): void
    {
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
