<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pozycja oferty: towar XL i/lub karta katalogu z ceną netto (null = do uzupełnienia). Nazwa, zdjęcie, koszt i cena
 * sugerowana liczone na bieżąco (App\Services\Offers\OfferItemPresenter) — migawek nie ma.
 */
class OfferItem extends Model
{
    protected $fillable = [
        'offer_id',
        'position',
        'erp_item_id',
        'product_id',
        'price_net',
        'note',
        'description',
        'link_url',
        'link_label',
        'link_color',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'price_net' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
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
