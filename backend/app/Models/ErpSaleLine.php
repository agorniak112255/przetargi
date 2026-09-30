<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pozycja faktury (FS) albo paragonu (PA) z ERP XL z towarem kampanii — do wyniku „kupili odbiorcy kampanii”.
 * Zapis: App\Services\Erp\ErpCampaignSalesSync.
 */
class ErpSaleLine extends Model
{
    protected $fillable = [
        'document_type',
        'document_id',
        'line',
        'document_number',
        'sold_at',
        'customer_xl_gid',
        'erp_customer_id',
        'erp_item_id',
        'quantity',
        'net_value',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => 'integer',
            'document_id' => 'integer',
            'line' => 'integer',
            'sold_at' => 'date',
            'customer_xl_gid' => 'integer',
            'quantity' => 'decimal:3',
            'net_value' => 'decimal:2',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ErpCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ErpCustomer::class, 'erp_customer_id');
    }

    /** @return BelongsTo<ErpItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ErpItem::class, 'erp_item_id');
    }
}
