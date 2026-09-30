<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Odbiorca kampanii ustalony przy starcie wysyłki; token (losowy) prowadzi do strony wypisu. */
class CampaignRecipient extends Model
{
    public const STATUS_PENDING = 'pending';

    /** Zarezerwowany do wysyłki w bieżącym przebiegu; po przerwaniu > 15 min → failed, nigdy ponownie. */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const SOURCE_LIST = 'list';

    public const SOURCE_XL = 'xl';

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'erp_customer_id',
        'email',
        'name',
        'source',
        'token',
        'status',
        'attempts',
        'error',
        'sent_at',
        'message_id',
        'unsubscribed_at',
        'first_clicked_at',
        'clicks',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'first_clicked_at' => 'datetime',
            'clicks' => 'integer',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<ErpCustomer, $this> */
    public function erpCustomer(): BelongsTo
    {
        return $this->belongsTo(ErpCustomer::class);
    }
}
