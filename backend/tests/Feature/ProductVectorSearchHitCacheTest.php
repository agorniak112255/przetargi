<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Vector\ProductVectorSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Pamięć trafień wektorowych ma limit: erp:suggest (4.10.2026) przeszukiwał 2000 towarów jedną instancją wyszukiwarki,
 * a każde zapytanie zostawało w pamięci (130 zapytań = 8 MB) — przebieg padał na 128 MB PHP CLI.
 */
final class ProductVectorSearchHitCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'vector_enabled' => true,
            'qdrant_url' => 'http://qdrant.test:6333',
            'qdrant_collection' => 'products',
            'embedding_model' => 'text-embedding-3-small',
        ]);
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => array_fill(0, 8, 0.1)]]]),
            'qdrant.test:6333/collections/products' => Http::response(['result' => ['status' => 'green']]),
            'qdrant.test:6333/collections/products/points/search' => Http::response(['result' => [['id' => 7, 'score' => 0.9]]]),
        ]);
    }

    public function test_cache_keeps_at_most_the_limit_and_drops_the_oldest_queries(): void
    {
        $search = app(ProductVectorSearch::class);
        for ($i = 0; $i < 300; $i++) {
            $search->similar('zapytanie '.$i);
        }

        $this->assertCount(256, $this->cache($search));
        $this->assertSame(300, $this->searches());

        // najnowsze z pamięci, najstarsze liczone od nowa
        $this->assertSame([['id' => 7, 'score' => 0.9]], $search->similar('zapytanie 299'));
        $this->assertSame(300, $this->searches());
        $search->similar('zapytanie 0');
        $this->assertSame(301, $this->searches());
    }

    public function test_prefetched_wave_stays_whole_when_the_cache_is_full(): void
    {
        $search = app(ProductVectorSearch::class);
        for ($i = 0; $i < 250; $i++) {
            $search->similar('stare '.$i);
        }
        $wave = array_map(static fn (int $i): string => 'fala '.$i, range(1, 20));

        $search->prefetch($wave);
        $before = $this->searches();
        foreach ($wave as $text) {
            $search->similar($text);
        }

        $this->assertSame($before, $this->searches());
        $this->assertCount(256, $this->cache($search));
    }

    /** @return array<string, mixed> */
    private function cache(ProductVectorSearch $search): array
    {
        return (new ReflectionProperty($search, 'hitCache'))->getValue($search);
    }

    private function searches(): int
    {
        return count(Http::recorded(static fn (Request $request): bool => str_ends_with($request->url(), '/points/search')));
    }
}
