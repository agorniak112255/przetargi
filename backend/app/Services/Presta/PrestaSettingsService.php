<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\PrestaShopSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Schema;

final class PrestaSettingsService
{
    /**
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     database: string,
     *     username: string,
     *     password: string,
     *     prefix: string,
     *     id_lang: int,
     *     shop_url: string,
     *     has_password: bool,
     *     source: string
     * }
     */
    public function resolve(): array
    {
        $env = $this->fromEnv();
        if (! Schema::hasTable('presta_shop_settings')) {
            return $env;
        }

        $row = PrestaShopSetting::query()->first();
        if ($row === null) {
            return $env;
        }

        $password = $this->safePassword($row);
        if ($password === '') {
            $password = $env['password'];
        }

        $host = trim((string) ($row->host ?? ''));
        $database = trim((string) ($row->database_name ?? ''));

        return [
            'enabled' => (bool) $row->enabled,
            'host' => $host !== '' ? $host : $env['host'],
            'port' => (int) ($row->port ?: $env['port']),
            'database' => $database !== '' ? $database : $env['database'],
            'username' => trim((string) ($row->username ?? '')) ?: $env['username'],
            'password' => $password,
            'prefix' => $this->safePrefix((string) ($row->table_prefix ?: $env['prefix'])),
            'id_lang' => (int) ($row->id_lang ?: $env['id_lang']),
            'shop_url' => rtrim((string) ($row->shop_url ?: $env['shop_url']), '/'),
            'has_password' => $password !== '',
            'source' => 'database',
        ];
    }

    /**
     * @return array<string, mixed>
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
        $row = PrestaShopSetting::query()->first() ?? new PrestaShopSetting;
        if (array_key_exists('enabled', $data)) {
            $row->enabled = (bool) $data['enabled'];
        }
        if (array_key_exists('host', $data)) {
            $row->host = $this->nullableString($data['host'] ?? null);
        }
        if (array_key_exists('port', $data)) {
            $row->port = (int) $data['port'];
        }
        if (array_key_exists('database', $data)) {
            $row->database_name = $this->nullableString($data['database'] ?? null);
        }
        if (array_key_exists('username', $data)) {
            $row->username = $this->nullableString($data['username'] ?? null);
        }
        if (array_key_exists('password', $data) && is_string($data['password']) && $data['password'] !== '') {
            $row->password = $data['password'];
        }
        if (array_key_exists('prefix', $data)) {
            $row->table_prefix = $this->safePrefix((string) ($data['prefix'] ?? 'ps_'));
        }
        if (array_key_exists('id_lang', $data)) {
            $row->id_lang = max(1, (int) $data['id_lang']);
        }
        if (array_key_exists('shop_url', $data)) {
            $row->shop_url = $this->nullableString($data['shop_url'] ?? null);
        }
        $row->save();
    }

    /**
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     database: string,
     *     username: string,
     *     password: string,
     *     prefix: string,
     *     id_lang: int,
     *     shop_url: string,
     *     has_password: bool,
     *     source: string
     * }
     */
    private function fromEnv(): array
    {
        $password = (string) config('prestashop.password', '');

        return [
            'enabled' => (bool) config('prestashop.enabled', false),
            'host' => (string) config('prestashop.host', ''),
            'port' => (int) config('prestashop.port', 3306),
            'database' => (string) config('prestashop.database', ''),
            'username' => (string) config('prestashop.username', ''),
            'password' => $password,
            'prefix' => $this->safePrefix((string) config('prestashop.prefix', 'ps_')),
            'id_lang' => max(1, (int) config('prestashop.id_lang', 1)),
            'shop_url' => rtrim((string) config('prestashop.shop_url', 'https://supon.rzeszow.pl'), '/'),
            'has_password' => $password !== '',
            'source' => 'env',
        ];
    }

    private function safePassword(PrestaShopSetting $row): string
    {
        try {
            $password = $row->password;
        } catch (DecryptException) {
            return '';
        }

        return is_string($password) ? $password : '';
    }

    private function safePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '' || preg_match('/^[a-zA-Z0-9_]{1,16}$/', $prefix) !== 1) {
            return 'ps_';
        }

        return $prefix;
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
