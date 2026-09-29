<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prośba „Otwórz w Thunderbirdzie” z okna oferty dla klienta. Treść (HTML i tekst) składa aplikacja w przeglądarce;
 * dodatek do Thunderbirda odbiera ją przy pytaniu o kolejkę (GET /inquiries/queued?with_offers=1), podejmuje
 * (claimed_at) i otwiera nowego maila. Wiersz zostaje po podjęciu — to ślad, kto i kiedy wysłał jaką ofertę do poczty.
 */
class OfferComposeRequest extends Model
{
    /**
     * Po tylu minutach niepodjęta prośba przepada: aplikacja czeka na Thunderbirda ok. 40 s, a oferta otwarta
     * godzinę później, przy innej pracy, byłaby zaskoczeniem.
     */
    public const PENDING_MINUTES = 15;

    protected $fillable = [
        'user_id',
        'product_id',
        'subject',
        'body_html',
        'body_text',
        'requested_at',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Prośby użytkownika czekające na dodatek.
     *
     * @param  Builder<OfferComposeRequest>  $query
     * @return Builder<OfferComposeRequest>
     */
    public function scopePendingFor(Builder $query, User $user): Builder
    {
        return $query
            ->where('user_id', $user->id)
            ->whereNull('claimed_at')
            ->where('requested_at', '>=', now()->subMinutes(self::PENDING_MINUTES));
    }
}
