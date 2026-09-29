<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Powiązanie towaru ERP XL z kartą. auto/suggested zakłada i odświeża ErpItemMatcher; confirmed/rejected to decyzje
 * człowieka — automat ich nie zmienia.
 */
class ErpItemLink extends Model
{
    /** Pewny kod + dowód (dostawca = producent, marka albo wspólne słowo nazwy). */
    public const STATUS_AUTO = 'auto';

    /** Kod wskazuje kartę, ale brak dowodu albo kilka kart — do decyzji człowieka. */
    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_AUTO, self::STATUS_SUGGESTED, self::STATUS_CONFIRMED, self::STATUS_REJECTED];

    /** Kod z Twr_Nazwa. */
    public const METHOD_NAME = 'name';

    /** Kod z Twr_Nazwa1. */
    public const METHOD_NAME1 = 'name1';

    /** Końcówka kodu towaru XL po literowym przedrostku (SOK9301145 → 9301145). */
    public const METHOD_XL_CODE = 'xl_code';

    public const METHOD_MANUAL = 'manual';

    protected $fillable = [
        'erp_item_id',
        'product_id',
        'status',
        'method',
        'matched_value',
        'matched_code',
        'evidence',
        'decided_by',
        'decided_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'decided_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ErpItem::class, 'erp_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
