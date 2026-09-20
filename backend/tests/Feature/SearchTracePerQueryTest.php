<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Search\AiProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Fala „Dopasuj wszystkie” prowadziła jeden wspólny ślad: pula kandydatów i karty wysłane do
 * modelu opisywały ostatnie wymaganie, a wcześniejsze były nadpisane w pętli. Bez śladu przy
 * każdej pozycji nie da się porównać tej ścieżki z wyszukiwarką ani zmierzyć pojedynczej pozycji.
 */
final class SearchTracePerQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_gives_every_requirement_its_own_trace(): void
    {
        $gloves = $this->makeProduct('RKW-1', 'Rękawice powlekane nitrylem', 'Rękawice robocze powlekane nitrylem, EN 388.');
        $helmet = $this->makeProduct('HLM-1', 'Hełm ochronny przemysłowy', 'Hełm ochronny z czaszą ABS, EN 397.');
        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $results = $this->app->make(AiProductSearch::class)->findMany(
            ['Rękawice powlekane nitrylem EN 388', 'Hełm ochronny przemysłowy EN 397'],
            10,
            AiTask::ProductSearch,
            2,
        );

        $this->assertCount(2, $results);
        $first = $results[0]['trace'] ?? null;
        $second = $results[1]['trace'] ?? null;
        $this->assertIsArray($first, 'pierwsze wymaganie nie dostało śladu');
        $this->assertIsArray($second, 'drugie wymaganie nie dostało śladu');

        $firstIds = array_map('intval', $first['candidate_ids'] ?? []);
        $secondIds = array_map('intval', $second['candidate_ids'] ?? []);
        $this->assertContains($gloves->id, $firstIds, 'ślad rękawic nie zawiera karty rękawic');
        $this->assertContains($helmet->id, $secondIds, 'ślad hełmu nie zawiera karty hełmu');
        $this->assertNotEquals(
            $firstIds,
            $secondIds,
            'oba wymagania dostały tę samą pulę — ślad nadal jest wspólny dla całej fali'
        );
    }

    public function test_single_search_returns_its_trace_in_the_result(): void
    {
        $this->makeProduct('RKW-2', 'Rękawice powlekane nitrylem', 'Rękawice robocze powlekane nitrylem, EN 388.');
        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $result = $this->app->make(AiProductSearch::class)->find('Rękawice powlekane nitrylem EN 388', 10);

        $this->assertIsArray($result['trace'] ?? null);
        $this->assertArrayHasKey('prompt_version', $result['trace']);
    }

    private function makeProduct(string $sku, string $name, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'TEST',
            'category' => 'BHP',
            'description' => $description,
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    private function emptyRankLlm(): OpenAiCompatibleClient
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(['matches' => []]);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $messages): array {
            return array_fill(0, count($messages), ['matches' => []]);
        });

        return $llm;
    }
}
