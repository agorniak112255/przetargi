<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Odpowiedź klienta na kampanię odczytana z nagłówków skrzynki handlowca (IMAP, tylko odczyt). */
class CampaignReply extends Model
{
    public const MATCHED_CODE = 'code';

    public const MATCHED_THREAD = 'thread';

    protected $fillable = [
        'campaign_id',
        'campaign_recipient_id',
        'user_id',
        'from_email',
        'from_name',
        'subject',
        'item_code',
        'matched_by',
        'message_id',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<CampaignRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'campaign_recipient_id');
    }
}
