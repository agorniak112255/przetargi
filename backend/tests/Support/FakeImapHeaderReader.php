<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Campaigns\ImapHeaderReader;
use DateTimeInterface;
use RuntimeException;

/** Skrzynka IMAP w pamięci: wiadomości (UID → nagłówki), zapis wywołań; bez sieci. */
final class FakeImapHeaderReader extends ImapHeaderReader
{
    /** @var array<int, array<string, string>> UID → nagłówki (małe litery) */
    public array $messages = [];

    public int $uidValidity = 7;

    public ?string $loginError = null;

    /** @var list<array{0: string, 1: mixed}> */
    public array $calls = [];

    public function open(string $host, int $port, bool $verifyPeer): void
    {
        $this->calls[] = ['open', [$host, $port, $verifyPeer]];
    }

    public function login(string $username, string $password): void
    {
        $this->calls[] = ['login', $username];
        if ($this->loginError !== null) {
            throw new RuntimeException($this->loginError);
        }
    }

    public function examine(string $mailbox = 'INBOX'): array
    {
        $this->calls[] = ['examine', $mailbox];

        return ['uidvalidity' => $this->uidValidity, 'exists' => count($this->messages)];
    }

    public function searchSince(DateTimeInterface $since): array
    {
        $this->calls[] = ['searchSince', $since->format('Y-m-d')];
        $uids = array_keys($this->messages);
        sort($uids);

        return $uids;
    }

    public function searchAfterUid(int $afterUid): array
    {
        $this->calls[] = ['searchAfterUid', $afterUid];
        $uids = array_values(array_filter(array_keys($this->messages), static fn (int $uid): bool => $uid > $afterUid));
        sort($uids);

        return $uids;
    }

    public function fetchHeaders(array $uids): array
    {
        $this->calls[] = ['fetchHeaders', $uids];
        $out = [];
        foreach ($uids as $uid) {
            if (isset($this->messages[$uid])) {
                $out[] = ['uid' => $uid, 'headers' => $this->messages[$uid]];
            }
        }

        return $out;
    }

    public function logout(): void
    {
        $this->calls[] = ['logout', null];
    }
}
