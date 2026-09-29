<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pozycja PZ z Comarch ERP XL (document_type/id/line = TrE_GIDTyp/GIDNumer/GIDLp). Zapis: ErpItemSync.
 */
class ErpItemPurchase extends Model
{
    protected $fillable = [
        'erp_item_id',
        'document_type',
        'document_id',
        'document_line',
        'document_state',
        'purchased_at',
        'supplier_xl_id',
        'supplier',
        'quantity',
        'document_unit',
        'net_value_pln',
        'unit_price_pln',
        'document_price',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'quantity' => 'decimal:4',
            'net_value_pln' => 'decimal:2',
            'unit_price_pln' => 'decimal:4',
            'document_price' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ErpItem::class, 'erp_item_id');
    }
}
