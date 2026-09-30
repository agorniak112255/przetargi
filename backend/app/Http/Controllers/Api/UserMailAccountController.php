<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignReplySync;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\SmtpHostGuard;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * „Moje konto → Moja poczta”: skrzynka SMTP, z której wychodzą kampanie użytkownika, i odczyt odpowiedzi (IMAP). Hasło nigdy nie wraca w odpowiedzi
 * (has_password); puste hasło przy zapisie = bez zmiany. Zmiana połączenia kasuje potwierdzenie testem.
 */
class UserMailAccountController extends Controller
{
    private const NO_NEWLINE = '/[\r\n]/';

    /** Pola, których zmiana wymaga ponownego testu skrzynki. */
    private const CONNECTION_FIELDS = ['from_address', 'host', 'port', 'scheme', 'username', 'password', 'verify_peer'];

    /** Odczyt odpowiedzi: tylko IMAP z SSL od początku połączenia. */
    private const IMAP_PORTS = [993];

    public function __construct(
        private readonly CampaignSender $sender,
        private readonly CampaignReplySync $replies,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->present($this->account($request->user())));
    }

    public function update(Request $request, SmtpHostGuard $hosts): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->account($user);
        $v = $request->validate([
            'from_name' => ['required', 'string', 'max:150', 'not_regex:'.self::NO_NEWLINE],
            'from_address' => ['required', 'string', 'email', 'max:255', 'not_regex:'.self::NO_NEWLINE],
            // nazwa hosta — bez spacji, schematu i ścieżki (adres IP i sieć wewnętrzną odrzuca SmtpHostGuard niżej)
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/'],
            'port' => ['required', 'integer', Rule::in(array_map('intval', (array) config('campaigns.smtp_ports')))],
            'scheme' => ['nullable', 'string', Rule::in(UserMailAccount::SCHEMES)],
            'username' => ['required', 'string', 'max:255', 'not_regex:'.self::NO_NEWLINE],
            'password' => ['nullable', 'string', 'max:255'],
            'verify_peer' => ['sometimes', 'boolean'],
            'rate_per_hour' => ['required', 'integer', 'min:1', 'max:2000'],
            'copy_to_self' => ['sometimes', 'boolean'],
            'signature' => ['nullable', 'string', 'max:2000'],
            // odczyt odpowiedzi klientów (IMAP, tylko nagłówki); host pusty = ten sam co SMTP
            'imap_enabled' => ['sometimes', 'boolean'],
            'imap_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/'],
            'imap_port' => ['sometimes', 'integer', Rule::in(self::IMAP_PORTS)],
        ], [
            'imap_host.regex' => 'Podaj sam adres serwera IMAP, np. imap.firma.pl.',
            'imap_port.in' => 'Odczyt odpowiedzi działa przez IMAP z SSL (port 993).',
            'from_name.not_regex' => 'Nazwa nadawcy musi być jedną linią.',
            'host.regex' => 'Podaj sam adres serwera, np. smtp.firma.pl.',
            'port.in' => 'Dozwolone porty: '.implode(', ', (array) config('campaigns.smtp_ports')).'.',
        ]);

        $hostProblem = $hosts->problem($v['host']);
        if ($hostProblem !== null) {
            throw ValidationException::withMessages(['host' => [$hostProblem]]);
        }
        $imapHost = isset($v['imap_host']) && trim((string) $v['imap_host']) !== '' ? mb_strtolower(trim((string) $v['imap_host'])) : null;
        if ($imapHost !== null && ($imapProblem = $hosts->problem($imapHost)) !== null) {
            throw ValidationException::withMessages(['imap_host' => [$imapProblem]]);
        }

        $password = (string) ($v['password'] ?? '');
        if ($password === '' && ($account === null || blank($account->getRawOriginal('password')))) {
            throw ValidationException::withMessages(['password' => ['Podaj hasło do skrzynki.']]);
        }
        $passwordChanged = false;
        if ($password !== '' && $account !== null) {
            try {
                $passwordChanged = $account->password !== $password;
            } catch (DecryptException) {
                // zapisane hasło nieczytelne (zmieniony APP_KEY): nowe je zastępuje; bez starej wartości w „original”
                // porównanie przy zapisie nie próbuje go odszyfrować
                $raw = $account->getAttributes();
                unset($raw['password']);
                $account->setRawAttributes($raw, true);
                $passwordChanged = true;
            }
        }

        $data = [
            'from_name' => trim($v['from_name']),
            'from_address' => mb_strtolower(trim($v['from_address'])),
            'host' => mb_strtolower(trim($v['host'])),
            'port' => (int) $v['port'],
            'scheme' => $v['scheme'] ?? null,
            'username' => trim($v['username']),
            'verify_peer' => (bool) ($v['verify_peer'] ?? $account?->verify_peer ?? true),
            'rate_per_hour' => (int) $v['rate_per_hour'],
            'copy_to_self' => (bool) ($v['copy_to_self'] ?? $account?->copy_to_self ?? true),
            'signature' => isset($v['signature']) && trim($v['signature']) !== '' ? rtrim($v['signature']) : null,
            'imap_enabled' => (bool) ($v['imap_enabled'] ?? $account?->imap_enabled ?? true),
            'imap_host' => array_key_exists('imap_host', $v) ? $imapHost : $account?->imap_host,
            'imap_port' => (int) ($v['imap_port'] ?? $account?->imap_port ?? 993),
        ];
        if ($password !== '') {
            $data['password'] = $password;
        }

        $account ??= new UserMailAccount(['user_id' => $user->id]);
        $account->fill($data);
        // hasło porównane wyżej — isDirty('password') odszyfrowałoby starą wartość
        if (! $account->exists || $passwordChanged || $account->isDirty(array_diff(self::CONNECTION_FIELDS, ['password']))) {
            $account->verified_at = null;
            $account->last_error = null;
        }
        // inna skrzynka IMAP = inna numeracja wiadomości — odczyt odpowiedzi zaczyna się od nowa
        if ($account->exists && $account->isDirty(['imap_host', 'imap_port', 'username', 'host'])) {
            $account->imap_folders = null;
            $account->imap_error = null;
        }
        $account->user_id = $user->id;
        $account->save();

        return response()->json($this->present($account->fresh()));
    }

    /** Krótki mail na adres nadawcy (wysyłka) i logowanie IMAP (odczyt odpowiedzi, gdy włączony). */
    public function test(Request $request): JsonResponse
    {
        $account = $this->account($request->user());
        if ($account === null) {
            abort(422, 'Najpierw zapisz ustawienia skrzynki.');
        }
        $result = $this->sender->testAccount($account);
        $imap = $account->imap_enabled ? $this->replies->test($account->fresh() ?? $account) : null;

        return response()->json(['ok' => (bool) $result['ok'], 'message' => (string) $result['message'], 'imap' => $imap]);
    }

    private function account(User $user): ?UserMailAccount
    {
        return UserMailAccount::query()->where('user_id', $user->id)->first();
    }

    /** @return array<string, mixed> */
    private function present(?UserMailAccount $a): array
    {
        return [
            'configured' => $a !== null,
            'from_name' => $a?->from_name,
            'from_address' => $a?->from_address,
            'host' => $a?->host,
            'port' => $a !== null ? (int) $a->port : 587,
            'scheme' => $a?->scheme,
            'username' => $a?->username,
            'has_password' => $a !== null && ! blank($a->getRawOriginal('password')),
            'verify_peer' => $a !== null ? (bool) $a->verify_peer : true,
            'rate_per_hour' => $a !== null ? (int) $a->rate_per_hour : (int) config('campaigns.default_rate_per_hour'),
            'copy_to_self' => $a !== null ? (bool) $a->copy_to_self : true,
            'signature' => $a?->signature,
            'verified_at' => $a?->verified_at?->toIso8601String(),
            'last_error' => $a?->last_error,
            'imap_enabled' => $a !== null ? (bool) $a->imap_enabled : true,
            'imap_host' => $a?->imap_host,
            'imap_port' => $a !== null ? (int) $a->imap_port : 993,
            'imap_checked_at' => $a?->imap_checked_at?->toIso8601String(),
            'imap_error' => $a?->imap_error,
        ];
    }
}
