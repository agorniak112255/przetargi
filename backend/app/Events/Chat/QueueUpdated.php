<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * W kolejce dodatku do Thunderbirda pojawiła się praca (GET /inquiries/queued: prośba „Zapisz i wyślij” albo oferta
 * „Otwórz w Thunderbirdzie”). Sam sygnał — dodatek z działającym websocketem pyta wtedy o kolejkę od razu, zamiast
 * co kilka sekund. Bez uprawnienia `chat`: kolejka dotyczy każdego, kto pracuje z zapytaniami.
 */
final class QueueUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    public function __construct(
        public readonly int $userId,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }
}
