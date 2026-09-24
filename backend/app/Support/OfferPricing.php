<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Proponowana cena oferty = zakup × (1 + marża%/100).
 * Domyślnie +18% (config/pricing.php), albo marża docelowa przetargu.
 */
final class OfferPricing
{
    public static function markup(): float
    {
        $m = (float) config('pricing.offer_markup', 1.18);

        return $m > 0 ? $m : 1.18;
    }

    public static function markupPercent(): float
    {
        return max(0, (float) config('pricing.offer_markup_percent', 18));
    }

    /** Najwyższa marża, jaką wolno wpisać ręcznie. */
    public static function marginMax(): float
    {
        $max = config('pricing.offer_margin_max', 99);

        return is_numeric($max) && (float) $max >= 0 ? (float) $max : 99.0;
    }

    /**
     * Marża wpisana ręcznie: przecinek, znak procentu i spacje (także twarda spacja
     * z Worda) to normalny zapis. Jedno miejsce dla walidacji i dla liczenia ceny —
     * inaczej „30 %” przechodziło walidację, a cenę liczyła marża domyślna.
     */
    public static function percentFromInput(mixed $raw): ?float
    {
        if (is_float($raw) || is_int($raw)) {
            return (float) $raw;
        }
        if (! is_string($raw)) {
            return null;
        }
        $clean = str_replace([',', '%', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', '', ''], trim($raw));

        return $clean !== '' && is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * Komunikat błędu dla marży wpisanej ręcznie albo null, gdy jest poprawna.
     * Wspólny dla marży w liście i domyślnej marży konta — obie liczą tę samą cenę.
     */
    public static function marginInputError(mixed $raw): ?string
    {
        $percent = self::percentFromInput($raw);
        if ($percent === null) {
            return 'Marża musi być liczbą, np. 18 albo 12,5.';
        }
        $max = self::marginMax();
        if ($percent < 0 || $percent > $max) {
            return 'Marża musi mieścić się w zakresie 0–'.rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.').'%.';
        }

        return null;
    }

    /** Najwyższa cena jednostkowa wpisywana ręcznie — wyżej to niemal na pewno literówka. */
    public const MANUAL_PRICE_MAX = 1_000_000.0;

    /**
     * Cena netto w zł wpisana ręcznie przez handlowca: „159”, „159,00”, „1 234,50 zł”.
     * Więcej niż dwa miejsca po przecinku albo cokolwiek poza liczbą = null —
     * do listu idzie dokładnie ta kwota, którą handlowiec zobaczył w polu.
     */
    public static function plnFromInput(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if (! is_string($raw)) {
            return null;
        }
        $clean = preg_replace('/\s*(?:zł|zl|pln)\.?$/iu', '', trim($raw)) ?? '';
        $clean = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], $clean);
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $clean) !== 1) {
            return null;
        }
        $value = (float) $clean;

        return $value > 0 && $value <= self::MANUAL_PRICE_MAX ? round($value, 2) : null;
    }

    /** Komunikat błędu dla ceny wpisanej ręcznie albo null, gdy jest poprawna. */
    public static function plnInputError(mixed $raw): ?string
    {
        return self::plnFromInput($raw) === null
            ? 'Cena musi być kwotą w zł netto większą od zera, np. 159 albo 159,90.'
            : null;
    }

    public static function factorFromPercent(?float $percent): float
    {
        $p = $percent ?? self::markupPercent();
        $factor = 1 + ($p / 100);

        return $factor > 0 ? $factor : 1.0;
    }

    public static function fromPurchase(float|string|null $purchase, ?float $markupPercent = null): ?float
    {
        if ($purchase === null || $purchase === '') {
            return null;
        }
        $p = (float) $purchase;
        if ($p <= 0) {
            return null;
        }

        return round($p * self::factorFromPercent($markupPercent), 2);
    }

    /**
     * Link zewnętrzny: stara cena × (nowy narzut / stary narzut).
     */
    public static function scaleByMarginChange(?float $price, float $oldPercent, float $newPercent): ?float
    {
        if ($price === null || $price <= 0) {
            return $price;
        }
        $old = self::factorFromPercent($oldPercent);
        $new = self::factorFromPercent($newPercent);
        if ($old <= 0) {
            return round($price * $new, 2);
        }

        return round($price * ($new / $old), 2);
    }
}
