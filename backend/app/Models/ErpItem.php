<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Towar z Comarch ERP XL — kopia dosłowna ze stanami i dostawcami. Zapis: App\Services\Erp\ErpItemSync.
 */
class ErpItem extends Model
{
    protected $fillable = [
        'xl_gid',
        'code',
        'name',
        'name1',
        'ean',
        'unit',
        'archived',
        'stock_trade',
        'stock_total',
        'stock_by_warehouse',
        'suppliers',
        'last_purchase_at',
        'last_sale_at',
        'synced_at',
        'stock_synced_at',
        'removed_at',
        'match_outcome',
        'match_value',
        'last_supplier',
        'search_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'xl_gid' => 'integer',
            'archived' => 'boolean',
            'stock_trade' => 'decimal:4',
            'stock_total' => 'decimal:4',
            'stock_by_warehouse' => 'array',
            'suppliers' => 'array',
            'last_purchase_at' => 'date',
            'last_sale_at' => 'date',
            'synced_at' => 'datetime',
            'stock_synced_at' => 'datetime',
            'removed_at' => 'datetime',
            'search_checked_at' => 'datetime',
        ];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(ErpItemPurchase::class)->orderByDesc('purchased_at')->orderByDesc('document_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ErpItemLink::class);
    }
}
