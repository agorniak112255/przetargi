<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAccessory extends Model
{
    public const SOURCE_PRESTA = 'presta';

    public const SOURCE_ENRICHMENT = 'enrichment';

    protected $fillable = [
        'product_id',
        'related_product_id',
        'source',
        'link_key',
        'presta_parent_id',
        'presta_related_id',
        'related_sku',
        'related_ean',
        'related_name',
        'related_manufacturer',
        'score',
        'method',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'related_product_id' => 'integer',
            'presta_parent_id' => 'integer',
            'presta_related_id' => 'integer',
            'score' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }
}
