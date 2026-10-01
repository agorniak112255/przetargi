<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Platforma Comarch B2B producenta Brubeck (FILATI Mirosław Kubiak S.K.A.) — ta sama wersja platformy co Fagum
 * (CompilationVersion 2025.4.0.5, ERP XL: applicationId 0, endpointy …Xl), więc logowanie, sesja i zapytania są
 * wspólne (ComarchB2bClient). Sprawdzone na zalogowanym koncie 01.10.2026.
 *
 * Adres: konto ma witrynę https://46.45.74.25 (sam adres IP), a serwer przedstawia certyfikat *.brubeck.pl
 * (RapidSSL), który do adresu IP nie pasuje. Ten sam serwer ma nazwę b2b.brubeck.pl (DNS → 46.45.74.25, ta sama
 * strona), zgodną z certyfikatem — zapytania idą więc pod https://b2b.brubeck.pl z pełną weryfikacją TLS (bez
 * wyłączania jej dla adresu IP). Adresy zapisywane na kartach (strona towaru, zdjęcia) zostają na hoście konta
 * 46.45.74.25, żeby rejestr łączników rozpoznawał je jako adresy łącznika (B2bConnectorRegistry::isConnectorUrl);
 * fileBytes tłumaczy je na adres z nazwą.
 *
 * Kod kontrahenta = kod klienta w sklepie (np. „FIRMA_MIASTO”, nie NIP), użytkownik = pracownik („Jan Kowalski”).
 * Zdjęcia (/imagehandler.ashx) wydaje tylko zalogowanemu — bez sesji HTTP 200 z pustą treścią (jak u Fagum).
 */
final class BrubeckB2bClient extends ComarchB2bClient
{
    /** Host konta (witryna zapisana przy koncie B2B) i adresów zapisywanych na kartach. */
    public const HOST = '46.45.74.25';

    public const BASE = 'https://46.45.74.25';

    /** Nazwa tego samego serwera zgodna z certyfikatem — pod nią idą zapytania. */
    public const REQUEST_HOST = 'b2b.brubeck.pl';

    private const REQUEST_BASE = 'https://b2b.brubeck.pl';

    /** https na 46.45.74.25 albo b2b.brubeck.pl (bez danych logowania i nietypowego portu w adresie). */
    public static function isShopUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), [self::HOST, self::REQUEST_HOST], true);
    }

    protected function siteName(): string
    {
        return self::HOST;
    }

    protected function requestBase(): string
    {
        return self::REQUEST_BASE;
    }

    protected function customerCodeHint(): string
    {
        return '';
    }

    /** Adres zdjęcia z karty (host konta) → ten sam adres pod nazwą zgodną z certyfikatem. */
    protected function requestUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return self::REQUEST_BASE.($path !== '' ? $path : '/').(is_string($query) && $query !== '' ? '?'.$query : '');
    }
}
