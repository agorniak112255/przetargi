<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\Search\AiProductSearch;
use App\Services\Search\RequirementUnderstandingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Produkcja 24.09.2026: „spodnie do pasa z polipropylenu” zapisane w zrozumieniu jako „spodnie robocze”. Wektor
 * szukał po tej parafrazie, więc karta Reis SFI („100% polipropylen”) nie weszła do puli. Treść wymagania to źródło —
 * druga lista wektorowa szuka po niej.
 */
final class ProductAiSearchQueryVectorTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Spodnie do pasa z polipropylenu, gumka w pasie, nogawki do skrócenia';

    public function test_card_found_only_by_requirement_text_vector_enters_pool(): void
    {
        // Jak w ProductVectorSearchApiTest: przy włączonych wektorach Product::create() reindeksuje kartę,
        // zanim test zarejestruje Http::fake. Test sprawdza wyszukiwanie, nie indeksowanie.
        Queue::fake([ReindexProductEmbeddingJob::class]);
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
        $this->freezeTime();

        $work = [];
        for ($i = 1; $i <= 120; $i++) {
            $work[] = Product::query()->create([
                'sku' => 'MACH-'.$i,
                'name' => 'SPODNIE ROBOCZE MACH '.$i,
                'manufacturer' => 'Delta Plus',
                'category' => 'Odzież robocza',
                'description' => 'Spodnie robocze Mach '.$i.' z poliestru i bawełny.',
                'catalog_price_net' => 100 + $i,
                'purchase_price' => 70,
                'stock' => 3,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ])->id;
        }
        $sfi = Product::query()->create([
            'sku' => 'SFI',
            'name' => 'SFI',
            'manufacturer' => 'Reis',
            'category' => 'Odzież robocza',
            'description' => 'Włóknina 100% polipropylen, gramatura 50 g/m², gumka w pasie, nogawki do skrócenia.',
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 100,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        // Zapisane zrozumienie jak na produkcji: parafraza bez materiału.
        app(RequirementUnderstandingStore::class)->put(self::QUERY, ProductAiSearchService::UNDERSTAND_PROMPT_VERSION, [
            'needed' => 'spodnie robocze',
            'search_steps' => ['spodnie', 'robocze'],
            'manufacturer' => null,
            'model_name' => null,
            'size_note' => null,
            'search_phrases' => ['spodnie robocze'],
            'constraints' => [],
        ]);

        // Wektor treści wymagania widzi SFI; wektor parafrazy — same spodnie robocze.
        Http::fake(function (Request $request) use ($sfi, $work) {
            $url = $request->url();
            if (str_contains($url, '/embeddings')) {
                $fromRequirement = str_contains((string) $request['input'], 'polipropylenu');

                return Http::response(['data' => [['embedding' => $fromRequirement ? [1.0, 0.0] : [0.0, 1.0]]]]);
            }
            if (str_contains($url, '/points/search')) {
                $ids = ((float) ($request['vector'][0] ?? 0)) === 1.0 ? [$sfi->id] : array_slice($work, 0, 150);

                return Http::response(['result' => array_map(static fn (int $id): array => ['id' => $id, 'score' => 0.9], $ids)]);
            }

            return Http::response(['result' => ['status' => 'green']]);
        });

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $sets): array => array_map(static fn (): array => ['matches' => []], $sets)
        );
        $llm->shouldReceive('chatJson')->andReturn(['matches' => []]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $rows = app(AiProductSearch::class)->findMany([self::QUERY], 40);

        $pool = array_map('intval', (array) ($rows[0]['trace']['candidate_ids'] ?? []));
        $this->assertCount(80, $pool, 'pula pełna — bez drugiej listy SFI nie miałaby miejsca');
        $this->assertContains($sfi->id, $pool, 'karta widoczna tylko w wektorze treści wymagania jest w puli');
        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), '/embeddings') && $r['input'] === self::QUERY);
    }

    /**
     * Marki nie ma w katalogu (ten sam tryb włącza szukanie zamienników): `needed` celowo bez marki — wektor po treści
     * z marką ściągałby karty tej marki z powrotem. Rozumienie zapisane, więc test nie zależy od słownika marek.
     */
    public function test_no_requirement_text_vector_when_brand_is_absent_from_catalog(): void
    {
        Queue::fake([ReindexProductEmbeddingJob::class]);
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
        $query = 'Kombinezon ochronny z kapturem marki ZXQBRAND, zamek błyskawiczny kryty';
        app(RequirementUnderstandingStore::class)->put($query, ProductAiSearchService::UNDERSTAND_PROMPT_VERSION, [
            'needed' => 'kombinezon ochronny z kapturem',
            'search_steps' => ['kombinezon', 'kaptur'],
            'manufacturer' => 'ZXQBRAND',
            'model_name' => null,
            'size_note' => null,
            'search_phrases' => ['kombinezon ochronny z kapturem'],
            'constraints' => [],
        ]);
        $card = Product::query()->create([
            'sku' => 'KOMB-1',
            'name' => 'Kombinezon ochronny z kapturem',
            'manufacturer' => 'Reis',
            'category' => 'Odzież ochronna',
            'description' => 'Kombinezon ochronny z kapturem, zamek kryty listwą.',
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        // Każde zapytanie wektorowe coś zwraca — pusta lista `vector_query` znaczy więc, że nikt o nią nie pytał.
        Http::fake(function (Request $request) use ($card) {
            if (str_contains($request->url(), '/embeddings')) {
                return Http::response(['data' => [['embedding' => [0.0, 1.0]]]]);
            }
            if (str_contains($request->url(), '/points/search')) {
                return Http::response(['result' => [['id' => $card->id, 'score' => 0.9]]]);
            }

            return Http::response(['result' => ['status' => 'green']]);
        });

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $sets): array => array_map(static fn (): array => ['matches' => []], $sets)
        );
        $llm->shouldReceive('chatJson')->andReturn(['matches' => []]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $search = app(AiProductSearch::class);
        $search->enableSourceTrace();
        $rows = $search->findMany([$query], 40);

        $sources = $rows[0]['trace']['sources'][0] ?? null;
        $this->assertIsArray($sources, 'ślad źródeł puli zapisany');
        $this->assertSame([], $sources['vector_query'], 'bez listy wektorowej po treści z marką');
    }
}
