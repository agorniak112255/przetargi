<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\UserMailAccount;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Mailer SMTP ze skrzynki użytkownika (Mail::build). Mail::fake() go nie przechwytuje — testy podmieniają tę klasę
 * w kontenerze na mailer z transportem „array”. Nie final.
 */
class UserMailerFactory
{
    /** Sekundy na połączenie i odpowiedź serwera — przebieg co minutę nie może wisieć na jednej skrzynce. */
    public const TIMEOUT = 20;

    private readonly SmtpHostGuard $hosts;

    public function __construct(?SmtpHostGuard $hosts = null)
    {
        $this->hosts = $hosts ?? new SmtpHostGuard;
    }

    /**
     * Odczyt hasła może rzucić DecryptException (zmieniony APP_KEY), a niedozwolony serwer TransportException —
     * wołający traktuje oba jak błąd skrzynki nadawcy.
     */
    public function make(UserMailAccount $account): Mailer
    {
        // ponownie przy każdym połączeniu: nazwa zapisana jako publiczna mogła zacząć wskazywać sieć wewnętrzną
        $this->hosts->assertAllowed((string) $account->host);

        $scheme = in_array($account->scheme, UserMailAccount::SCHEMES, true) ? $account->scheme : null;
        $implicitTls = $scheme === 'smtps' || (int) $account->port === 465;
        $localDomain = parse_url((string) config('campaigns.public_url'), PHP_URL_HOST);

        return Mail::build(array_filter([
            'transport' => 'smtp',
            'name' => 'campaigns-user-'.$account->user_id,
            'host' => (string) $account->host,
            'port' => (int) $account->port,
            'scheme' => $scheme,
            'username' => (string) $account->username,
            'password' => (string) $account->password,
            'timeout' => self::TIMEOUT,
            'verify_peer' => (bool) $account->verify_peer,
            // bez SSL od początku połączenia STARTTLS jest obowiązkowy — hasło nigdy nie idzie otwartym tekstem
            // (opcja trafia do Dsn, EsmtpTransportFactory czyta require_tls)
            'require_tls' => $implicitTls ? null : true,
            // EHLO z nazwą publicznego serwera zamiast „localhost” (filtry antyspamowe)
            'local_domain' => is_string($localDomain) && $localDomain !== '' ? $localDomain : null,
        ], static fn ($v): bool => $v !== null));
    }
}
