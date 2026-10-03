<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Oferta innej firmy w części zamówienia (z informacji z otwarcia ofert) — cena jak w źródle, bez przeliczania.
 */
class TenderLotOffer extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BZP = 'bzp';

    public const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_BZP];

    protected $fillable = [
        'tender_lot_id',
        'competitor_id',
        'price',
        'currency',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(TenderLot::class, 'tender_lot_id');
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
