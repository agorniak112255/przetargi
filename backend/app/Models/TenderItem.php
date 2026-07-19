<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderItem extends Model
{
    protected $fillable = [
        'tender_id',
        'line_no',
        'requirement',
        'main_product_id',
        'ai_match_percent',
        'ai_match_reasons',
        'match_source',
        'quantity',
        'offer_price',
        'margin_percent',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'offer_price' => 'decimal:2',
            'margin_percent' => 'decimal:2',
            'ai_match_reasons' => 'array',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function mainProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'main_product_id');
    }
}
