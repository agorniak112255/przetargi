<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wiersz oferty przeglądu (Offer kind = inspection): co, ile sztuk, kiedy ostatnio, kiedy termin — bez ceny (oferta
 * zaczepna). Wypełniany z inspection_due przy przygotowaniu oferty; handlowiec może poprawić ilość, termin i uwagę.
 */
class OfferInspectionLine extends Model
{
    protected $fillable = [
        'offer_id',
        'position',
        'inspection_position_id',
        'xl_gid',
        'name',
        'unit',
        'quantity',
        'last_on',
        'due_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'inspection_position_id' => 'integer',
            'xl_gid' => 'integer',
            'quantity' => 'decimal:3',
            'last_on' => 'date',
            'due_on' => 'date',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
