<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * „Dopasuj AI (puste)” obiecuje w interfejsie: „Tylko pozycje bez produktu — zapisanych nie rusza”. Filtr brał jednak
 * także słabe propozycje (< progu zapisu), w tym wybór ręczny i z battlecard — model nadpisywał decyzję użytkownika
 * (recenzja dopasowania 13.09, błąd C). „Dopasuj wszystkie” świadomie nadpisuje produkty z katalogu — bez zmian.
 */
final class TenderMatchUserDecisionTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, do prac montażowych';

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    public function test_only_empty_rematch_keeps_weak_manual_and_battlecard_picks(): void
    {
        $chosen = $this->glove('MAN-1');
        $better = $this->glove('BEST-1');
        $this->stubRank([[(int) $better->id, 95]]);
        $tender = Tender::query()->create([
            'number' => 'PRZ/DECYZJA/1',
            'title' => 'Decyzje użytkownika',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $manual = $this->item($tender, 1, $chosen, 40, 'manual');
        $battlecard = $this->item($tender, 2, $chosen, 30, 'battlecard');
        $weakModel = $this->item($tender, 3, $chosen, 40, 'ai');

        app(ProductMatchService::class)->matchTender($tender, true);

        $this->assertSame((int) $chosen->id, (int) $manual->fresh()->main_product_id, 'wybór ręczny zostaje');
        $this->assertSame('manual', $manual->fresh()->match_source);
        $this->assertSame((int) $chosen->id, (int) $battlecard->fresh()->main_product_id, 'wybór z battlecard zostaje');
        $this->assertSame((int) $better->id, (int) $weakModel->fresh()->main_product_id, 'słaba propozycja automatyczna nadal jest poprawiana');
    }

    private function item(Tender $tender, int $line, Product $product, int $percent, string $source): TenderItem
    {
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => $line,
            'requirement' => self::REQUIREMENT,
            'quantity' => 10,
            'status' => 'brak',
        ]);
        $item->forceFill(['main_product_id' => $product->id, 'ai_match_percent' => $percent, 'match_source' => $source])->save();

        return $item;
    }

    private function glove(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, powlekane nitrylem, do prac montażowych.',
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    /** @param  list<array{0: int, 1: int}>  $scores */
    private function stubRank(array $scores): void
    {
        $answer = static function (array $messages) use ($scores): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                return ['matches' => []];
            }

            return ['matches' => array_map(static fn (array $row): array => [
                'id' => $row[0],
                'score' => $row[1],
                'reason' => 'Rękawice nitrylowe ze ściągaczem',
                'missing_key' => [],
            ], $scores)];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
