<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Services\Erp\ErpCampaignSalesSync;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Okno liczenia sprzedaży po kampanii w czasie polskim: od dnia maila do odbiorcy do dnia startu wysyłki + 30 dni
 * (oba dni włącznie). Aplikacja trzyma chwile w UTC — mail o 00:30 czasu polskiego to jeszcze poprzedni dzień w UTC,
 * a liczy się dzień na zegarze w Polsce (daty faktur z ERP XL też są polskie).
 * Zwracane dni to północ w strefie Europe/Warsaw; toDateString() daje datę polską.
 */
final class CampaignWindow
{
    /** Długość okna po dniu startu (dni). */
    public const DAYS = ErpCampaignSalesSync::WINDOW_DAYS;

    /** Dzień startu wysyłki (data polska); null = kampania jeszcze nie wysyłana. */
    public static function startDay(Campaign $campaign): ?CarbonImmutable
    {
        return self::polishDay($campaign->sending_started_at);
    }

    /** Ostatni dzień okna (start + DAYS, włącznie); null = kampania jeszcze nie wysyłana. */
    public static function endDay(Campaign $campaign): ?CarbonImmutable
    {
        return self::startDay($campaign)?->addDays(self::DAYS);
    }

    /** Dzień maila do odbiorcy (data polska z campaign_recipients.sent_at); null = brak chwili wysłania. */
    public static function mailDay(?DateTimeInterface $sentAt): ?CarbonImmutable
    {
        return self::polishDay($sentAt);
    }

    private static function polishDay(?DateTimeInterface $moment): ?CarbonImmutable
    {
        if ($moment === null) {
            return null;
        }

        return CarbonImmutable::instance($moment)->setTimezone(PolishTime::TIMEZONE)->startOfDay();
    }
}
