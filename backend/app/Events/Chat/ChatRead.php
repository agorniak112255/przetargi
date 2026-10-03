<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Przeczytanie rozmowy — tylko do czytającego, żeby jego inne urządzenia (dodatek, druga karta) zgasiły licznik. */
final class ChatRead implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    public function __construct(
        public readonly int $userId,
        public readonly int $conversationId,
        public readonly ?int $lastReadMessageId,
        public readonly int $unreadTotal,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'chat.read';
    }

    /** @return array{conversation_id: int, last_read_message_id: int|null, unread_total: int} */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'last_read_message_id' => $this->lastReadMessageId,
            'unread_total' => $this->unreadTotal,
        ];
    }
}
