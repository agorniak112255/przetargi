<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Campaigns\ImapCommandException;
use App\Services\Campaigns\ImapHeaderReader;
use DateTimeInterface;
use RuntimeException;

/** Skrzynka IMAP w pamięci: INBOX i foldery (UID → nagłówki), zapis wywołań; bez sieci. */
final class FakeImapHeaderReader extends ImapHeaderReader
{
    /** @var array<int, array<string, string>> INBOX: UID → nagłówki (małe litery) */
    public array $messages = [];

    /** @var array<string, array{flags?: list<string>, messages: array<int, array<string, string>>}> pozostałe foldery */
    public array $folders = [];

    /** @var list<string> foldery, których serwer nie pozwala otworzyć (EXAMINE → NO) */
    public array $failing = [];

    public int $uidValidity = 7;

    public ?string $loginError = null;

    /** @var list<array{0: string, 1: mixed}> */
    public array $calls = [];

    private string $current = 'INBOX';

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

    public function listMailboxes(): array
    {
        $this->calls[] = ['list', null];
        $out = [['name' => 'INBOX', 'delimiter' => '.', 'flags' => ['\haschildren']]];
        foreach ($this->folders as $name => $folder) {
            $out[] = ['name' => $name, 'delimiter' => '.', 'flags' => $folder['flags'] ?? ['\hasnochildren']];
        }

        return $out;
    }

    public function examine(string $mailbox = 'INBOX'): array
    {
        $this->calls[] = ['examine', $mailbox];
        if (in_array($mailbox, $this->failing, true)) {
            throw new ImapCommandException('Nie można otworzyć skrzynki '.$mailbox.'.');
        }
        $this->current = $mailbox;
        $uids = array_keys($this->box());

        return ['uidvalidity' => $this->uidValidity, 'exists' => count($uids), 'uidnext' => ($uids === [] ? 0 : max($uids)) + 1];
    }

    /** Jak serwer: wiadomości z datą od dnia $since (bez daty — liczą się). */
    public function searchSince(DateTimeInterface $since): array
    {
        $this->calls[] = ['searchSince', $since->format('Y-m-d')];
        $day = $since->format('Y-m-d');
        $uids = array_keys(array_filter(
            $this->box(),
            static fn (array $h): bool => ! isset($h['date']) || date('Y-m-d', (int) strtotime($h['date'])) >= $day,
        ));
        sort($uids);

        return $uids;
    }

    public function searchAfterUid(int $afterUid): array
    {
        $this->calls[] = ['searchAfterUid', $afterUid];
        $uids = array_values(array_filter(array_keys($this->box()), static fn (int $uid): bool => $uid > $afterUid));
        sort($uids);

        return $uids;
    }

    public function fetchHeaders(array $uids): array
    {
        $this->calls[] = ['fetchHeaders', $uids];
        $box = $this->box();
        $out = [];
        foreach ($uids as $uid) {
            if (isset($box[$uid])) {
                $out[] = ['uid' => $uid, 'headers' => $box[$uid]];
            }
        }

        return $out;
    }

    public function logout(): void
    {
        $this->calls[] = ['logout', null];
    }

    /** @return array<int, array<string, string>> */
    private function box(): array
    {
        return $this->current === 'INBOX' ? $this->messages : ($this->folders[$this->current]['messages'] ?? []);
    }
}
