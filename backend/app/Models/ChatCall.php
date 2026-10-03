<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rozmowa głosowa albo wideo w rozmowie czatu (pokój LiveKit `call-{id}`). Statusy `ended` i `missed` są końcowe:
 * koniec bez odebrania (`answered_at` puste) to `missed`, po odebraniu — `ended`.
 */
class ChatCall extends Model
{
    public const KIND_AUDIO = 'audio';

    public const KIND_VIDEO = 'video';

    public const STATUS_RINGING = 'ringing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_MISSED = 'missed';

    public const ROOM_PREFIX = 'call-';

    protected $fillable = [
        'conversation_id',
        'started_by',
        'kind',
        'status',
        'message_id',
        'started_at',
        'answered_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'started_by' => 'integer',
            'message_id' => 'integer',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function roomName(): string
    {
        return self::ROOM_PREFIX.$this->id;
    }

    /** Id rozmowy z nazwy pokoju `call-{id}`; null dla obcego pokoju. */
    public static function idFromRoom(string $room): ?int
    {
        return preg_match('/^'.preg_quote(self::ROOM_PREFIX, '/').'([1-9]\d{0,18})$/', $room, $m) === 1 ? (int) $m[1] : null;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_ENDED, self::STATUS_MISSED], true);
    }

    /** Czas rozmowy od odebrania do końca; null, dopóki trwa albo gdy nikt nie odebrał. */
    public function durationSeconds(): ?int
    {
        if ($this->answered_at === null || $this->ended_at === null) {
            return null;
        }

        return max(0, (int) $this->answered_at->diffInSeconds($this->ended_at, true));
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return HasMany<ChatCallMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ChatCallMember::class, 'call_id');
    }
}
