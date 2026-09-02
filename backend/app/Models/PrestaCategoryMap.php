<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestaCategoryMap extends Model
{
    protected $fillable = [
        'local_category',
        'presta_id',
    ];

    protected function casts(): array
    {
        return [
            'presta_id' => 'integer',
        ];
    }

    public function prestaCategory(): BelongsTo
    {
        return $this->belongsTo(PrestaCategory::class, 'presta_id', 'presta_id');
    }
}
