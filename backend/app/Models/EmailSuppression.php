<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Adres wypisany z mailingu — pomijany we wszystkich kampaniach wszystkich nadawców. */
class EmailSuppression extends Model
{
    public const REASON_UNSUBSCRIBE = 'unsubscribe';

    public const REASON_BOUNCE = 'bounce';

    public const REASON_MANUAL = 'manual';

    protected $fillable = [
        'email',
        'reason',
        'campaign_id',
        'note',
        'created_by',
    ];

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
