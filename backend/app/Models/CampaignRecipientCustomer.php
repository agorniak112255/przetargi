<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Odbiorca kampanii ↔ klient ERP XL, zamrożone przy starcie wysyłki i przy dopisaniu odbiorców
 * (raport „Wynik kampanii”). matched_by: direct | email; cards_count — na ilu kartach był adres.
 */
class CampaignRecipientCustomer extends Model
{
    public const MATCHED_DIRECT = 'direct';

    public const MATCHED_EMAIL = 'email';

    protected $fillable = [
        'campaign_id',
        'campaign_recipient_id',
        'erp_customer_id',
        'matched_by',
        'cards_count',
    ];

    protected function casts(): array
    {
        return [
            'cards_count' => 'integer',
        ];
    }

    /** @return BelongsTo<CampaignRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    /** @return BelongsTo<ErpCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ErpCustomer::class, 'erp_customer_id');
    }
}
