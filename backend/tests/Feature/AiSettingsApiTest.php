<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AiSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_read_and_update_ai_settings(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
        ]));

        $this->getJson('/api/ai-settings')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('has_api_key', false);

        $this->putJson('/api/ai-settings', [
            'enabled' => true,
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key-1234567890',
            'timeout_seconds' => 60,
            'temperature' => 0.2,
        ])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('has_api_key', true)
            ->assertJsonPath('model', 'gpt-4o-mini');

        $this->getJson('/api/ai-settings')
            ->assertOk()
            ->assertJsonPath('source', 'database')
            ->assertJsonMissingPath('api_key');
    }
}
