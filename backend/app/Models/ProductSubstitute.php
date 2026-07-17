<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSubstitute extends Model
{
    protected $fillable = [
        'main_product_id',
        'substitute_product_id',
        'type',
        'match_percent',
        'norms_ok',
        'certs_ok',
        'reason',
        'approval_status',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'norms_ok' => 'boolean',
            'certs_ok' => 'boolean',
        ];
    }

    public function mainProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'main_product_id');
    }

    public function substituteProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'substitute_product_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
