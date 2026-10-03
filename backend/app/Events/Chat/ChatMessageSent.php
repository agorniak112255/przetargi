<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Sygnał „nowa wiadomość” do każdego uczestnika rozmowy (także nadawcy — ma kilka urządzeń). Niesie tylko skrót
 * (≤ 300 znaków): pełną treść klient dociąga z API — zdarzenie Reverb ma limit 10 000 bajtów.
 */
final class ChatMessageSent implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    /**
     * @param  list<int>  $userIds
     * @param  array{conversation_id: int, message_id: int, kind: string, user: array{id: int, name: string}|null, preview: string, conversation_name: string, created_at: string|null}  $payload
     */
    public function __construct(
        public readonly array $userIds,
        public readonly array $payload,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(static fn (int $id): PrivateChannel => new PrivateChannel('user.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }

    public function broadcastWhen(): bool
    {
        return $this->userIds !== [];
    }
}
