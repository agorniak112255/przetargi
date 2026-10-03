<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Do kiedy ważna jest oferta z odpowiedzi na zapytanie — z tekstu handlowca w offer_terms.validity (dowolny tekst do
 * 200 znaków) i dnia wysłania odpowiedzi (replied_at, dzień w Polsce). Rozumie tylko jednoznaczne zapisy:
 *  - sama liczba albo „N dni / dnia / dzień (kalendarzowych)” → N dni kalendarzowych od dnia odpowiedzi,
 *  - „N dni roboczych” → N dni od poniedziałku do piątku po dniu odpowiedzi (święta nie są liczone),
 *  - „N tygodni”, „tydzień” → 7·N dni; „N miesięcy”, „miesiąc” → N miesięcy kalendarzowych,
 *  - data „DD.MM.RRRR” (także z „r.”, z kreskami albo ukośnikami),
 * z opcjonalnym początkiem „oferta ważna (przez | do)” i końcem „od daty oferty / wysłania / wystawienia”.
 * Wszystko inne (np. „do odwołania”, „do wyczerpania zapasów”) → null — nie zgadujemy. Wynik dalej niż 365 dni od
 * odpowiedzi albo data sprzed odpowiedzi → null.
 */
final class OfferValidity
{
    public const MAX_DAYS = 365;

    private const PREFIX = '/^(?:oferta\s+)?(?:jest\s+)?(?:ważna\s+)?(?:przez\s+|do\s+(?:dnia\s+)?)?/u';

    private const SUFFIX = '(?:\s+od\s+(?:daty\s+|dnia\s+)?(?:oferty|wysłania|wystawienia|złożenia|otrzymania)(?:\s+oferty)?)?';

    /**
     * Ostatni dzień ważności (00:00 czasu polskiego) albo null, gdy tekstu nie da się jednoznacznie odczytać.
     */
    public static function until(?string $text, CarbonImmutable $repliedAt): ?CarbonImmutable
    {
        if ($text === null) {
            return null;
        }
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        $value = rtrim($value, " .\t");
        $value = (string) preg_replace(self::PREFIX, '', $value);
        if ($value === '') {
            return null;
        }

        $start = $repliedAt->setTimezone(PolishTime::TIMEZONE)->startOfDay();
        $until = self::parse($value, $start);
        if ($until === null || $until->lessThan($start) || $until->greaterThan($start->addDays(self::MAX_DAYS))) {
            return null;
        }

        return $until;
    }

    private static function parse(string $value, CarbonImmutable $start): ?CarbonImmutable
    {
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})(?:\s?r\.?)?$/u', $value, $m) === 1) {
            if (! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return null;
            }

            return CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1], 0, 0, 0, PolishTime::TIMEZONE);
        }

        $suffix = self::SUFFIX;
        if (preg_match('/^(\d{1,3})(?:\s+(?:dni|dnia|dzień)(?:\s+kalendarzow(?:ych|e|y))?)?'.$suffix.'$/u', $value, $m) === 1) {
            return self::positive($m[1]) ? $start->addDays((int) $m[1]) : null;
        }
        if (preg_match('/^(\d{1,3})\s+(?:dni|dnia|dzień)\s+robocz(?:ych|e|y)'.$suffix.'$/u', $value, $m) === 1) {
            return self::positive($m[1]) ? self::addBusinessDays($start, (int) $m[1]) : null;
        }
        if (preg_match('/^(?:(\d{1,2})\s+)?(?:tydzień|tygodnie|tygodni)'.$suffix.'$/u', $value, $m) === 1) {
            $weeks = ($m[1] ?? '') === '' ? '1' : $m[1];

            return self::positive($weeks) ? $start->addDays(7 * (int) $weeks) : null;
        }
        if (preg_match('/^(?:(\d{1,2})\s+)?(?:miesiąc|miesiące|miesięcy)'.$suffix.'$/u', $value, $m) === 1) {
            $months = ($m[1] ?? '') === '' ? '1' : $m[1];

            return self::positive($months) ? $start->addMonthsNoOverflow((int) $months) : null;
        }

        return null;
    }

    private static function positive(string $number): bool
    {
        return (int) $number > 0;
    }

    /** N dni od poniedziałku do piątku po dniu $start (sam dzień odpowiedzi się nie liczy). */
    private static function addBusinessDays(CarbonImmutable $start, int $days): CarbonImmutable
    {
        $day = $start;
        while ($days > 0) {
            $day = $day->addDay();
            if (! $day->isWeekend()) {
                $days--;
            }
        }

        return $day;
    }
}
