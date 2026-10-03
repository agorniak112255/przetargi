<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Osoba w rozmowie głosowej/wideo. `connecting` ustawia POST /join (dostała token), `joined` wyłącznie webhook
 * LiveKit albo uzgodnienie z listą uczestników pokoju. `livekit_sid` = sesja, która się połączyła.
 */
class ChatCallMember extends Model
{
    public const STATE_INVITED = 'invited';

    public const STATE_CONNECTING = 'connecting';

    public const STATE_DECLINED = 'declined';

    public const STATE_JOINED = 'joined';

    public const STATE_LEFT = 'left';

    protected $fillable = [
        'call_id',
        'user_id',
        'state',
        'livekit_sid',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'call_id' => 'integer',
            'user_id' => 'integer',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChatCall, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(ChatCall::class, 'call_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
