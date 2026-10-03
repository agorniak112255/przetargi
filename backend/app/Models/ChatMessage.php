<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wiadomość czatu. `kind`: text, link (meta.link — zapytanie albo przetarg), mail (meta.mail — przekazany mail),
 * system. `user_id` NULL przy kind ≠ system znaczy „konto usunięte”. Usunięcie wiadomości czyści body i meta
 * i ustawia `deleted_at` — wiersz zostaje, żeby rozmowa nie traciła ciągłości.
 */
class ChatMessage extends Model
{
    public const KIND_TEXT = 'text';

    public const KIND_LINK = 'link';

    public const KIND_MAIL = 'mail';

    public const KIND_SYSTEM = 'system';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'kind',
        'body',
        'meta',
        'client_uuid',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'user_id' => 'integer',
            'meta' => 'array',
            'deleted_at' => 'datetime',
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
