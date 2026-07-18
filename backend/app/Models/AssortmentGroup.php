<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssortmentGroup extends Model
{
    public const GLOBAL_NAME = '(cały asortyment)';

    protected $fillable = [
        'manufacturer',
        'name',
        'discount_percent',
        'is_global',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'is_global' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
