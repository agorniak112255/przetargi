<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiSetting;

final class AiSettingsService
{
    /**
     * @return array{
     *     enabled: bool,
     *     provider: string,
     *     base_url: string,
     *     api_key: ?string,
     *     model: string,
     *     timeout_seconds: int,
     *     temperature: float,
     *     has_api_key: bool,
     *     source: string
     * }
     */
    public function resolve(): array
    {
        $row = AiSetting::query()->first();
        if ($row !== null) {
            $key = $row->api_key;

            return [
                'enabled' => (bool) $row->enabled,
                'provider' => (string) $row->provider,
                'base_url' => rtrim((string) $row->base_url, '/'),
                'api_key' => $key !== null && $key !== '' ? (string) $key : null,
                'model' => (string) $row->model,
                'timeout_seconds' => (int) $row->timeout_seconds,
                'temperature' => (float) $row->temperature,
                'has_api_key' => $key !== null && $key !== '',
                'source' => 'database',
            ];
        }

        $key = config('ai.api_key');
        $key = is_string($key) && $key !== '' ? $key : null;

        return [
            'enabled' => (bool) config('ai.enabled'),
            'provider' => (string) config('ai.provider'),
            'base_url' => rtrim((string) config('ai.base_url'), '/'),
            'api_key' => $key,
            'model' => (string) config('ai.model'),
            'timeout_seconds' => (int) config('ai.timeout_seconds'),
            'temperature' => (float) config('ai.temperature'),
            'has_api_key' => $key !== null,
            'source' => 'env',
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     provider: string,
     *     base_url: string,
     *     model: string,
     *     timeout_seconds: int,
     *     temperature: float,
     *     has_api_key: bool,
     *     source: string,
     *     api_key_masked: ?string
     * }
     */
    public function publicView(): array
    {
        $cfg = $this->resolve();
        $masked = null;
        if ($cfg['has_api_key'] && is_string($cfg['api_key'])) {
            $len = strlen($cfg['api_key']);
            $masked = $len <= 8
                ? str_repeat('*', $len)
                : substr($cfg['api_key'], 0, 3).str_repeat('*', max(4, $len - 7)).substr($cfg['api_key'], -4);
        }

        return [
            'enabled' => $cfg['enabled'],
            'provider' => $cfg['provider'],
            'base_url' => $cfg['base_url'],
            'model' => $cfg['model'],
            'timeout_seconds' => $cfg['timeout_seconds'],
            'temperature' => $cfg['temperature'],
            'has_api_key' => $cfg['has_api_key'],
            'source' => $cfg['source'],
            'api_key_masked' => $masked,
        ];
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     provider?: string,
     *     base_url?: string,
     *     api_key?: ?string,
     *     model?: string,
     *     timeout_seconds?: int,
     *     temperature?: float
     * }  $data
     */
    public function update(array $data): AiSetting
    {
        $row = AiSetting::query()->first() ?? new AiSetting([
            'enabled' => false,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 90,
            'temperature' => 0.1,
        ]);

        foreach (['enabled', 'provider', 'base_url', 'model', 'timeout_seconds', 'temperature'] as $field) {
            if (array_key_exists($field, $data)) {
                $row->{$field} = $data[$field];
            }
        }

        if (array_key_exists('api_key', $data)) {
            $incoming = $data['api_key'];
            if (is_string($incoming) && trim($incoming) !== '' && ! str_contains($incoming, '*')) {
                $row->api_key = trim($incoming);
            }
            if ($incoming === null || $incoming === '') {
                $row->api_key = null;
            }
        }

        $row->base_url = rtrim((string) $row->base_url, '/');
        $row->save();

        return $row;
    }

    public function isReady(): bool
    {
        $cfg = $this->resolve();

        return $cfg['enabled']
            && $cfg['has_api_key']
            && $cfg['base_url'] !== ''
            && $cfg['model'] !== '';
    }
}
