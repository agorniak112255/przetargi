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
     *     enrichment_model: ?string,
     *     timeout_seconds: int,
     *     temperature: float,
     *     web_search_enabled: bool,
     *     tavily_api_key: ?string,
     *     search_fallback: string,
     *     has_api_key: bool,
     *     has_tavily_api_key: bool,
     *     source: string
     * }
     */
    public function resolve(): array
    {
        $row = AiSetting::query()->first();
        if ($row !== null) {
            $key = $row->api_key;
            $tavily = $row->tavily_api_key;

            return [
                'enabled' => (bool) $row->enabled,
                'provider' => (string) $row->provider,
                'base_url' => rtrim((string) $row->base_url, '/'),
                'api_key' => $key !== null && $key !== '' ? (string) $key : null,
                'model' => (string) $row->model,
                'enrichment_model' => $this->nullableString($row->enrichment_model ?? null),
                'timeout_seconds' => (int) $row->timeout_seconds,
                'temperature' => (float) $row->temperature,
                'web_search_enabled' => (bool) ($row->web_search_enabled ?? true),
                'tavily_api_key' => $tavily !== null && $tavily !== '' ? (string) $tavily : null,
                'search_fallback' => (string) ($row->search_fallback ?: 'tavily'),
                'has_api_key' => $key !== null && $key !== '',
                'has_tavily_api_key' => $tavily !== null && $tavily !== '',
                'source' => 'database',
            ];
        }

        $key = config('ai.api_key');
        $key = is_string($key) && $key !== '' ? $key : null;
        $tavily = config('ai.tavily_api_key');
        $tavily = is_string($tavily) && $tavily !== '' ? $tavily : null;

        return [
            'enabled' => (bool) config('ai.enabled'),
            'provider' => (string) config('ai.provider'),
            'base_url' => rtrim((string) config('ai.base_url'), '/'),
            'api_key' => $key,
            'model' => (string) config('ai.model'),
            'enrichment_model' => $this->nullableString(config('ai.enrichment_model')),
            'timeout_seconds' => (int) config('ai.timeout_seconds'),
            'temperature' => (float) config('ai.temperature'),
            'web_search_enabled' => (bool) config('ai.web_search_enabled', true),
            'tavily_api_key' => $tavily,
            'search_fallback' => (string) config('ai.search_fallback', 'tavily'),
            'has_api_key' => $key !== null,
            'has_tavily_api_key' => $tavily !== null,
            'source' => 'env',
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     provider: string,
     *     base_url: string,
     *     model: string,
     *     enrichment_model: ?string,
     *     timeout_seconds: int,
     *     temperature: float,
     *     web_search_enabled: bool,
     *     search_fallback: string,
     *     has_api_key: bool,
     *     has_tavily_api_key: bool,
     *     source: string,
     *     api_key_masked: ?string,
     *     tavily_api_key_masked: ?string
     * }
     */
    public function publicView(): array
    {
        $cfg = $this->resolve();

        return [
            'enabled' => $cfg['enabled'],
            'provider' => $cfg['provider'],
            'base_url' => $cfg['base_url'],
            'model' => $cfg['model'],
            'enrichment_model' => $cfg['enrichment_model'],
            'timeout_seconds' => $cfg['timeout_seconds'],
            'temperature' => $cfg['temperature'],
            'web_search_enabled' => $cfg['web_search_enabled'],
            'search_fallback' => $cfg['search_fallback'],
            'has_api_key' => $cfg['has_api_key'],
            'has_tavily_api_key' => $cfg['has_tavily_api_key'],
            'source' => $cfg['source'],
            'api_key_masked' => $this->maskKey($cfg['api_key']),
            'tavily_api_key_masked' => $this->maskKey($cfg['tavily_api_key']),
        ];
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     provider?: string,
     *     base_url?: string,
     *     api_key?: ?string,
     *     model?: string,
     *     enrichment_model?: ?string,
     *     timeout_seconds?: int,
     *     temperature?: float,
     *     web_search_enabled?: bool,
     *     tavily_api_key?: ?string,
     *     search_fallback?: string
     * }  $data
     */
    public function update(array $data): AiSetting
    {
        $row = AiSetting::query()->first() ?? new AiSetting([
            'enabled' => false,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'enrichment_model' => null,
            'timeout_seconds' => 90,
            'temperature' => 0.1,
            'web_search_enabled' => false,
            'search_fallback' => 'tavily',
        ]);

        foreach ([
            'enabled',
            'provider',
            'base_url',
            'model',
            'timeout_seconds',
            'temperature',
            'web_search_enabled',
            'search_fallback',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $row->{$field} = $data[$field];
            }
        }

        if (array_key_exists('enrichment_model', $data)) {
            $row->enrichment_model = $this->nullableString($data['enrichment_model']);
        }

        $this->applySecret($row, 'api_key', $data);
        $this->applySecret($row, 'tavily_api_key', $data);

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

    /** Model do opisów produktów (tani); pusty enrichment_model → model główny. */
    public function enrichmentModel(): string
    {
        $cfg = $this->resolve();
        $cheap = trim((string) ($cfg['enrichment_model'] ?? ''));

        return $cheap !== '' ? $cheap : (string) $cfg['model'];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);

        return $v !== '' ? $v : null;
    }

    private function maskKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        $len = strlen($key);

        return $len <= 8
            ? str_repeat('*', $len)
            : substr($key, 0, 3).str_repeat('*', max(4, $len - 7)).substr($key, -4);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applySecret(AiSetting $row, string $field, array $data): void
    {
        if (! array_key_exists($field, $data)) {
            return;
        }

        $incoming = $data[$field];
        if (is_string($incoming) && trim($incoming) !== '' && ! str_contains($incoming, '*')) {
            $row->{$field} = trim($incoming);
        }
        if ($incoming === null || $incoming === '') {
            $row->{$field} = null;
        }
    }
}
