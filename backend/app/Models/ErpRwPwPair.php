<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Para RW → PW z ERP XL (ten sam towar, ta sama ilość, PW do 30 dni po RW). Zapis: App\Services\Erp\ErpRwPwSync.
 */
class ErpRwPwPair extends Model
{
    protected $fillable = [
        'xl_gid',
        'erp_item_id',
        'rw_document_id', 'rw_number', 'rw_date', 'rw_warehouse', 'rw_quantity', 'rw_value', 'rw_operator', 'rw_approver',
        'rw_lot_at', 'rw_lot_age_months', 'rw_lot_avg_age_months', 'rw_lots', 'rw_lot_source', 'rw_lot_from_pw', 'rw_features',
        'pw_document_id', 'pw_number', 'pw_date', 'pw_warehouse', 'pw_quantity', 'pw_value', 'pw_operator', 'pw_approver',
        'pw_features',
        'same_feature',
        'rw_operator_name', 'rw_approver_name', 'rw_note', 'rw_foreign_number',
        'pw_operator_name', 'pw_approver_name', 'pw_note', 'pw_foreign_number',
        'gap_days',
        'same_value',
        'same_warehouse',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'xl_gid' => 'integer',
            'rw_date' => 'date',
            'pw_date' => 'date',
            'rw_quantity' => 'decimal:4',
            'pw_quantity' => 'decimal:4',
            'rw_value' => 'decimal:2',
            'pw_value' => 'decimal:2',
            'rw_lot_at' => 'date',
            'rw_lot_age_months' => 'integer',
            'rw_lot_avg_age_months' => 'decimal:1',
            'rw_lots' => 'integer',
            'rw_lot_from_pw' => 'boolean',
            'gap_days' => 'integer',
            'same_value' => 'boolean',
            'same_feature' => 'boolean',
            'same_warehouse' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ErpItem::class, 'erp_item_id');
    }
}
