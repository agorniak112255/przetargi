<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Założenie kanału, dodanie osób albo wyjście — klienci uczestników odświeżają listę rozmów. */
final class ChatConversationChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    /** @param  list<int>  $userIds */
    public function __construct(
        public readonly array $userIds,
        public readonly int $conversationId,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(static fn (int $id): PrivateChannel => new PrivateChannel('user.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.conversation';
    }

    /** @return array{conversation_id: int} */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }

    public function broadcastWhen(): bool
    {
        return $this->userIds !== [];
    }
}
