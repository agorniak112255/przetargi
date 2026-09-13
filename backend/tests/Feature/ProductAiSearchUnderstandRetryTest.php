<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Awaria kroku „zrozum” (wyjątek/timeout klienta): jedna ponowna próba krótszym promptem,
 * a po niej intent lokalny z nagłówkiem wymagania jako `needed` — nie z całym tekstem
 * (całe wymaganie włączało tryb „marka+model” na igłach z opisu: klasy, „karton 100”).
 */
final class ProductAiSearchUnderstandRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_understand_retries_once_with_shorter_prompt_only_on_exception(): void
    {
        $prompts = [];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->twice()->andReturnUsing(static function (array $messages) use (&$prompts): array {
            $prompts[] = (string) ($messages[0]['content'] ?? '');
            if (count($prompts) === 1) {
                throw new RuntimeException('cURL error 28: Operation timed out');
            }

            return [
                'needed' => 'rękawice dzianinowe powlekane',
                'search_steps' => ['rękawice', 'dzianinowe', 'dłoń powlekana'],
                'manufacturer' => null,
                'search_phrases' => ['rękawice dzianinowe powlekane'],
                'constraints' => [],
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $intent = $this->app->make(ProductAiSearchService::class)
            ->understandRequirement('Rękawice wampirki uniwersalne');

        $this->assertSame('rękawice dzianinowe powlekane', $intent['needed']);
        $this->assertCount(2, $prompts);
        // Druga próba: ten sam krok „zrozum”, ale bez listy producentów i przykładów żargonu.
        $this->assertStringContainsString('Najpierw ZROZUM', $prompts[1]);
        $this->assertLessThan(mb_strlen($prompts[0]), mb_strlen($prompts[1]));
        $this->assertStringNotContainsString('Blok „Żargon SIWZ”', $prompts[1]);
    }

    public function test_understand_falls_back_to_requirement_head_after_failed_retry(): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->twice()->andThrow(new RuntimeException('cURL error 28: Operation timed out'));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        $svc = $this->app->make(ProductAiSearchService::class);

        $query = 'Płukanka do oczu – sterylny, izotoniczny roztwór soli fizjologicznej bez konserwantów. '
            .'Wymagane: butelka o pojemności 500 ml z końcówką w kształcie wanienki; okres przydatności 5 lat.';
        $intent = $svc->understandRequirement($query);

        // Całe wymaganie jako `needed` włączałoby tryb „marka+model” na igłach z opisu (pojemności 500).
        $this->assertSame('Płukanka do oczu', $intent['needed']);
        $this->assertSame('Płukanka do oczu', $intent['search_phrases'][0] ?? null);
        $this->assertContains($query, $intent['search_phrases']);
        $this->assertStringContainsString('understand-retry', (string) ($svc->lastTrace()['intent_error'] ?? ''));
        $this->assertStringContainsString('timed out', (string) ($svc->lastTrace()['intent_error'] ?? ''));
    }

    public function test_empty_understand_response_is_not_retried(): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        $svc = $this->app->make(ProductAiSearchService::class);

        // Pusta odpowiedź to decyzja modelu, nie awaria — bez drugiej próby i bez skracania.
        $intent = $svc->understandRequirement('Rękawice wampirki uniwersalne');

        $this->assertSame('Rękawice wampirki uniwersalne', $intent['needed']);
        $this->assertNull($svc->lastTrace()['intent_error'] ?? null);
    }
}
