<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public const ENRICHMENT_NONE = 'none';

    public const ENRICHMENT_QUEUED = 'queued';

    public const ENRICHMENT_RUNNING = 'running';

    public const ENRICHMENT_DONE = 'done';

    public const ENRICHMENT_FAILED = 'failed';

    protected $fillable = [
        'sku',
        'name',
        'manufacturer',
        'ean',
        'category',
        'assortment_group_id',
        'description',
        'enrichment_status',
        'enriched_at',
        'enrichment_error',
        'enrichment_payload',
        'norms',
        'catalog_price_net',
        'discount_percent',
        'purchase_price',
        'currency',
        'stock',
        'pack_qty',
        'packaging',
    ];

    protected function casts(): array
    {
        return [
            'catalog_price_net' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'pack_qty' => 'integer',
            'enriched_at' => 'datetime',
            'enrichment_payload' => 'array',
        ];
    }

    public function assortmentGroup(): BelongsTo
    {
        return $this->belongsTo(AssortmentGroup::class);
    }

    public function substitutes(): HasMany
    {
        return $this->hasMany(ProductSubstitute::class, 'main_product_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProductDocument::class)->orderBy('sort_order');
    }
}
