<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rozmowa czatu: kanał (`channel`, z nazwą) albo rozmowa 1:1 (`direct`, klucz „mniejszeId:większeId”).
 * Kanał `everyone` („Ogólny”) obejmuje każdego z uprawnieniem `chat` — wiersze uczestników powstają leniwie.
 */
class ChatConversation extends Model
{
    public const TYPE_CHANNEL = 'channel';

    public const TYPE_DIRECT = 'direct';

    protected $fillable = [
        'type',
        'name',
        'everyone',
        'direct_key',
        'created_by',
        'last_message_id',
    ];

    protected function casts(): array
    {
        return [
            'everyone' => 'boolean',
            'last_message_id' => 'integer',
        ];
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    /** Kanał, do którego można dodawać osoby i z którego można wyjść (nie 1:1, nie „Ogólny”). */
    public function isOpenChannel(): bool
    {
        return $this->type === self::TYPE_CHANNEL && ! $this->everyone;
    }

    /** @return HasMany<ChatParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    /** @return BelongsTo<ChatMessage, $this> */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id');
    }
}
