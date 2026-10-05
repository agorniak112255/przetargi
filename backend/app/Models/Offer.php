<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Oferta dla klienta: wybrane produkty (towary XL albo karty) z ceną netto, wysyłana ze skrzynki autora — osobny mail
 * na każdy adres — albo kopiowana do Thunderbirda. Bez statusu: zawsze edytowalna; co dostał klient, mówi zapis
 * wysyłki (OfferSend). Widzi i zmienia ją tylko autor.
 */
class Offer extends Model
{
    protected $fillable = [
        'user_id',
        'code',
        'subject',
        'intro',
        'layout',
        'valid_until',
        'last_sent_at',
        'last_copied_at',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'last_sent_at' => 'datetime',
            'last_copied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // kod w temacie „Zapytaj o ofertę” wiąże pytanie klienta z ofertą (jak K-0001 kampanii)
        static::created(static function (Offer $offer): void {
            if ($offer->code === null) {
                $offer->forceFill(['code' => 'OF-'.str_pad((string) $offer->id, 4, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OfferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<OfferSend, $this> najnowsza wysyłka pierwsza */
    public function sends(): HasMany
    {
        return $this->hasMany(OfferSend::class)->latest()->orderByDesc('id');
    }

    /** @return HasMany<OfferRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(OfferRecipient::class);
    }
}
