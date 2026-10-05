<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Adres w wysyłce oferty z wynikiem: wysłano, błąd adresu albo pominięty (po błędzie skrzynki nadawcy). */
class OfferRecipient extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'offer_id',
        'offer_send_id',
        'email',
        'status',
        'error',
        'message_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<OfferSend, $this> */
    public function send(): BelongsTo
    {
        return $this->belongsTo(OfferSend::class, 'offer_send_id');
    }
}
