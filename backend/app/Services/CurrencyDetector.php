<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Identyfikacja waluty z tekstu cennika / komórki / nagłówka.
 */
final class CurrencyDetector
{
    private const SUPPORTED = ['PLN', 'EUR', 'USD', 'GBP', 'CHF', 'CZK', 'SEK', 'NOK', 'DKK', 'ZAR'];

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

        // 446.00R = rand (ZAR). „EURO” bywa marką obuwia, nie walutą.
        if (preg_match('/\bZAR\b/u', $upper) === 1
            || preg_match_all('/\d+[.,]\d{2}\s*R\b/u', $upper) >= 2) {
            return 'ZAR';
        }

        if (preg_match('/\bEUR\b|€/u', $upper) === 1 || str_contains($raw, '€')) {
            return 'EUR';
        }
        if (preg_match('/\bEURO\b/u', $upper) === 1) {
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
