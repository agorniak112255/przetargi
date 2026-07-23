<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MailSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MailSettingsService
{
    /**
     * @return array{
     *     mailer: string,
     *     host: ?string,
     *     port: int,
     *     username: ?string,
     *     password: ?string,
     *     scheme: ?string,
     *     from_address: ?string,
     *     from_name: ?string,
     *     verify_peer: bool,
     *     has_password: bool,
     *     source: string
     * }
     */
    public function resolve(): array
    {
        if (! Schema::hasTable('mail_settings')) {
            return $this->fromEnv();
        }

        $row = MailSetting::query()->first();
        if ($row === null) {
            return $this->fromEnv();
        }

        $password = $this->safePassword($row);
        if ($password === null || $password === '') {
            $envPassword = config('mail.mailers.smtp.password');
            $password = is_string($envPassword) && $envPassword !== '' ? $envPassword : null;
        }

        return [
            'mailer' => (string) ($row->mailer ?: 'smtp'),
            'host' => $this->nullableString($row->host),
            'port' => (int) ($row->port ?: 587),
            'username' => $this->nullableString($row->username),
            'password' => $password,
            'scheme' => $this->nullableString($row->scheme),
            'from_address' => $this->nullableString($row->from_address),
            'from_name' => $this->nullableString($row->from_name) ?? 'Przetargi Supon',
            'verify_peer' => (bool) $row->verify_peer,
            'has_password' => $password !== null && $password !== '',
            'source' => 'database',
        ];
    }

    /**
     * @return array{
     *     mailer: string,
     *     host: ?string,
     *     port: int,
     *     username: ?string,
     *     has_password: bool,
     *     scheme: ?string,
     *     from_address: ?string,
     *     from_name: ?string,
     *     verify_peer: bool,
     *     source: string
     * }
     */
    public function publicView(): array
    {
        $cfg = $this->resolve();
        unset($cfg['password']);

        return $cfg;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): void
    {
        $row = MailSetting::query()->first() ?? new MailSetting;

        foreach (['mailer', 'host', 'username', 'scheme', 'from_address', 'from_name'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $row->{$key} = is_string($value) && trim($value) === '' ? null : $value;
            }
        }

        if (array_key_exists('port', $data)) {
            $row->port = (int) $data['port'];
        }

        if (array_key_exists('verify_peer', $data)) {
            $row->verify_peer = (bool) $data['verify_peer'];
        }

        if (array_key_exists('password', $data)) {
            $password = $data['password'];
            if (is_string($password) && $password !== '') {
                $row->password = $password;
            }
        }

        $row->save();
        $this->applyToConfig();
    }

    public function applyToConfig(): void
    {
        try {
            if (! Schema::hasTable('mail_settings')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $cfg = $this->resolve();
        if ($cfg['source'] !== 'database') {
            return;
        }

        Config::set('mail.default', $cfg['mailer']);
        Config::set('mail.mailers.smtp.host', $cfg['host']);
        Config::set('mail.mailers.smtp.port', $cfg['port']);
        Config::set('mail.mailers.smtp.username', $cfg['username']);
        Config::set('mail.mailers.smtp.password', $cfg['password']);
        Config::set('mail.mailers.smtp.scheme', $cfg['scheme']);
        Config::set('mail.mailers.smtp.verify_peer', $cfg['verify_peer']);
        Config::set('mail.from.address', $cfg['from_address'] ?? config('mail.from.address'));
        Config::set('mail.from.name', $cfg['from_name'] ?? config('mail.from.name'));

        try {
            Mail::purge();
        } catch (Throwable) {
            // brak zainicjalizowanego mailera — OK przy boot
        }
    }

    /**
     * @return array{
     *     mailer: string,
     *     host: ?string,
     *     port: int,
     *     username: ?string,
     *     password: ?string,
     *     scheme: ?string,
     *     from_address: ?string,
     *     from_name: ?string,
     *     verify_peer: bool,
     *     has_password: bool,
     *     source: string
     * }
     */
    private function fromEnv(): array
    {
        $password = config('mail.mailers.smtp.password');
        $password = is_string($password) && $password !== '' ? $password : null;

        return [
            'mailer' => (string) config('mail.default', 'log'),
            'host' => $this->nullableString(config('mail.mailers.smtp.host')),
            'port' => (int) config('mail.mailers.smtp.port', 587),
            'username' => $this->nullableString(config('mail.mailers.smtp.username')),
            'password' => $password,
            'scheme' => $this->nullableString(config('mail.mailers.smtp.scheme')),
            'from_address' => $this->nullableString(config('mail.from.address')),
            'from_name' => $this->nullableString(config('mail.from.name')) ?? 'Przetargi Supon',
            'verify_peer' => (bool) config('mail.mailers.smtp.verify_peer', true),
            'has_password' => $password !== null,
            'source' => 'env',
        ];
    }

    private function safePassword(MailSetting $row): ?string
    {
        try {
            $value = $row->password;
        } catch (DecryptException) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
