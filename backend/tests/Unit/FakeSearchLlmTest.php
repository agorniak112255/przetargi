<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiTask;
use App\Services\ProductAiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\Exception\InvalidCountException;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\FakeSearchLlm;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Stub modelu rozpoznaje prompty po tekście z ProductAiSearchService. Ten test pęka, gdy
 * ktoś zmieni początek promptu „zrozum”/„przepisz”/„ranking” — wtedy trzeba zaktualizować
 * markery w FakeSearchLlm, zamiast pozwolić, by testy zestawu opisowego po cichu odpowiadały
 * na złe pytania.
 */
final class FakeSearchLlmTest extends TestCase
{
    /* promptBlock() listy producentów czyta tabelę products — pusta baza wystarczy. */
    use RefreshDatabase;

    public function test_recognizes_prompts_built_by_the_search_service(): void
    {
        $service = app(ProductAiSearchService::class);
        $query = Opisowy15Fixture::requirement(8);
        $cards = new Collection([Opisowy15Fixture::product('9914'), Opisowy15Fixture::product('9310+')]);

        $understand = (new ReflectionMethod(ProductAiSearchService::class, 'understandMessages'))->invoke($service, $query);
        $rewrite = (new ReflectionMethod(ProductAiSearchService::class, 'rewriteMessages'))->invoke($service, $query);
        $rank = (new ReflectionMethod(ProductAiSearchService::class, 'analyzeAndRankMessages'))
            ->invoke($service, $query, $cards, 10, null, [], AiTask::ProductSearch);

        $this->assertSame(FakeSearchLlm::KIND_UNDERSTAND, FakeSearchLlm::kind($understand));
        $this->assertSame(FakeSearchLlm::KIND_REWRITE, FakeSearchLlm::kind($rewrite));
        $this->assertSame(FakeSearchLlm::KIND_RANK, FakeSearchLlm::kind($rank));
        $this->assertSame(FakeSearchLlm::KIND_OTHER, FakeSearchLlm::kind([['role' => 'system', 'content' => 'Odpowiedz JSON.']]));
        // Wiadomość użytkownika rankingu niesie wymaganie i id kart — z tego replay() odtwarza pozycję i ranking.
        $this->assertStringContainsString(mb_substr($query, 0, 60), (string) $rank[1]['content']);
        $this->assertMatchesRegularExpression('/"id":\d+/', (string) $rank[1]['content']);
    }

    public function test_empty_model_gives_local_intent_and_empty_ranking(): void
    {
        $llm = FakeSearchLlm::empty();
        $understand = [['role' => 'system', 'content' => 'Jesteś ekspertem BHP i katalogów. Najpierw ZROZUM wymaganie.'], ['role' => 'user', 'content' => 'Wymaganie:\nRękawice']];
        $rank = [['role' => 'system', 'content' => 'Jesteś ekspertem BHP. Ranking w dwóch krokach — nie mieszaj ich.'], ['role' => 'user', 'content' => 'Karty katalogu:\n[{"id":1}]']];

        $this->assertSame(['matches' => []], $llm->chatJson($rank));
        $this->assertSame([[], ['matches' => []]], $llm->chatJsonMany([$understand, $rank]));
        try {
            $llm->chatJson($understand);
            $this->fail('„zrozum” w chatJson ma kończyć się wyjątkiem → intencja lokalna w understandRequirement()');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('model nie odpowiada', $e->getMessage());
        }
    }

    public function test_replay_returns_production_intent_and_ranking_from_card_ids(): void
    {
        $items = Opisowy15Fixture::items();
        $idBySku = ['S56T0SM0' => 501, '9310+' => 502, 'S565A202' => 503];
        $llm = FakeSearchLlm::replay($items, $idBySku, FakeSearchLlm::rankExpectedAndForbidden());
        $line13 = Opisowy15Fixture::requirement(13);

        $intent = $llm->chatJson([
            ['role' => 'system', 'content' => 'Jesteś ekspertem BHP i katalogów. Najpierw ZROZUM wymaganie.'],
            ['role' => 'user', 'content' => "Wymaganie:\n{$line13}"],
        ]);
        $this->assertSame('Półmaska wielokrotnego użytku', $intent['needed']);
        $this->assertSame('PN-EN', $intent['manufacturer'], 'replay oddaje intencję produkcji razem z jej błędem (norma jako producent)');

        $ranking = $llm->chatJson([
            ['role' => 'system', 'content' => 'Jesteś ekspertem BHP. Ranking w dwóch krokach — nie mieszaj ich.'],
            ['role' => 'user', 'content' => "Wymaganie:\n{$line13}\n\nKarty katalogu:\n[{\"id\":501,\"sku\":\"S56T0SM0\"},{\"id\":502,\"sku\":\"9310+\"},{\"id\":503}]"],
        ]);
        $this->assertSame([501, 502], array_column($ranking['matches'], 'id'));
        $this->assertSame([90, 92], array_column($ranking['matches'], 'score'));

        // Poz. 2 nie ma intencji produkcyjnej („błąd zapytania”) → jak przy pustym modelu.
        $line2 = [['role' => 'system', 'content' => 'Najpierw ZROZUM'], ['role' => 'user', 'content' => "Wymaganie:\n".Opisowy15Fixture::requirement(2)]];
        $this->assertSame([[]], $llm->chatJsonMany([$line2]));
    }

    /**
     * shouldNotReceive('chat') nie przerywa wywołania — zgłasza je przy weryfikacji mocka
     * (tearDown → Mockery::close()), więc test korzystający ze stubu kończy się błędem zamiast
     * cichego `null`. Tu weryfikujemy ręcznie i zerujemy kontener, żeby tearDown nie zgłosił
     * tego samego drugi raz.
     */
    public function test_low_level_chat_is_refused_so_a_real_model_call_is_visible(): void
    {
        $llm = FakeSearchLlm::empty();
        $llm->chat([['role' => 'user', 'content' => 'ping']]);

        try {
            Mockery::getContainer()->mockery_verify();
            $this->fail('chat() ma być zgłoszone przez shouldNotReceive');
        } catch (InvalidCountException $e) {
            $this->assertStringContainsString('chat', $e->getMessage());
            $this->assertStringContainsString('exactly 0 times but called 1 times', $e->getMessage());
        } finally {
            Mockery::resetContainer();
        }
    }
}
