<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestaProductMatch extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_REVIEW = 'review';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'product_id',
        'presta_id',
        'method',
        'score',
        'status',
        'presta_url',
        'presta_reference',
        'presta_name',
    ];

    protected function casts(): array
    {
        return [
            'presta_id' => 'integer',
            'score' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
