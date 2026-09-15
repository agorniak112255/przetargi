<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produkt w B2B dostawcy ↔ karta katalogu (źródło danych karty).
 */
class B2bProductLink extends Model
{
    protected $fillable = [
        'b2b_account_id',
        'remote_id',
        'product_id',
        'remote_sku',
        'remote_name',
        'description_hash',
        'source_description_hash',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(B2bAccount::class, 'b2b_account_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
