<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'manufacturer',
        'ean',
        'category',
        'description',
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
        ];
    }

    public function substitutes(): HasMany
    {
        return $this->hasMany(ProductSubstitute::class, 'main_product_id');
    }
}
