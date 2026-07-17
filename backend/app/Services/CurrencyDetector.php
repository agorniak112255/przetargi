<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Identyfikacja waluty z tekstu cennika / komórki / nagłówka.
 */
final class CurrencyDetector
{
    private const SUPPORTED = ['PLN', 'EUR', 'USD', 'GBP', 'CHF', 'CZK', 'SEK', 'NOK', 'DKK'];

    public function detect(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $raw = trim($text);
        if ($raw === '') {
            return null;
        }

        $upper = mb_strtoupper($raw);

        if (preg_match('/\bEUR\b|EURO\b|€/u', $upper) === 1 || str_contains($raw, '€')) {
            return 'EUR';
        }
        if (preg_match('/\bPLN\b|\bZL\b|\bZLOTY|\bZŁOT/u', $upper) === 1
            || str_contains(mb_strtolower($raw), 'zł')
            || str_contains($raw, 'zł')) {
            return 'PLN';
        }
        if (preg_match('/\bUSD\b|US\s*\$|DOLLAR/u', $upper) === 1 || str_contains($raw, '$')) {
            return 'USD';
        }
        if (preg_match('/\bGBP\b|POUND/u', $upper) === 1 || str_contains($raw, '£')) {
            return 'GBP';
        }
        if (preg_match('/\bCHF\b/u', $upper) === 1) {
            return 'CHF';
        }
        if (preg_match('/\bCZK\b/u', $upper) === 1) {
            return 'CZK';
        }
        if (preg_match('/\bSEK\b/u', $upper) === 1) {
            return 'SEK';
        }
        if (preg_match('/\bNOK\b/u', $upper) === 1) {
            return 'NOK';
        }
        if (preg_match('/\bDKK\b/u', $upper) === 1) {
            return 'DKK';
        }

        return null;
    }

    public function normalize(?string $code, ?string $fallback = 'PLN'): ?string
    {
        if ($code === null || trim($code) === '') {
            return $fallback;
        }
        $code = strtoupper(trim($code));
        if (strlen($code) > 3) {
            $detected = $this->detect($code);

            return $detected ?? $fallback;
        }

        return in_array($code, self::SUPPORTED, true) ? $code : $fallback;
    }

    /**
     * @return list<string>
     */
    public function supported(): array
    {
        return self::SUPPORTED;
    }
}
