<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uczestnik rozmowy czatu. `last_read_message_id` tylko rośnie — nieprzeczytane to wiadomości innych osób
 * o większym id (NULL = nic nie przeczytane).
 */
class ChatParticipant extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_id',
        'last_read_message_id',
    ];

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'user_id' => 'integer',
            'last_read_message_id' => 'integer',
        ];
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
