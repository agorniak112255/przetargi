<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Data w zapisie Clarion (Comarch ERP XL, np. TrN_Data2): liczba dni od 28.12.1800. 0 i puste = brak daty.
 */
final class ClarionDate
{
    public static function toDate(int|string|null $days): ?CarbonImmutable
    {
        if ($days === null || $days === '' || ! is_numeric($days)) {
            return null;
        }
        $days = (int) $days;
        // poza zakresem dat dokumentów (1900–2100) to nie jest data, tylko błędny zapis
        if ($days < 36163 || $days > 109576) {
            return null;
        }

        return CarbonImmutable::create(1800, 12, 28)->addDays($days)->startOfDay();
    }
}
