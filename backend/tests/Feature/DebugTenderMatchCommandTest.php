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
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * `tenders:debug-match` pokazuje obie ścieżki (Dopasuj wszystkie / wyszukiwarka) dla jednej pozycji
 * i czy wskazana karta dotarła do rankingu — bez zapisu do przetargu.
 */
final class DebugTenderMatchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_both_paths_and_tracks_card_through_ranking_without_saving(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        $sandal = Product::query()->create([
            'sku' => 'ARSO 701 616560 S1 P ESD',
            'name' => 'ARSO 701 616560 S1 P ESD',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'description' => 'Sandały bezpieczne ARSO 701 S1 P ESD z zabudowaną piętą i podnoskiem, właściwości antyelektrostatyczne ESD.',
            'norms' => 'EN ISO 20345 S1 P, EN 61340-4-3 ESD',
            'catalog_price_net' => 200,
            'purchase_price' => 150,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $sandalId = (int) $sandal->id;
        $answer = static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => [['id' => $sandalId, 'score' => 93, 'reason' => 'Sandał S1 P ESD', 'missing_key' => []]]]
            : ['matches' => []];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $tender = Tender::query()->create([
            'number' => 'PRZ/DEBUG/1',
            'title' => 'Diagnostyka',
            'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 3,
            'requirement' => 'Sandały ochronne kategorii S1 P ESD z zabudowaną piętą',
            'quantity' => 20,
            'status' => 'brak',
        ]);

        $this->artisan('tenders:debug-match', [
            'tender' => $tender->id,
            'line' => 3,
            '--sku' => 'ARSO 701 616560 S1 P ESD',
        ])
            ->expectsOutputToContain('„Dopasuj wszystkie” (findMany)')
            ->expectsOutputToContain('Wyszukiwarka (find)')
            ->expectsOutputToContain('ARSO 701 616560 S1 P ESD=93')
            ->expectsOutputToContain('w kartach rankingu=tak')
            ->expectsOutputToContain('dowód warunków karty ARSO 701 616560 S1 P ESD:')
            ->expectsOutputToContain('bramki zgodności karty ARSO 701 616560 S1 P ESD: w puli przed bramką=tak · przeszła wszystkie')
            ->expectsOutputToContain('źródła wyszukiwania karty ARSO 701 616560 S1 P ESD: priorytet=')
            ->expectsOutputToContain('Decyzja przetargu dla wyniku „Dopasuj wszystkie” (bez zapisu)')
            ->expectsOutputToContain('wybór przetargu:')
            ->assertSuccessful();

        $item->refresh();
        $this->assertNull($item->main_product_id, 'diagnostyka nie zapisuje karty do przetargu');
        $this->assertSame('brak', $item->status);
    }

    public function test_missing_item_fails_clearly(): void
    {
        $this->artisan('tenders:debug-match', ['tender' => 999, 'line' => 1])
            ->expectsOutputToContain('Nie ma takiej pozycji w przetargu.')
            ->assertFailed();
    }
}
