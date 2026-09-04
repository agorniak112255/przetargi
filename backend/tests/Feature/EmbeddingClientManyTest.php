<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Vector\EmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EmbeddingClientManyTest extends TestCase
{
    use RefreshDatabase;

    public function test_embed_many_sends_parallel_requests(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://emb.test/v1',
            'api_key' => 'sk-test-embed',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'embedding_provider' => 'local',
            'embedding_base_url' => 'https://emb.test/v1',
            'embedding_api_key' => 'sk-test-embed',
            'embedding_model' => 'text-embedding-3-small',
        ]);

        Http::fake([
            'https://emb.test/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ]),
        ]);

        $vectors = $this->app->make(EmbeddingClient::class)->embedMany([
            'Rękawice nitrylowe',
            'Rękawice PCV długie',
        ]);

        $this->assertCount(2, $vectors);
        $this->assertSame([0.1, 0.2, 0.3], $vectors['Rękawice nitrylowe']);
        $this->assertSame([0.1, 0.2, 0.3], $vectors['Rękawice PCV długie']);
        Http::assertSentCount(2);
    }
}
