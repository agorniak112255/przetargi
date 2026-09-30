<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Campaigns\ImapCommandException;
use App\Services\Campaigns\ImapHeaderReader;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Czytnik IMAP na nagranej rozmowie z serwerem: tylko EXAMINE i BODY.PEEK, literały, długie linie, błędy. */
final class ImapHeaderReaderTest extends TestCase
{
    /** @var resource */
    private $written;

    /** @param  list<string>  $serverLines */
    private function reader(array $serverLines): ImapHeaderReader
    {
        $in = fopen('php://temp', 'r+');
        fwrite($in, implode('', $serverLines));
        rewind($in);
        $this->written = fopen('php://memory', 'r+');

        return new ImapHeaderReader(fn (string $host, int $port, bool $verify): array => [$in, $this->written]);
    }

    private function sent(): string
    {
        rewind($this->written);

        return (string) stream_get_contents($this->written);
    }

    public function test_reads_headers_read_only(): void
    {
        $h1 = "From: =?UTF-8?Q?Anna_=C5=81=C4=85cka?= <klient@alfa.pl>\r\nSubject: Zapytanie K-0006\r\n B20417\r\nIn-Reply-To: <abc@x.pl>\r\n\r\n";
        $h2 = "From: biuro@beta.pl\r\nSubject: =?utf-8?B?T2Rwb3dpZWTFug==?=\r\n\r\n";
        $reader = $this->reader([
            "* OK [CAPABILITY IMAP4rev1 SASL-IR AUTH=PLAIN] Dovecot ready.\r\n",
            "a1 OK Logged in\r\n",
            "* FLAGS (\\Answered \\Seen)\r\n* 3 EXISTS\r\n* OK [UIDVALIDITY 1700000000] UIDs valid\r\na2 OK [READ-ONLY] Examine completed\r\n",
            "* SEARCH 12 5 9\r\na3 OK Search completed\r\n",
            '* 1 FETCH (UID 5 BODY[HEADER.FIELDS (FROM SUBJECT)] {'.strlen($h1)."}\r\n".$h1.")\r\n",
            '* 2 FETCH (BODY[HEADER.FIELDS (FROM SUBJECT)] {'.strlen($h2)."}\r\n".$h2." UID 9)\r\n",
            "* 2 FETCH (FLAGS (\\Seen))\r\na4 OK Fetch completed\r\n",
            "* BYE Logging out\r\na5 OK Logout completed\r\n",
        ]);

        $reader->open('imap.example.pl', 993, true);
        $reader->login('jan@supon.pl', 'tajne"haslo');
        $this->assertSame(['uidvalidity' => 1700000000, 'exists' => 3], $reader->examine('INBOX'));
        $this->assertSame([5, 9, 12], $reader->searchSince(new DateTimeImmutable('2026-09-28')));
        $messages = $reader->fetchHeaders([5, 9]);
        $sent = $this->sent();
        $reader->logout();
        // LOGOUT wysłany i połączenie zamknięte
        $this->assertFalse(is_resource($this->written));

        $this->assertSame(5, $messages[0]['uid']);
        $this->assertSame('Anna Łącka <klient@alfa.pl>', $messages[0]['headers']['from']);
        $this->assertSame('Zapytanie K-0006 B20417', $messages[0]['headers']['subject']);
        $this->assertSame('<abc@x.pl>', $messages[0]['headers']['in-reply-to']);
        $this->assertSame(9, $messages[1]['uid']);
        $this->assertSame('Odpowiedź', $messages[1]['headers']['subject']);

        $this->assertStringContainsString('a1 AUTHENTICATE PLAIN '.base64_encode("\0jan@supon.pl\0tajne\"haslo")."\r\n", $sent);
        $this->assertStringContainsString("a2 EXAMINE \"INBOX\"\r\n", $sent);
        $this->assertStringContainsString("a3 UID SEARCH SINCE 28-Sep-2026\r\n", $sent);
        $this->assertStringContainsString('a4 UID FETCH 5,9 (UID BODY.PEEK[HEADER.FIELDS (', $sent);
        // nic, co zmienia skrzynkę albo flagi
        $this->assertDoesNotMatchRegularExpression('/\b(SELECT|STORE|EXPUNGE|COPY|MOVE|APPEND|DELETE)\b/', $sent);
        $this->assertStringNotContainsString('BODY[', $sent);
    }

    public function test_login_fallback_long_search_line_and_after_uid_filter(): void
    {
        $uids = range(1000, 21000);
        $reader = $this->reader([
            "* OK IMAP ready\r\n",
            "a1 OK LOGIN completed\r\n",
            '* SEARCH '.implode(' ', $uids)."\r\na2 OK done\r\n",
            // „n:*” zwraca ostatnią wiadomość nawet o mniejszym UID
            "* SEARCH 40\r\na3 OK done\r\n",
        ]);

        $reader->open('imap.example.pl', 993, true);
        $reader->login('jan', 'ha"s\\lo');
        $this->assertSame($uids, $reader->searchSince(new DateTimeImmutable('2026-09-01')));
        $this->assertSame([], $reader->searchAfterUid(40));
        $this->assertStringContainsString("a1 LOGIN \"jan\" \"ha\\\"s\\\\lo\"\r\n", $this->sent());
        $this->assertStringContainsString("a3 UID SEARCH UID 41:*\r\n", $this->sent());
    }

    public function test_lists_mailboxes_quoted_atom_literal_and_refused_examine(): void
    {
        $reader = $this->reader([
            "* OK ready\r\n",
            "* LIST (\\HasChildren) \".\" INBOX\r\n",
            "* LIST (\\HasNoChildren \\Sent) \".\" \"INBOX.Sent\"\r\n",
            "* LIST (\\HasNoChildren) \".\" \"INBOX.Wys&AUI-ane\"\r\n",
            "* LIST (\\HasNoChildren) \"/\" {15}\r\nKlienci \"VIP\" 1\r\n",
            "* LIST (\\Noselect) NIL \"Publiczne\"\r\n",
            "a1 OK List completed\r\n",
            "a2 NO [NONEXISTENT] Mailbox doesn't exist\r\n",
        ]);
        $reader->open('imap.example.pl', 993, true);

        $this->assertSame([
            ['name' => 'INBOX', 'delimiter' => '.', 'flags' => ['\\haschildren']],
            ['name' => 'INBOX.Sent', 'delimiter' => '.', 'flags' => ['\\hasnochildren', '\\sent']],
            ['name' => 'INBOX.Wys&AUI-ane', 'delimiter' => '.', 'flags' => ['\\hasnochildren']],
            ['name' => 'Klienci "VIP" 1', 'delimiter' => '/', 'flags' => ['\\hasnochildren']],
            ['name' => 'Publiczne', 'delimiter' => '', 'flags' => ['\\noselect']],
        ], $reader->listMailboxes());
        $this->assertStringContainsString("a1 LIST \"\" \"*\"\r\n", $this->sent());

        // odmowa serwera to osobny wyjątek — folder pomijany, połączenie działa dalej
        $this->expectException(ImapCommandException::class);
        $reader->examine('Klienci "VIP" 1');
    }

    public function test_rejected_login_and_dropped_connection_throw(): void
    {
        $reader = $this->reader(["* OK [CAPABILITY IMAP4rev1] ready\r\n", "a1 NO [AUTHENTICATIONFAILED] Authentication failed.\r\n"]);
        $reader->open('imap.example.pl', 993, true);
        try {
            $reader->login('jan', 'zle');
            $this->fail('Brak wyjątku przy odrzuconym logowaniu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sprawdź login i hasło', $e->getMessage());
        }
        // serwer zamknął połączenie w trakcie odpowiedzi
        $this->expectException(RuntimeException::class);
        $reader->examine();
    }

    public function test_bad_greeting_and_line_breaks_in_credentials_are_refused(): void
    {
        $reader = $this->reader(["* BYE too many connections\r\n"]);
        try {
            $reader->open('imap.example.pl', 993, true);
            $this->fail('Brak wyjątku przy złym powitaniu.');
        } catch (RuntimeException) {
        }

        $reader = $this->reader(["* OK ready\r\n"]);
        $reader->open('imap.example.pl', 993, true);
        $this->expectException(RuntimeException::class);
        $reader->login("jan\r\na9 DELETE INBOX", 'x');
    }
}
