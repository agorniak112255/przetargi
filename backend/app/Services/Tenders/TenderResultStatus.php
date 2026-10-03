<?php

declare(strict_types=1);

namespace App\Services\Tenders;

use App\Models\Tender;
use App\Models\TenderLot;

/**
 * Wynik przetargu w całości, wyliczany z wyników części (tenders.result_status — denormalizacja przeliczana
 * po każdym zapisie części).
 *
 * Liczą się najpierw tylko części wygrane i przegrane: wszystkie z nich wygrane → `won`, wygrane i przegrane →
 * `partial`, same przegrane → `lost` (części unieważnione, bez oferty i jeszcze bez wyniku nie zmieniają tego).
 * Gdy żadna część nie jest wygrana ani przegrana: wszystkie części unieważnione → `cancelled`; wszystkie bez
 * naszej oferty (ewentualnie część unieważniona) → `not_submitted`; inaczej (np. część bez wyniku) → null.
 */
final class TenderResultStatus
{
    public const WON = 'won';

    public const PARTIAL = 'partial';

    public const LOST = 'lost';

    public const CANCELLED = 'cancelled';

    public const NOT_SUBMITTED = 'not_submitted';

    public const ALL = [self::WON, self::PARTIAL, self::LOST, self::CANCELLED, self::NOT_SUBMITTED];

    /**
     * @param  iterable<TenderLot|array{outcome?: ?string}|string|null>  $lots  części albo same wyniki części
     */
    public static function compute(iterable $lots): ?string
    {
        $total = 0;
        $counts = array_fill_keys(TenderLot::OUTCOMES, 0);
        foreach ($lots as $lot) {
            $total++;
            $outcome = self::outcomeOf($lot);
            if ($outcome !== null && array_key_exists($outcome, $counts)) {
                $counts[$outcome]++;
            }
        }

        $won = $counts[TenderLot::OUTCOME_WON];
        $lost = $counts[TenderLot::OUTCOME_LOST];
        if ($won + $lost > 0) {
            if ($lost === 0) {
                return self::WON;
            }

            return $won === 0 ? self::LOST : self::PARTIAL;
        }

        $cancelled = $counts[TenderLot::OUTCOME_CANCELLED];
        $notSubmitted = $counts[TenderLot::OUTCOME_NOT_SUBMITTED];
        if ($total === 0) {
            return null;
        }
        if ($cancelled === $total) {
            return self::CANCELLED;
        }
        if ($notSubmitted > 0 && $notSubmitted + $cancelled === $total) {
            return self::NOT_SUBMITTED;
        }

        return null;
    }

    /**
     * Przelicza i zapisuje tenders.result_status z części w bazie; zwraca nowy wynik.
     */
    public static function recompute(Tender $tender): ?string
    {
        $status = self::compute($tender->lots()->pluck('outcome')->all());
        if ($tender->result_status !== $status) {
            $tender->forceFill(['result_status' => $status])->save();
        }

        return $status;
    }

    private static function outcomeOf(mixed $lot): ?string
    {
        if ($lot instanceof TenderLot) {
            $value = $lot->outcome;
        } elseif (is_array($lot)) {
            $value = $lot['outcome'] ?? null;
        } else {
            $value = $lot;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
