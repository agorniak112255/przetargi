<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AiSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_read_and_update_ai_settings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/ai-settings')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('has_api_key', false);

        $this->putJson('/api/ai-settings', [
            'enabled' => true,
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'enrichment_model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-key-1234567890',
            'timeout_seconds' => 60,
            'temperature' => 0.2,
            'vector_enabled' => true,
            'qdrant_url' => 'http://127.0.0.1:6333',
            'qdrant_collection' => 'products',
            'embedding_model' => 'text-embedding-3-small',
        ])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('has_api_key', true)
            ->assertJsonPath('model', 'gpt-4o-mini')
            ->assertJsonPath('enrichment_model', 'openai/gpt-4o-mini')
            ->assertJsonPath('vector_enabled', true)
            ->assertJsonPath('qdrant_url', 'http://127.0.0.1:6333')
            ->assertJsonPath('embedding_model', 'text-embedding-3-small');

        $this->getJson('/api/ai-settings')
            ->assertOk()
            ->assertJsonPath('source', 'database')
            ->assertJsonPath('enrichment_model', 'openai/gpt-4o-mini')
            ->assertJsonPath('vector_enabled', true)
            ->assertJsonMissingPath('api_key')
            ->assertJsonMissingPath('qdrant_api_key');
    }
}
