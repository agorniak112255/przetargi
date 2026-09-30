<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use Closure;
use DateTimeInterface;
use RuntimeException;

/**
 * Minimalny klient IMAP (tylko SSL, port 993) do odczytu NAGŁÓWKÓW poczty handlowca: skrzynka otwierana przez EXAMINE
 * (tylko do odczytu), treści nie pobieramy, BODY.PEEK nie ustawia flagi „przeczytane”. Nic w skrzynce nie zmienia.
 * Bez rozszerzenia imap i bez nowej biblioteki — tylko komendy, których potrzebuje CampaignReplySync.
 */
class ImapHeaderReader
{
    /** Nagłówki, których potrzebuje dopasowanie odpowiedzi do kampanii i rozpoznanie autoodpowiedzi/zwrotek. */
    public const HEADER_FIELDS = 'FROM SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES AUTO-SUBMITTED X-AUTOREPLY X-AUTORESPOND PRECEDENCE CONTENT-TYPE';

    private const TIMEOUT = 20;

    /** Najdłuższa linia odpowiedzi serwera (SEARCH przy dużej skrzynce potrafi mieć setki kB). */
    private const MAX_LINE = 8 * 1024 * 1024;

    /** @var resource|null */
    private $in = null;

    /** @var resource|null */
    private $out = null;

    private int $tag = 0;

    private string $capabilities = '';

    /**
     * @param  (Closure(string, int, bool): array{0: resource, 1: resource})|null  $connector  host, port, verify_peer →
     *                                                                                         [odczyt, zapis]; testy podają
     *                                                                                         strumienie z nagraną rozmową
     */
    public function __construct(private readonly ?Closure $connector = null) {}

    public function open(string $host, int $port, bool $verifyPeer): void
    {
        if ($this->connector !== null) {
            [$this->in, $this->out] = ($this->connector)($host, $port, $verifyPeer);
        } else {
            $context = stream_context_create(['ssl' => ['verify_peer' => $verifyPeer, 'verify_peer_name' => $verifyPeer, 'SNI_enabled' => true]]);
            $errno = 0;
            $error = '';
            $socket = @stream_socket_client('ssl://'.$host.':'.$port, $errno, $error, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
            if ($socket === false) {
                throw new RuntimeException('Nie udało się połączyć z serwerem IMAP '.$host.':'.$port.($error !== '' ? ' ('.$error.')' : '').'.');
            }
            stream_set_timeout($socket, self::TIMEOUT);
            $this->in = $socket;
            $this->out = $socket;
        }
        $greeting = $this->readLine();
        if (! str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('Serwer IMAP nie przywitał się poprawnie.');
        }
        $this->capabilities = strtoupper($greeting);
    }

    /** Logowanie: AUTHENTICATE PLAIN w jednej linii (SASL-IR — dowolne znaki hasła), inaczej LOGIN. */
    public function login(string $username, string $password): void
    {
        if (preg_match('/[\r\n\0]/', $username.$password) === 1) {
            throw new RuntimeException('Login albo hasło skrzynki zawiera niedozwolone znaki.');
        }
        if (str_contains($this->capabilities, 'SASL-IR') && str_contains($this->capabilities, 'AUTH=PLAIN')) {
            $this->command('AUTHENTICATE PLAIN '.base64_encode("\0".$username."\0".$password), 'Logowanie do skrzynki IMAP nie powiodło się — sprawdź login i hasło.');

            return;
        }
        $this->command('LOGIN '.$this->quote($username).' '.$this->quote($password), 'Logowanie do skrzynki IMAP nie powiodło się — sprawdź login i hasło.');
    }

    /**
     * Foldery skrzynki (LIST) z oznaczeniami serwera (\Sent, \Trash, \Noselect…). Nazwa jak na serwerze
     * (zmodyfikowane UTF-7) — taką podaje się do EXAMINE.
     *
     * @return list<array{name: string, delimiter: string, flags: list<string>}>
     */
    public function listMailboxes(): array
    {
        $out = [];
        foreach ($this->command('LIST "" "*"', 'Nie udało się odczytać listy folderów.') as $item) {
            if (preg_match('/^\* LIST \(([^)]*)\) (NIL|"(?:[^"\\\\]|\\\\.)*") ?(.*)$/i', $item['line'], $m) !== 1) {
                continue;
            }
            if ($item['literal'] !== null) {
                $name = $item['literal'];
            } elseif (str_starts_with($m[3], '"')) {
                $name = $this->unquote($m[3]);
            } else {
                $name = $m[3];
            }
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'delimiter' => strtoupper($m[2]) === 'NIL' ? '' : $this->unquote($m[2]),
                'flags' => array_values(array_filter(preg_split('/\s+/', strtolower(trim($m[1]))) ?: [])),
            ];
        }

        return $out;
    }

    /**
     * Skrzynka tylko do odczytu (EXAMINE). UIDVALIDITY zmienia się, gdy serwer przenumeruje wiadomości; UIDNEXT = UID,
     * który dostanie następna wiadomość (0, gdy serwer go nie podał).
     *
     * @return array{uidvalidity: int, exists: int, uidnext: int}
     */
    public function examine(string $mailbox = 'INBOX'): array
    {
        $items = $this->command('EXAMINE '.$this->quote($mailbox), 'Nie można otworzyć skrzynki '.$mailbox.'.');
        $uidValidity = 0;
        $exists = 0;
        $uidNext = 0;
        foreach ($items as $item) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $item['line'], $m) === 1) {
                $uidValidity = (int) $m[1];
            }
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $item['line'], $m) === 1) {
                $uidNext = (int) $m[1];
            }
            if (preg_match('/^\* (\d+) EXISTS/i', $item['line'], $m) === 1) {
                $exists = (int) $m[1];
            }
        }

        return ['uidvalidity' => $uidValidity, 'exists' => $exists, 'uidnext' => $uidNext];
    }

    /** @return list<int> UID wiadomości od dnia (data wg serwera) */
    public function searchSince(DateTimeInterface $since): array
    {
        return $this->search('UID SEARCH SINCE '.$since->format('d-M-Y'));
    }

    /** @return list<int> UID większe niż $afterUid */
    public function searchAfterUid(int $afterUid): array
    {
        // „n:*” zwraca co najmniej ostatnią wiadomość, nawet o mniejszym UID — odfiltrowujemy
        return array_values(array_filter($this->search('UID SEARCH UID '.($afterUid + 1).':*'), static fn (int $uid): bool => $uid > $afterUid));
    }

    /**
     * Nagłówki wiadomości (BODY.PEEK — bez flagi „przeczytane”).
     *
     * @param  list<int>  $uids
     * @return list<array{uid: int, headers: array<string, string>}>
     */
    public function fetchHeaders(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $items = $this->command(
            'UID FETCH '.implode(',', array_map('intval', $uids)).' (UID BODY.PEEK[HEADER.FIELDS ('.self::HEADER_FIELDS.')])',
            'Nie udało się odczytać nagłówków wiadomości.',
        );
        $out = [];
        foreach ($items as $item) {
            if (preg_match('/^\* \d+ FETCH /i', $item['line']) !== 1 || $item['literal'] === null) {
                continue;
            }
            if (preg_match('/\bUID (\d+)/i', $item['line'].' '.$item['rest'], $m) !== 1) {
                continue;
            }
            $out[] = ['uid' => (int) $m[1], 'headers' => self::parseHeaders($item['literal'])];
        }

        return $out;
    }

    public function logout(): void
    {
        try {
            if ($this->out !== null) {
                $this->command('LOGOUT', 'LOGOUT');
            }
        } catch (RuntimeException) {
            // serwer mógł już zamknąć połączenie — nic do zrobienia
        } finally {
            if (is_resource($this->in)) {
                fclose($this->in);
            }
            if ($this->out !== $this->in && is_resource($this->out)) {
                fclose($this->out);
            }
            $this->in = $this->out = null;
        }
    }

    /**
     * Nagłówki (małe litery w kluczach), złożone linie rozwinięte, słowa =?utf-8?…?= rozkodowane.
     *
     * @return array<string, string>
     */
    public static function parseHeaders(string $raw): array
    {
        $raw = (string) preg_replace("/\r?\n[ \t]+/", ' ', $raw);
        $out = [];
        foreach (preg_split("/\r?\n/", $raw) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            if ($name === '' || isset($out[$name])) {
                continue;
            }
            $decoded = @iconv_mime_decode(trim($value), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            $out[$name] = is_string($decoded) ? $decoded : trim($value);
        }

        return $out;
    }

    /** @return list<int> */
    private function search(string $command): array
    {
        $uids = [];
        foreach ($this->command($command, 'Wyszukiwanie w skrzynce nie powiodło się.') as $item) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $item['line'], $m) === 1) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }
        sort($uids);

        return $uids;
    }

    /**
     * Wysyła komendę i czyta odpowiedzi do linii z tym samym znacznikiem; literał {n} czytany dokładnie n bajtów.
     *
     * @return list<array{line: string, literal: string|null, rest: string}>
     */
    private function command(string $command, string $error): array
    {
        $tag = 'a'.(++$this->tag);
        $this->write($tag.' '.$command."\r\n");
        $items = [];
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, $tag.' ')) {
                if (preg_match('/^'.preg_quote($tag, '/').' OK\b/i', $line) !== 1) {
                    throw new ImapCommandException($error);
                }

                return $items;
            }
            $literal = null;
            $rest = '';
            if (preg_match('/\{(\d+)\}$/', $line, $m) === 1) {
                $literal = $this->readBytes((int) $m[1]);
                $rest = $this->readLine();
            }
            $items[] = ['line' => $line, 'literal' => $literal, 'rest' => $rest];
        }
    }

    private function write(string $data): void
    {
        if ($this->out === null || @fwrite($this->out, $data) === false) {
            throw new RuntimeException('Połączenie z serwerem IMAP zostało przerwane.');
        }
    }

    private function readLine(): string
    {
        if ($this->in === null) {
            throw new RuntimeException('Brak połączenia z serwerem IMAP.');
        }
        // linia do znaku końca linii, choćby dłuższa niż bufor fgets — ucięta lista UID zgubiłaby wiadomości
        $line = '';
        while (! str_ends_with($line, "\n")) {
            $part = fgets($this->in, 65536);
            if ($part === false) {
                $meta = stream_get_meta_data($this->in);
                throw new RuntimeException(($meta['timed_out'] ?? false) ? 'Serwer IMAP nie odpowiedział na czas.' : 'Połączenie z serwerem IMAP zostało przerwane.');
            }
            $line .= $part;
            if (strlen($line) > self::MAX_LINE) {
                throw new RuntimeException('Serwer IMAP przysłał zbyt długą odpowiedź.');
            }
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = $this->in !== null ? fread($this->in, $length - strlen($data)) : false;
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Połączenie z serwerem IMAP zostało przerwane.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    /** "a\"b" → a"b — w cytowanym napisie IMAP escapowane są tylko \" i \\ */
    private function unquote(string $quoted): string
    {
        return (string) preg_replace('/\\\\(.)/s', '$1', substr($quoted, 1, -1));
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
