<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Zmiana rozmowy głosowej/wideo. reason „status” — do uczestników rozmowy czatu przy każdej zmianie statusu;
 * „declined” / „joined” — tylko do osoby, która odrzuciła albo dołącza (jej inne urządzenia przestają dzwonić).
 * Sam sygnał: szczegóły klient pobiera z GET /chat/calls/{id}.
 */
final class ChatCallUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    public const REASON_STATUS = 'status';

    public const REASON_DECLINED = 'declined';

    public const REASON_JOINED = 'joined';

    /**
     * @param  list<int>  $userIds
     * @param  array{call_id: int, conversation_id: int, message_id: int|null, status: string, kind: string, reason: string, duration_seconds: int|null}  $payload
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
        return 'chat.call.updated';
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
