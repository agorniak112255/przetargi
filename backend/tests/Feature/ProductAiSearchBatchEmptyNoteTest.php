<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\Search\AiProductSearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Fala „Dopasuj wszystkie” przy pustym wyniku zawsze mówiła „model nie znalazł pasującego produktu”
 * — także wtedy, gdy wywołanie modelu padło (limit tempa, timeout). Handlowiec czytał to jako brak
 * karty w katalogu, choć model kart nie widział. Wyszukiwarka rozróżnia te dwa przypadki od dawna;
 * fala ma mówić to samo, tym samym tekstem.
 */
final class ProductAiSearchBatchEmptyNoteTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Rękawice powlekane nitrylem EN 388 do prac montażowych';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        // Karta bez opisu: wchodzi do puli i do rankingu, ale nie do listy zapasowej („ten sam rodzaj
        // w katalogu” wymaga czegoś do pokazania). Dzięki temu po awarii modelu wynik jest pusty
        // i widać, jaki komunikat dostaje handlowiec — z kartą opisaną zapas zasłoniłby komunikat.
        Product::query()->create([
            'sku' => 'RKW-NITRYL-1',
            'name' => 'Rękawice powlekane nitrylem',
            'manufacturer' => 'TEST',
            'category' => 'Rękawice',
            'description' => null,
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
    }

    public function test_failed_model_call_in_batch_is_reported_as_failure_not_as_no_match(): void
    {
        // Pusta tablica z klienta = wywołanie padło (kontrakt OpenAiCompatibleClient).
        $this->app->instance(OpenAiCompatibleClient::class, $this->llmReturning([]));

        $result = $this->app->make(AiProductSearch::class)->findMany([self::QUERY], 10, AiTask::ProductSearch, 1)[0];

        $this->assertSame(ProductAiSearchService::MODEL_STATE_UNAVAILABLE, $result['model_state'] ?? null);
        $this->assertSame(
            ProductAiSearchService::NOTE_MODEL_FAILED,
            $result['ai_note'] ?? null,
            'awaria modelu w fali została opisana jak brak produktu w katalogu'
        );
    }

    public function test_model_that_answered_with_nothing_is_reported_as_no_match(): void
    {
        // Obiekt z pustym `matches` = model odpowiedział: nic nie pasuje.
        $this->app->instance(OpenAiCompatibleClient::class, $this->llmReturning(['matches' => []]));

        $result = $this->app->make(AiProductSearch::class)->findMany([self::QUERY], 10, AiTask::ProductSearch, 1)[0];

        $this->assertNotSame(ProductAiSearchService::MODEL_STATE_UNAVAILABLE, $result['model_state'] ?? null);
        $this->assertSame([], $result['products'] ?? null, 'karta bez opisu nie może wejść jako zapas');
        $this->assertSame(ProductAiSearchService::NOTE_MODEL_EMPTY, $result['ai_note'] ?? null);
    }

    /** @param  array<string, mixed>  $rankAnswer  odpowiedź na każde wywołanie rankingu i przepisania */
    private function llmReturning(array $rankAnswer): OpenAiCompatibleClient
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn($rankAnswer);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $batch): array => array_fill(0, count($batch), $rankAnswer)
        );

        return $llm;
    }
}
