<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSearchLlm;
use Tests\Support\Opisowy15Fixture;
use Tests\Support\Opisowy15TenderMatch;
use Tests\TestCase;

/**
 * Zestaw „przetarg opisowy 15” — pełny przebieg POST /api/tenders/{id}/match na 31 kartach
 * z produkcji. Model zastąpiony stubem, więc test pilnuje decyzji (bramka, wybór, próg,
 * uzasadnienie), nie jakości retrievalu (SQLite ≠ MySQL FULLTEXT — to mierzy `search:eval`).
 * Testy per pozycja (B‑replay, item_ids): Opisowy15TenderMatchLineTest.
 */
final class Opisowy15TenderMatchTest extends TestCase
{
    use Opisowy15TenderMatch;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOpisowy15Tender();
    }

    /**
     * B‑empty: model nic nie wnosi (intencja lokalna, pusty ranking) — wszystkie 15 pozycji
     * w jednym przebiegu, bo każda kosztuje ~0,9 s w searchMany/kaskadzie/explainMatch.
     * matchTender() wprost, nie przez POST: kontroler dokłada tylko battlecardy (~1,5 s na
     * 15 pozycji), a decyzje zapadają w serwisie; ścieżkę POST pokrywają testy per pozycja.
     */
    public function test_full_run_with_silent_model_never_picks_a_forbidden_card(): void
    {
        $ids = Opisowy15Fixture::seed();
        $tender = $this->makeOpisowy15Tender('PRZ/OPISOWY15/B-EMPTY');
        $items = $this->makeOpisowy15Items($tender);
        $this->app->instance(OpenAiCompatibleClient::class, FakeSearchLlm::empty());

        $result = $this->matcher->matchTender($tender, true);

        $this->assertSame(15, $result['processed']);
        $this->assertSame(15, $result['matched'] + $result['no_match'], 'każda pozycja kończy się trafieniem albo „brak”');
        foreach (Opisowy15Fixture::items() as $line) {
            $this->assertLineOutcome($line, $items[(int) $line['line_no']], $ids);
        }
    }
}
