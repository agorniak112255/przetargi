<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kliknięcie w link kampanii: „Zapytaj o ofertę” (offer) albo strona produktu (product). */
class CampaignClick extends Model
{
    public const KIND_OFFER = 'offer';

    public const KIND_PRODUCT = 'product';

    protected $fillable = [
        'campaign_id',
        'campaign_recipient_id',
        'campaign_item_id',
        'kind',
        'suspected_bot',
        'user_agent',
        'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'suspected_bot' => 'boolean',
            'clicked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CampaignRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }

    /** @return BelongsTo<CampaignItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(CampaignItem::class, 'campaign_item_id');
    }
}
