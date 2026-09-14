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
 * Przetarg 1 poz. 8: 3M 9914 za 305 EUR (karton) wobec 9312+ za 0,88 EUR przy równych 95 modelu. Rozrzut cen ponad
 * 20× to inna jednostka albo błąd cennika — cena nie rozstrzyga, a pozycja dostaje ostrzeżenie do sprawdzenia ceny.
 */
final class TenderMatchPriceSuspectTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, do prac montażowych';

    public function test_pack_price_spread_among_equal_candidates_is_flagged_on_the_saved_line(): void
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
        $perPiece = $this->glove('SZT-1', 2.0);
        $perCarton = $this->glove('KARTON-1', 240.0);
        $answer = static function (array $messages) use ($perPiece, $perCarton): array {
            if (FakeSearchLlm::kind($messages) !== FakeSearchLlm::KIND_RANK) {
                return ['matches' => []];
            }

            return ['matches' => [
                ['id' => (int) $perCarton->id, 'score' => 95, 'reason' => 'Rękawice nitrylowe ze ściągaczem', 'missing_key' => []],
                ['id' => (int) $perPiece->id, 'score' => 95, 'reason' => 'Rękawice nitrylowe ze ściągaczem', 'missing_key' => []],
            ]];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        $tender = Tender::query()->create([
            'number' => 'PRZ/CENA/1',
            'title' => 'Cena za opakowanie',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => self::REQUIREMENT,
            'quantity' => 10,
            'status' => 'brak',
        ]);

        app(ProductMatchService::class)->matchTender($tender, true);
        $item->refresh();

        $this->assertNotNull($item->main_product_id, 'równo ocenione karty — jedna z nich zostaje wybrana');
        $codes = array_column($item->ai_match_reasons ?? [], 'code');
        $this->assertContains('price_pack_suspect', $codes, 'rozrzut cen 120× jest zapisany jako ostrzeżenie na pozycji');
    }

    private function glove(string $sku, float $price): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, powlekane nitrylem, do prac montażowych.',
            'catalog_price_net' => $price * 1.25,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
