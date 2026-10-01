<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Platforma Comarch B2B producenta Fagum-Stomil b2b.fagum.pl (aplikacja Angular na API JSON, ERP Comarch XL).
 * Nieoficjalne: adresy i pola odczytane ze skryptów strony (main, chunk z usługami konta i towarów) i z odpowiedzi
 * zalogowanego konta 01.10.2026. Logowanie, sesja i zapytania — ComarchB2bClient (wspólne z Brubeckiem).
 *
 * Kod kontrahenta = NIP firmy, użytkownik = pracownik („Jan Kowalski”). Zdjęcia (/imagehandler.ashx) i pliki
 * (/filehandler.ashx z hashem pliku) wydaje tylko zalogowanemu.
 */
final class FagumB2bClient extends ComarchB2bClient
{
    public const HOST = 'b2b.fagum.pl';

    public const BASE = 'https://b2b.fagum.pl';

    /** https na b2b.fagum.pl (bez danych logowania i nietypowego portu w adresie). */
    public static function isShopUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return strtolower($parts['host'] ?? '') === self::HOST;
    }

    protected function siteName(): string
    {
        return self::HOST;
    }

    protected function requestBase(): string
    {
        return self::BASE;
    }

    protected function customerCodeHint(): string
    {
        return 'NIP';
    }
}
