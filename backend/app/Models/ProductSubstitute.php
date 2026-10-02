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
        'source',
        'evidence',
        'generated_at',
        'decision_note',
    ];

    public const SOURCE_MANUAL = 'reczny';

    /** Propozycja polecenia substitutes:propose — dowody porównania w `evidence`. */
    public const SOURCE_AUTO = 'automat';

    protected function casts(): array
    {
        return [
            'norms_ok' => 'boolean',
            'certs_ok' => 'boolean',
            'evidence' => 'array',
            'generated_at' => 'datetime',
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
