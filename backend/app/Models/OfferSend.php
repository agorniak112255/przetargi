<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jedna wysyłka oferty: dokładny temat, HTML i tekst, które dostali klienci (zapisane przed wysłaniem), i wynik
 * dla każdego adresu. Oferta zmienia się dalej — ten zapis nie.
 */
class OfferSend extends Model
{
    protected $fillable = [
        'offer_id',
        'subject',
        'html',
        'text',
        // forma użyta w tej wysyłce (Offer::DELIVERIES) i PDF, który dostali klienci (dysk local; null = bez PDF)
        'delivery',
        'pdf_path',
    ];

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return HasMany<OfferRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(OfferRecipient::class)->orderBy('id');
    }
}
