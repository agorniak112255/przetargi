<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Treść maila przygotowana do analizy zapytania.
 *
 * Cały mail trafia do bazy jako `source_body` — tu powstaje wyłącznie wersja
 * robocza dla modelu i dla parsera pozycji. Parser czyta wzorzec
 * „liczba + separator + reszta”, więc bez tego adres „35-206 Rzeszów”
 * albo telefon „17 785 22 46” ze stopki stają się pozycjami zamówienia.
 *
 * Zasada: wycinamy tylko to, co na pewno nie jest zapytaniem — cytat
 * poprzedniej wiadomości, nagłówek przekazania, podpis i klauzulę poufności.
 */
final class InquiryMailText
{
    /** Początek cytatu poprzedniej wiadomości. */
    private const SEPARATORS = [
        '/^-{2,}\s*(wiadomość oryginalna|wiadomosc oryginalna|original message)/iu',
        '/^_{5,}\s*$/u',
        '/^w dniu .{0,120}napisa[łl]/iu',
        '/^dnia .{0,120}napisa[łl]/iu',
        '/^on .{0,160}wrote:\s*$/iu',
    ];

    /** Nagłówek wiadomości przekazanej dalej — samo zapytanie jest pod nim. */
    private const FORWARD_MARKERS = [
        '/^-*\s*treść przekazanej wiadomości\s*-*$/iu',
        '/^-*\s*tresc przekazanej wiadomosci\s*-*$/iu',
        '/^-{2,}\s*wiadomość przekazana/iu',
        '/^-{2,}\s*forwarded message/iu',
        '/^-{2,}\s*(begin )?forwarded message/iu',
        '/^begin forwarded message/iu',
    ];

    private const HEADER_LINE = '/^(od|from|do|to|dw|cc|udw|bcc|wysłano|wyslano|sent|data|date|temat|subject|nadawca|adresat|odbiorca|reply-to)\s*:/iu';

    /** Stopka wg RFC 3676. */
    private const SIGNATURE = '/^--\s*$/u';

    /** Zwrot grzecznościowy albo klauzula poufności — dalej idzie już tylko podpis. */
    private const CLOSING = [
        '/^pozdrawiam/iu',
        '/^pozdrowienia/iu',
        '/^z\s+pozdrowieniami/iu',
        '/^z\s+powa[żz]aniem/iu',
        '/^z\s+wyrazami\s+szacunku/iu',
        '/^[łl][ąa]cz[ęe]\s+wyrazy/iu',
        '/^serdecznie\s+pozdrawiam/iu',
        '/^(best|kind|warm)\s+regards/iu',
        '/^regards\s*[,.]?\s*$/iu',
        '/^sincerely/iu',
        '/^uwaga:\s*wiadomo[śs][ćc]/iu',
        '/^(niniejsza\s+)?wiadomo[śs][ćc]\s+(jest\s+)?(przeznaczona|poufna)/iu',
        '/^this\s+(e-?mail|message)\s+(is|and)/iu',
    ];

    public static function forAnalysis(string $raw): string
    {
        $text = self::split($raw)['body'];
        $text = preg_replace('/[ \t]+$/mu', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);

        // Nadgorliwe cięcie jest gorsze niż brak cięcia — wtedy wracamy do oryginału.
        return $text === '' ? trim($raw) : $text;
    }

    /**
     * Część maila odcięta przed analizą: podpis, stopka firmowa, klauzula
     * poufności albo początek cytatu. Z niej `InquirySignature` wyjmuje kontakt.
     *
     * Cytowane linie („> …”) i nagłówek przekazania są już usunięte, więc do
     * stopki nie wchodzą dane z cudzej, wcześniejszej wiadomości.
     * Pusty wynik = w mailu nie było nic do odcięcia.
     */
    public static function footerOf(string $raw): string
    {
        return trim(self::split($raw)['footer']);
    }

    /**
     * Wspólne cięcie dla obu widoków: to, co zostaje do analizy, i to, co odpada.
     *
     * @return array{body: string, footer: string}
     */
    /**
     * Zapytanie bywa przekazywane kilka razy, a osoba przekazująca ma podpis
     * NAD treścią. Dlatego mail dzielimy na kawałki po nagłówkach przekazania
     * i podpisy wycinamy w każdym z osobna — inaczej pierwsze „-- ” na górze
     * ucięłoby cały mail razem z zapytaniem.
     *
     * @return array{body: string, footer: string}
     */
    private static function split(string $raw): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = self::stripQuotedLines($text);

        $segments = self::forwardSegments($text);
        $last = array_pop($segments);

        [$body, $footer] = self::cutWithFooter($last);

        // Notatki osób przekazujących zostają (bywa w nich polecenie dla handlowca),
        // ale bez ich podpisów; same „Pozdrawiam” pomijamy.
        $notes = [];
        foreach ($segments as $segment) {
            $note = trim(self::cutWithFooter($segment)[0]);
            if (mb_strlen($note) >= 20) {
                $notes[] = $note;
            }
        }

        $full = trim(implode("\n\n", array_filter([...$notes, trim($body)])));

        // Nadgorliwe cięcie jest gorsze niż brak cięcia: gdy z długiego maila
        // zostały strzępy, wracamy do wersji bez wycinania podpisów.
        $plain = trim(implode("\n\n", array_map('trim', self::forwardSegments($text))));
        if (mb_strlen($full) < 60 && mb_strlen($plain) > 200) {
            return ['body' => $plain, 'footer' => ''];
        }

        return ['body' => $full, 'footer' => $footer];
    }

    /**
     * Mail pocięty nagłówkami przekazania: [notatka, …, właściwe zapytanie].
     * Same nagłówki („Temat:/Data:/Nadawca:/Adresat:”) wypadają.
     *
     * @return list<string>
     */
    private static function forwardSegments(string $text): array
    {
        $lines = explode("\n", $text);
        $segments = [];
        $current = [];

        for ($i = 0; $i < count($lines); $i++) {
            if (! self::matchesAny(trim($lines[$i]), self::FORWARD_MARKERS)) {
                $current[] = $lines[$i];

                continue;
            }

            $segments[] = implode("\n", $current);
            $current = [];
            $i = self::skipHeaderBlock($lines, $i + 1) - 1;
        }

        $segments[] = implode("\n", $current);

        if (count($segments) === 1) {
            // Brak przekazania — zostaje przypadek nagłówka na samej górze maila.
            return [self::dropLeadingHeaderBlock($lines)];
        }

        return $segments;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function cutWithFooter(string $segment): array
    {
        $lines = explode("\n", $segment);
        $separator = self::separatorIndex($lines);
        $cut = self::closingIndex(array_slice($lines, 0, $separator));

        return [
            implode("\n", array_slice($lines, 0, $cut)),
            implode("\n", array_slice($lines, $cut)),
        ];
    }

    private static function stripQuotedLines(string $text): string
    {
        $kept = array_filter(
            explode("\n", $text),
            static fn (string $line): bool => preg_match('/^\s*>/u', $line) !== 1,
        );

        return implode("\n", $kept);
    }

    /**
     * Zdejmuje nagłówek przekazania („Temat:/Data:/Nadawca:/Adresat:”), zostawiając
     * zarówno notatkę osoby przekazującej, jak i treść przekazanej wiadomości.
     */
    private static function dropForwardHeader(string $text): string
    {
        $lines = explode("\n", $text);
        $marker = null;
        foreach ($lines as $i => $line) {
            if (self::matchesAny(trim($line), self::FORWARD_MARKERS)) {
                $marker = $i;
                break;
            }
        }

        if ($marker === null) {
            return self::dropLeadingHeaderBlock($lines);
        }

        $after = self::skipHeaderBlock($lines, $marker + 1);
        $before = trim(implode("\n", array_slice($lines, 0, $marker)));
        $rest = trim(implode("\n", array_slice($lines, $after)));

        if ($before === '') {
            return $rest;
        }

        return $rest === '' ? $before : $before."\n\n".$rest;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function dropLeadingHeaderBlock(array $lines): string
    {
        $i = 0;
        while ($i < count($lines) && trim($lines[$i]) === '') {
            $i++;
        }

        if ($i >= count($lines) || preg_match(self::HEADER_LINE, trim($lines[$i])) !== 1) {
            return implode("\n", $lines);
        }

        $after = self::skipHeaderBlock($lines, $i);

        return $after === $i ? implode("\n", $lines) : implode("\n", array_slice($lines, $after));
    }

    /**
     * Zwraca indeks pierwszej linii po bloku nagłówków; pojedyncze „Od:” bywa
     * zwykłym zdaniem, więc blok liczy się od dwóch linii.
     *
     * @param  list<string>  $lines
     */
    private static function skipHeaderBlock(array $lines, int $from): int
    {
        $i = $from;
        while ($i < count($lines) && trim($lines[$i]) === '') {
            $i++;
        }

        $headers = 0;
        $j = $i;
        while ($j < count($lines) && trim($lines[$j]) !== '' && preg_match(self::HEADER_LINE, trim($lines[$j])) === 1) {
            $headers++;
            $j++;
        }

        return $headers >= 2 ? $j : $from;
    }

    /**
     * Indeks linii, od której zaczyna się podpis albo cytat (albo koniec tekstu).
     *
     * @param  list<string>  $lines
     */
    private static function separatorIndex(array $lines): int
    {
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match(self::SIGNATURE, $line) === 1 || self::matchesAny($trimmed, self::SEPARATORS)) {
                return $i;
            }
            if ($i > 0 && preg_match(self::HEADER_LINE, $trimmed) === 1 && self::looksLikeHeaderBlock($lines, $i)) {
                return $i;
            }
        }

        return count($lines);
    }

    /**
     * Indeks zwrotu grzecznościowego (albo koniec tekstu). Wymaga dwóch linii
     * treści przed nim, żeby „Pozdrawiam” w drugiej linijce krótkiego maila
     * nie skasowało całego zapytania.
     *
     * @param  list<string>  $lines
     */
    private static function closingIndex(array $lines): int
    {
        $content = 0;
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if ($content >= 2 && self::matchesAny($trimmed, self::CLOSING)) {
                return $i;
            }
            $content++;
        }

        return count($lines);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function looksLikeHeaderBlock(array $lines, int $index): bool
    {
        $headers = 0;
        for ($i = $index; $i < count($lines) && trim($lines[$i]) !== ''; $i++) {
            if (preg_match(self::HEADER_LINE, trim($lines[$i])) !== 1) {
                return false;
            }
            $headers++;
        }

        return $headers >= 2;
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matchesAny(string $line, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
