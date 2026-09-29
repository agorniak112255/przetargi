<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Znacznik czasu Comarch ERP XL (np. TwZ_DataP — przyjęcie partii): liczba sekund od 1.01.1990. Inny zapis niż daty
 * dokumentów (ClarionDate) — sprawdzone 29.09.2026: partia z PZ z datą 20.08.2026 ma 1156168293 = 21.08.2026 13:51
 * (przyjęcie na magazyn). Czas lokalny XL, tu tylko dzień. 0 i puste = brak daty.
 */
final class XlTimestamp
{
    /** 1.01.2100 — dalej to już nie data, tylko błędny zapis. */
    private const MAX_SECONDS = 3_471_292_800;

    public static function toDate(int|string|null $seconds): ?CarbonImmutable
    {
        if ($seconds === null || $seconds === '' || ! is_numeric($seconds)) {
            return null;
        }
        $seconds = (int) $seconds;
        if ($seconds <= 0 || $seconds >= self::MAX_SECONDS) {
            return null;
        }

        return CarbonImmutable::create(1990, 1, 1)->addSeconds($seconds)->startOfDay();
    }
}
