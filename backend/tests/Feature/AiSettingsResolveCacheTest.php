<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AiSettingsResolveCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_resolve_skips_schema_checks_and_decryption(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-or-test-1234567890',
            'model' => 'deepseek/deepseek-v4-flash-0731',
            'timeout_seconds' => 240,
            'temperature' => 0.1,
        ]);
        $settings = app(AiSettingsService::class);
        $settings->resolve();

        DB::enableQueryLog();
        $again = $settings->resolve();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // wzbogacanie woła resolve() setki razy na produkt — kolejne wywołanie to jeden SELECT wiersza
        $this->assertLessThanOrEqual(1, $queries);
        $this->assertSame('sk-or-test-1234567890', $again['api_key']);
    }

    public function test_changed_settings_row_is_read_again(): void
    {
        $row = AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-or-test-1234567890',
            'model' => 'model-a',
            'timeout_seconds' => 240,
            'temperature' => 0.1,
        ]);
        $settings = app(AiSettingsService::class);
        $this->assertSame('model-a', $settings->resolve()['model']);

        $row->update(['model' => 'model-b']);

        // zmiana w panelu działa od razu, także w innym egzemplarzu serwisu
        $this->assertSame('model-b', app(AiSettingsService::class)->resolve()['model']);
    }
}
