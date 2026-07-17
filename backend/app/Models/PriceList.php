<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceList extends Model
{
    protected $fillable = [
        'manufacturer',
        'version',
        'original_filename',
        'imported_by',
        'rows_total',
        'products_created',
        'products_updated',
        'prices_changed',
        'rows_skipped',
        'errors',
        'price_changes',
        'updated_products',
        'skipped_details',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'price_changes' => 'array',
            'updated_products' => 'array',
            'skipped_details' => 'array',
        ];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
