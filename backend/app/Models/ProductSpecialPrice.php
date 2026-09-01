<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpecialPrice extends Model
{
    protected $fillable = [
        'product_id',
        'client_id',
        'client_name',
        'price',
        'currency',
        'valid_from',
        'contract_ref',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'valid_from' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
