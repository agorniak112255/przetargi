<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Towar XL, który kontrahent kupował (FS/PA i WZ z 24 mies.): data ostatniego wydania, liczba dokumentów (faktura do WZ = jeden), ilość. */
class ErpCustomerItem extends Model
{
    protected $fillable = [
        'erp_customer_id',
        'erp_item_id',
        'last_sale_at',
        'documents',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'last_sale_at' => 'date',
            'documents' => 'integer',
            'quantity' => 'decimal:3',
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
