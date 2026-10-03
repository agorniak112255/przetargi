<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Plik kalendarza iCalendar (RFC 5545) do subskrypcji w Outlooku i Thunderbirdzie.
 *
 * Zasady formatu, których pilnuje ta klasa: wiersze kończą się CRLF; wiersz dłuższy niż 75 oktetów (bajtów UTF-8)
 * jest łamany, a kontynuacja zaczyna się spacją — nigdy w środku znaku wielobajtowego; w tekście „\”, „;”, „,”
 * i nowa linia są poprzedzane „\”. Zdarzenie z godziną ma czas „na zegarze” w strefie Europe/Warsaw (TZID) i opis
 * tej strefy (VTIMEZONE: CET zimą, CEST latem), więc programy pokazują je poprawnie w każdej strefie; zdarzenie bez
 * godziny jest całodniowe (VALUE=DATE, koniec następnego dnia — wyłącznie).
 */
final class IcsBuilder
{
    private const CRLF = "\r\n";

    private const MAX_OCTETS = 75;

    /**
     * Zdarzenia: date „RRRR-MM-DD” i time „GG:MM” (albo null — całodniowe) to czas polski „na zegarze”.
     *
     * @param  list<array{uid: string, summary: string, description: string, url: string|null, date: string, time: string|null, duration_minutes: int, last_modified: DateTimeInterface|null}>  $events
     */
    public function calendar(string $name, array $events, ?DateTimeInterface $now = null): string
    {
        $stamp = self::utc($now ?? CarbonImmutable::now());
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Supon//Przetargi//PL',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escapeText($name),
            'X-WR-TIMEZONE:'.PolishTime::TIMEZONE,
            // podpowiedź, jak często pobierać (Outlook i tak ma własny rytm odświeżania)
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
            ...self::timezone(),
        ];
        foreach ($events as $event) {
            array_push($lines, ...$this->event($event, $stamp));
        }
        $lines[] = 'END:VCALENDAR';

        return implode('', array_map(static fn (string $line): string => self::fold($line).self::CRLF, $lines));
    }

    /**
     * Tekst wartości (SUMMARY, DESCRIPTION, X-WR-CALNAME): „\” → „\\”, „;” → „\;”, „,” → „\,”, nowa linia → „\n”;
     * pozostałe znaki sterujące (poza tabulatorem) wypadają.
     */
    public static function escapeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text) ?? '';

        return strtr($text, ['\\' => '\\\\', ';' => '\;', ',' => '\,', "\n" => '\n']);
    }

    /**
     * Łamanie wiersza: najwyżej 75 oktetów w wierszu (z wiodącą spacją kontynuacji), bez rozcinania znaku UTF-8.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_OCTETS) {
            return $line;
        }
        $out = '';
        $current = '';
        $limit = self::MAX_OCTETS;
        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out .= $current.self::CRLF.' ';
                $current = '';
                // kontynuacja zaczyna się spacją, która też jest oktetem wiersza
                $limit = self::MAX_OCTETS - 1;
            }
            $current .= $char;
        }

        return $out.$current;
    }

    /**
     * @param  array{uid: string, summary: string, description: string, url: string|null, date: string, time: string|null, duration_minutes: int, last_modified: DateTimeInterface|null}  $event
     * @return list<string>
     */
    private function event(array $event, string $stamp): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$event['uid'],
            'DTSTAMP:'.$stamp,
        ];
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $event['date'], PolishTime::TIMEZONE);
        $time = $event['time'];
        if (is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $event['date'].' '.$time, PolishTime::TIMEZONE);
            $end = $start->addMinutes(max(1, $event['duration_minutes']));
            $lines[] = 'DTSTART;TZID='.PolishTime::TIMEZONE.':'.$start->format('Ymd\THis');
            $lines[] = 'DTEND;TZID='.PolishTime::TIMEZONE.':'.$end->format('Ymd\THis');
        } else {
            $lines[] = 'DTSTART;VALUE=DATE:'.$day->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$day->addDay()->format('Ymd');
        }
        if ($event['last_modified'] !== null) {
            $lines[] = 'LAST-MODIFIED:'.self::utc($event['last_modified']);
        }
        $lines[] = 'SUMMARY:'.self::escapeText($event['summary']);
        $lines[] = 'DESCRIPTION:'.self::escapeText($event['description']);
        if ($event['url'] !== null && $event['url'] !== '') {
            $lines[] = 'URL:'.$event['url'];
        }
        // termin to przypomnienie, nie spotkanie — nie zajmuje czasu w kalendarzu
        $lines[] = 'TRANSP:TRANSPARENT';
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Strefa Europe/Warsaw: czas zimowy CET (UTC+1) od ostatniej niedzieli października, letni CEST (UTC+2)
     * od ostatniej niedzieli marca.
     *
     * @return list<string>
     */
    private static function timezone(): array
    {
        return [
            'BEGIN:VTIMEZONE',
            'TZID:'.PolishTime::TIMEZONE,
            'X-LIC-LOCATION:'.PolishTime::TIMEZONE,
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    private static function utc(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->setTimezone('UTC')->format('Ymd\THis\Z');
    }
}
