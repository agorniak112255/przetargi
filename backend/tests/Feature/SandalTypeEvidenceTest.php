<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\TenderItem;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\ProductMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Support\FakeSearchLlm;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * AUDYT_4, poz. 3 przetargu 1 (dane z produkcji): model ocenił sandały ARSO 701 S1 P ESD na 95,
 * a zakryte „buty robocze” AROSIO 730 i ARDESIO 731 na 90 (uzasadnienie „sandały w opisie” — nieprawda).
 * Przetarg nie zapisał nic (explain < 65), a samo zaufanie do modelu wybrałoby najtańsze AROSIO.
 * Karta bez słowa „sandał” dostaje ≤ 50, decyzja przetargu wybiera ARSO.
 */
final class SandalTypeEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const ARSO = 'ARSO 701 616560 S1 P ESD';

    private const AROSIO = 'AROSIO 730 Air 618080 S1 P ESD';

    private const ARDESIO = 'ARDESIO 731 Air 618080 S1 P ESD';

    public function test_closed_work_shoes_are_capped_and_tender_picks_real_sandals(): void
    {
        Http::fake();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        $ids = Opisowy15Fixture::seed([self::ARSO, self::AROSIO, self::ARDESIO]);
        $scores = [$ids[self::ARSO] => 95, $ids[self::AROSIO] => 90, $ids[self::ARDESIO] => 90];
        $answer = static function (array $messages) use ($scores): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                return ['matches' => []];
            }
            preg_match_all('/"id":(\d+)/', (string) ($messages[1]['content'] ?? ''), $m);
            $matches = [];
            foreach (array_unique(array_map('intval', $m[1] ?? [])) as $id) {
                if (isset($scores[$id])) {
                    // jak model na produkcji: „sandały w opisie” także przy butach roboczych
                    $matches[] = ['id' => $id, 'score' => $scores[$id], 'reason' => 'Nazwa zgodna (sandały w opisie)', 'missing_key' => []];
                }
            }

            return ['matches' => $matches];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $requirement = Opisowy15Fixture::requirement(3);
        $rows = app(ProductAiSearchService::class)->searchMany([$requirement], 80, false, AiTask::ProductSearch, 16);
        $bySku = [];
        foreach ($rows[0]['products'] ?? [] as $row) {
            $bySku[(string) $row['sku']] = $row;
        }

        $this->assertArrayHasKey(self::ARSO, $bySku, 'sandały ARSO są w wyniku');
        $this->assertSame(95, (int) $bySku[self::ARSO]['ai_match_percent'], 'karta nazwana sandałem zachowuje ocenę modelu');
        foreach ([self::AROSIO, self::ARDESIO] as $shoe) {
            if (isset($bySku[$shoe])) {
                $this->assertLessThanOrEqual(50, (int) $bySku[$shoe]['ai_match_percent'], "{$shoe}: buty robocze bez dowodu typu");
                $this->assertStringContainsString('Brak dowodu typu obuwia', (string) $bySku[$shoe]['ai_match_reason']);
            }
        }

        $item = new TenderItem;
        $item->forceFill(['requirement' => $requirement]);
        $decision = app(ProductMatchService::class)->debugPick($item, $rows[0]);

        $this->assertNotNull($decision['pick'], 'przetarg zapisuje kartę, gdy model ocenił sandały na 95');
        $this->assertSame(self::ARSO, $decision['pick']['sku']);
    }
}
