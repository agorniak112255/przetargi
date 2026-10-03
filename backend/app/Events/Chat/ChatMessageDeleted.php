<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Autor usunął wiadomość — otwarte rozmowy u innych pokazują „wiadomość usunięta” od razu, a liczniki
 * nieprzeczytanych się przeliczają (usunięta nie jest liczona). Sam sygnał, bez treści.
 */
final class ChatMessageDeleted implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    /** @param  list<int>  $userIds */
    public function __construct(
        public readonly array $userIds,
        public readonly int $conversationId,
        public readonly int $messageId,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(static fn (int $id): PrivateChannel => new PrivateChannel('user.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.deleted';
    }

    /** @return array{conversation_id: int, message_id: int} */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'message_id' => $this->messageId];
    }

    public function broadcastWhen(): bool
    {
        return $this->userIds !== [];
    }
}
