<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tender;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Czas polski dla ludzi i harmonogramu. Aplikacja liczy w UTC (config app.timezone), a termin składania ofert
 * to data i godzina „na zegarze” w Polsce (tenders.deadline + tenders.deadline_time) — stąd jedno miejsce,
 * które składa z nich chwilę i liczy „dziś”.
 */
final class PolishTime
{
    public const TIMEZONE = 'Europe/Warsaw';

    /** Początek bieżącego dnia w Polsce (00:00 czasu polskiego). */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->setTimezone(self::TIMEZONE);
    }

    /**
     * Chwila terminu składania ofert (strefa Europe/Warsaw) — null, gdy przetarg nie ma daty albo godziny.
     * Godzina nieistniejąca przy zmianie czasu na letni (np. 02:30 w ostatnią niedzielę marca) przesuwa się
     * o godzinę do przodu; godzina podwójna przy zmianie na zimowy oznacza pierwsze jej wystąpienie (czas letni).
     */
    public static function deadlineAt(Tender $tender): ?CarbonImmutable
    {
        $date = self::dateString($tender->deadline);
        $time = $tender->deadline_time;
        if ($date === null || ! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            return null;
        }

        $moment = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, self::TIMEZONE);

        if (! $moment instanceof CarbonImmutable) {
            return null;
        }
        // godzina podwójna (zmiana na czas zimowy): zawsze pierwsze wystąpienie, niezależnie od wersji PHP
        $earlier = $moment->subHour();

        return $earlier->format('Y-m-d H:i') === $moment->format('Y-m-d H:i') ? $earlier : $moment;
    }

    /**
     * Ostatni dzień roboczy przed podaną datą (pomija tylko soboty i niedziele — święta nie są liczone).
     */
    public static function lastBusinessDayBefore(DateTimeInterface|string $date): CarbonImmutable
    {
        $day = self::day($date)->subDay();
        while ($day->isWeekend()) {
            $day = $day->subDay();
        }

        return $day;
    }

    /**
     * Data i godzina dla ludzi w czasie polskim: „5.10.2026, 10:00”; bez godziny „5.10.2026”.
     */
    public static function format(?DateTimeInterface $moment, bool $withTime = true): string
    {
        if ($moment === null) {
            return '';
        }
        $local = CarbonImmutable::instance($moment)->setTimezone(self::TIMEZONE);

        return $local->format($withTime ? 'j.n.Y, H:i' : 'j.n.Y');
    }

    /**
     * Termin składania ofert przetargu: „5.10.2026, 10:00”, bez godziny „5.10.2026”, bez daty pusty tekst.
     * Data terminu to dzień „na zegarze” — nie przelicza się jej między strefami.
     */
    public static function formatDeadline(Tender $tender): string
    {
        $date = self::dateString($tender->deadline);
        if ($date === null) {
            return '';
        }
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE);
        $label = $day instanceof CarbonImmutable ? $day->format('j.n.Y') : $date;
        $time = $tender->deadline_time;

        return is_string($time) && $time !== '' ? $label.', '.$time : $label;
    }

    /**
     * Dzień (00:00 czasu polskiego) z daty kalendarzowej. Napis „Y-m-d” i obiekt daty (np. rzutowanie `date`
     * modelu, które trzyma północ UTC) dają ten sam dzień kalendarza, bez przesunięcia strefy.
     */
    private static function day(DateTimeInterface|string $date): CarbonImmutable
    {
        $string = self::dateString($date) ?? (string) $date;

        return CarbonImmutable::parse(substr($string, 0, 10), self::TIMEZONE)->startOfDay();
    }

    private static function dateString(mixed $date): ?string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1) {
            return substr($date, 0, 10);
        }

        return null;
    }
}
