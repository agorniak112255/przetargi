<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Services\Notifications\AppNotificationMessage;
use App\Services\Notifications\NotificationDispatcher;

/**
 * Atrapa wspólnej wysyłki powiadomień dla testów alertów „Stanu systemu”: zapisuje wywołania i udaje, że e-mail
 * wyszedł do każdego odbiorcy (albo — z $mailResult — że nie wyszedł). Wnętrza prawdziwego dyspozytora nie zakłada.
 */
class FakeSystemAlertDispatcher extends NotificationDispatcher
{
    /** @var list<array{users: list<int>, message: AppNotificationMessage, period: ?string}> */
    public array $calls = [];

    public function __construct(public ?bool $mailResult = true) {}

    public function send(User|iterable $users, AppNotificationMessage $message, ?string $period = null): array
    {
        $list = $users instanceof User ? [$users] : (is_array($users) ? $users : iterator_to_array($users, false));
        $ids = array_map(static fn (User $u): int => (int) $u->id, $list);
        $this->calls[] = ['users' => $ids, 'message' => $message, 'period' => $period];

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['bell' => true, 'mail' => $this->mailResult];
        }

        return $out;
    }
}
