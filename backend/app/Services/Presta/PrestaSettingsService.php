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
     *     webservice_key: string,
     *     prefix: string,
     *     id_lang: int,
     *     shop_url: string,
     *     id_category_default: int,
     *     delivery_label: string,
     *     has_password: bool,
     *     has_webservice_key: bool,
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

        $password = $this->safeEncrypted($row, 'password');
        if ($password === '') {
            $password = $env['password'];
        }
        $key = '';
        if (Schema::hasColumn('presta_shop_settings', 'webservice_key')) {
            $key = $this->safeEncrypted($row, 'webservice_key');
        }
        if ($key === '') {
            $key = $env['webservice_key'];
        }

        $host = trim((string) ($row->host ?? ''));
        $database = trim((string) ($row->database_name ?? ''));
        $delivery = trim((string) ($row->delivery_label ?? ''));
        $category = (int) ($row->id_category_default ?? 0);

        return [
            'enabled' => (bool) $row->enabled,
            'host' => $host !== '' ? $host : $env['host'],
            'port' => (int) ($row->port ?: $env['port']),
            'database' => $database !== '' ? $database : $env['database'],
            'username' => trim((string) ($row->username ?? '')) ?: $env['username'],
            'password' => $password,
            'webservice_key' => $key,
            'prefix' => $this->safePrefix((string) ($row->table_prefix ?: $env['prefix'])),
            'id_lang' => (int) ($row->id_lang ?: $env['id_lang']),
            'shop_url' => rtrim((string) ($row->shop_url ?: $env['shop_url']), '/'),
            'id_category_default' => $category > 0 ? $category : $env['id_category_default'],
            'delivery_label' => $delivery !== '' ? $delivery : $env['delivery_label'],
            'has_password' => $password !== '',
            'has_webservice_key' => $key !== '',
            'source' => 'database',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $cfg = $this->resolve();
        unset($cfg['password'], $cfg['webservice_key']);

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
        if (array_key_exists('webservice_key', $data) && is_string($data['webservice_key']) && $data['webservice_key'] !== '') {
            $row->webservice_key = $data['webservice_key'];
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
        if (array_key_exists('id_category_default', $data)) {
            $row->id_category_default = max(1, (int) $data['id_category_default']);
        }
        if (array_key_exists('delivery_label', $data)) {
            $label = $this->nullableString($data['delivery_label'] ?? null);
            $row->delivery_label = $label ?? 'Na zamówienie';
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
     *     webservice_key: string,
     *     prefix: string,
     *     id_lang: int,
     *     shop_url: string,
     *     id_category_default: int,
     *     delivery_label: string,
     *     has_password: bool,
     *     has_webservice_key: bool,
     *     source: string
     * }
     */
    private function fromEnv(): array
    {
        $password = (string) config('prestashop.password', '');
        $key = (string) config('prestashop.webservice_key', '');
        $label = trim((string) config('prestashop.delivery_label', 'Na zamówienie'));

        return [
            'enabled' => (bool) config('prestashop.enabled', false),
            'host' => (string) config('prestashop.host', ''),
            'port' => (int) config('prestashop.port', 3306),
            'database' => (string) config('prestashop.database', ''),
            'username' => (string) config('prestashop.username', ''),
            'password' => $password,
            'webservice_key' => $key,
            'prefix' => $this->safePrefix((string) config('prestashop.prefix', 'ps_')),
            'id_lang' => max(1, (int) config('prestashop.id_lang', 1)),
            'shop_url' => rtrim((string) config('prestashop.shop_url', 'https://supon.rzeszow.pl'), '/'),
            'id_category_default' => max(1, (int) config('prestashop.id_category_default', 2)),
            'delivery_label' => $label !== '' ? $label : 'Na zamówienie',
            'has_password' => $password !== '',
            'has_webservice_key' => $key !== '',
            'source' => 'env',
        ];
    }

    private function safeEncrypted(PrestaShopSetting $row, string $attribute): string
    {
        try {
            $value = $row->getAttribute($attribute);
        } catch (DecryptException) {
            return '';
        }

        return is_string($value) ? $value : '';
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
