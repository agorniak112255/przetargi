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
 * Zestaw „przetarg opisowy 15” — pojedyncze pozycje przez `item_ids`, wariant B‑replay:
 * model odpowiada jak produkcja (intencja z `prod_search`, w rankingu właściwa karta 90
 * i błędna 92), a wybór między nimi należy do dopasowania przetargu.
 */
final class Opisowy15TenderMatchLineTest extends TestCase
{
    use Opisowy15TenderMatch;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOpisowy15Tender();
    }

    /**
     * Poz. 12: wyszukiwarka miała T5912100 na 1. miejscu, a przetarg brał zwykłe półbuty OB
     * z 92% — cena zamiast dowodów (PLAN D5) i brak bramki elektroizolacji (W3).
     */
    public function test_line_12_with_production_intent_picks_insulating_shoes_not_cheaper_ob(): void
    {
        $this->matchSingleLine(12);
    }

    /**
     * Poz. 13: półmaska wielokrotnego użytku (EN 140) vs jednorazowa FFP1 — ten sam rodzaj,
     * inny podtyp (PLAN W3, respiratory).
     */
    public function test_line_13_with_production_intent_does_not_pick_disposable_ffp(): void
    {
        $this->matchSingleLine(13);
    }

    private function matchSingleLine(int $lineNo): void
    {
        $ids = Opisowy15Fixture::seed();
        $tender = $this->makeOpisowy15Tender('PRZ/OPISOWY15/B-REPLAY-'.$lineNo);
        $items = $this->makeOpisowy15Items($tender);
        $this->app->instance(
            OpenAiCompatibleClient::class,
            FakeSearchLlm::replay(Opisowy15Fixture::items(), $ids, FakeSearchLlm::rankExpectedAndForbidden()),
        );

        $this->postJson("/api/tenders/{$tender->id}/match", [
            'only_empty' => true,
            'item_ids' => [$items[$lineNo]->id],
        ])
            ->assertOk()
            ->assertJsonPath('processed', 1);

        $this->assertLineOutcome(Opisowy15Fixture::line($lineNo), $items[$lineNo], $ids);
        foreach ($items as $otherLine => $other) {
            if ($otherLine !== $lineNo) {
                $this->assertNull($other->fresh()->main_product_id, "poz. {$otherLine} dopasowana mimo item_ids");
            }
        }
    }
}
