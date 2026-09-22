<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Witryna, której logowanie kończy się kodem jednorazowym wysłanym e-mailem (3M order.3m.com, Azure AD B2C z MFA
 * od kwietnia 2026). Serwer sam kodu nie zobaczy, więc logowanie ma dwa kroki z panelu: startCodeLogin() loguje
 * e-mailem i hasłem aż do prośby o kod i zleca jego wysyłkę; finishCodeLogin() wpisuje kod podany przez użytkownika
 * i zwraca sesję. Sesja jest zapisywana na koncie (B2bAccount::$connector_session, szyfrowana) i z niej łącznik
 * korzysta w przebiegu — login() bez sesji (albo z wygasłą, której witryna nie odnowi bez kodu) zgłasza
 * B2bFatalException z prośbą o zalogowanie kodem, zamiast wysyłać kod w nocnym przebiegu.
 *
 * Stan między krokami (ciasteczka i znaczniki transakcji logowania — bez hasła) trzyma kontroler w cache, zaszyfrowany.
 */
interface B2bCodeLoginSite
{
    /**
     * Logowanie e-mailem i hasłem do prośby o kod oraz zlecenie wysyłki kodu.
     *
     * @return array{state: array<string, mixed>, message: string} stan do finishCodeLogin() (serializowalny do JSON)
     *                                                              i komunikat dla użytkownika (np. dokąd poszedł kod)
     *
     * @throws \RuntimeException gdy logowanie się nie udało (złe hasło, zmiana witryny) — komunikat po polsku
     */
    public function startCodeLogin(): array;

    /**
     * Dokończenie logowania kodem z e-maila.
     *
     * @param  array<string, mixed>  $state  z startCodeLogin()
     * @return array<string, mixed> sesja do zapisania na koncie (serializowalna do JSON)
     *
     * @throws \RuntimeException gdy kod jest błędny albo wygasł — komunikat po polsku
     */
    public function finishCodeLogin(array $state, string $code): array;
}
