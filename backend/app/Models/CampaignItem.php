<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pozycja kampanii: towar XL (zwykle zalegający) z opcjonalną kartą katalogu jako źródłem zdjęcia i nazwy.
 * snap_* = co dostał klient w chwili startu wysyłki; stock_after_* = stan po 7/30 dniach (campaigns:stock-followup).
 */
class CampaignItem extends Model
{
    protected $fillable = [
        'campaign_id',
        'position',
        'erp_item_id',
        'product_id',
        'promo_price_net',
        'price_before_net',
        'note',
        'snap_name',
        'snap_code',
        'snap_unit',
        'snap_price',
        'snap_stock',
        'snap_stock_at',
        'snap_image_url',
        'stock_after_7d',
        'stock_after_30d',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'promo_price_net' => 'decimal:2',
            'price_before_net' => 'decimal:2',
            'snap_price' => 'decimal:2',
            'snap_stock' => 'decimal:3',
            'snap_stock_at' => 'date',
            'stock_after_7d' => 'decimal:3',
            'stock_after_30d' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<ErpItem, $this> */
    public function erpItem(): BelongsTo
    {
        return $this->belongsTo(ErpItem::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
