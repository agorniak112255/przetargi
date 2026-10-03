<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Ktoś dzwoni — do uczestników rozmowy czatu oprócz dzwoniącego (nie w kanale „everyone”: tam jest tylko wpis
 * „Rozmowa trwa — Dołącz”). Klient dzwoni najwyżej ring_seconds i przestaje po chat.call.updated (§4 kontraktu).
 */
final class ChatCallRinging implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    /**
     * @param  list<int>  $userIds
     * @param  array{call_id: int, conversation_id: int, conversation_name: string, kind: string, started_by: array{id: int, name: string}, started_at: string|null}  $payload
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
        return 'chat.call.ringing';
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
