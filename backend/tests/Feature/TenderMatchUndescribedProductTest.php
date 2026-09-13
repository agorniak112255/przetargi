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
 * Decyzja użytkownika (13.09.2026): karta bez opisu nie trafia do propozycji przetargu — ani z oceny modelu, ani po
 * kodzie z SIWZ, ani jako karta z poprzedniego przebiegu. Model oceniał takie karty z samej nazwy; po pobraniu
 * opisu SECURA 3000 poz. 13 od razu dostała 95%. Pozycja dostaje powód z SKU karty, której brakuje opisu.
 * Wybór ręczny zostaje.
 */
final class TenderMatchUndescribedProductTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTIVE = 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, do prac montażowych';

    private const DESCRIPTION = 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, powlekane nitrylem, do prac montażowych i magazynowych.';

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

    public function test_model_pick_without_description_gives_way_to_described_card(): void
    {
        $bare = $this->glove('BARE-1', null);
        $described = $this->glove('DESC-1', self::DESCRIPTION);
        $this->stubRank([[(int) $bare->id, 95], [(int) $described->id, 85]]);
        [$tender, $item] = $this->tenderWith(self::DESCRIPTIVE);

        app(ProductMatchService::class)->matchTender($tender, true);
        $item->refresh();

        $this->assertSame((int) $described->id, (int) $item->main_product_id, 'karta bez opisu nie wygrywa oceną z samej nazwy');
    }

    public function test_only_card_without_description_leaves_line_empty_with_reason(): void
    {
        $bare = $this->glove('BARE-1', null);
        $this->stubRank([[(int) $bare->id, 95]]);
        [$tender, $item] = $this->tenderWith(self::DESCRIPTIVE);

        app(ProductMatchService::class)->matchTender($tender, true);
        $item->refresh();

        $this->assertNull($item->main_product_id);
        $this->assertSame('brak', $item->status);
        $this->assertSame(ProductMatchService::NO_MATCH_NO_DESCRIPTION, $item->ai_match_reasons[0]['code'] ?? null);
        $this->assertStringContainsString('BARE-1', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
    }

    public function test_sku_code_card_without_description_is_not_proposed(): void
    {
        $this->glove('RNITZ-M', null);
        $this->stubRank([]);
        [$tender, $item] = $this->tenderWith('Rękawice robocze RNITZ-M ze ściągaczem');

        app(ProductMatchService::class)->matchTender($tender, true);
        $item->refresh();

        $this->assertNull($item->main_product_id, 'kod z SIWZ wskazał kartę bez opisu — bez propozycji i bez zamiennika');
        $this->assertSame(ProductMatchService::NO_MATCH_NO_DESCRIPTION, $item->ai_match_reasons[0]['code'] ?? null);
        $this->assertStringContainsString('RNITZ-M', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
    }

    public function test_previous_model_pick_without_description_is_removed_but_manual_pick_stays(): void
    {
        $bare = $this->glove('BARE-1', null);
        $this->stubRank([]);
        [$tender, $auto] = $this->tenderWith(self::DESCRIPTIVE);
        $auto->forceFill(['main_product_id' => $bare->id, 'ai_match_percent' => 95, 'match_source' => 'ai'])->save();
        $manual = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 2,
            'requirement' => self::DESCRIPTIVE,
            'quantity' => 5,
            'status' => 'brak',
        ]);
        $manual->forceFill(['main_product_id' => $bare->id, 'ai_match_percent' => 95, 'match_source' => 'manual'])->save();

        app(ProductMatchService::class)->matchTender($tender, false);
        $auto->refresh();
        $manual->refresh();

        $this->assertNull($auto->main_product_id, 'automatyczna karta bez opisu nie zostaje w propozycji');
        $this->assertSame(ProductMatchService::NO_MATCH_NO_DESCRIPTION, $auto->ai_match_reasons[0]['code'] ?? null);
        $this->assertSame((int) $bare->id, (int) $manual->main_product_id, 'wybór ręczny zostaje');
    }

    private function glove(string $sku, ?string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => $description,
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => $description === null ? Product::ENRICHMENT_NONE : Product::ENRICHMENT_DONE,
            'enriched_at' => $description === null ? null : now(),
        ]);
    }

    /** @param  list<array{0: int, 1: int}>  $scores  [id karty, ocena modelu] */
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

    /** @return array{0: Tender, 1: TenderItem} */
    private function tenderWith(string $requirement): array
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/OPIS/'.mb_substr(md5($requirement), 0, 6),
            'title' => 'Karty bez opisu',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => $requirement,
            'quantity' => 10,
            'status' => 'brak',
        ]);

        return [$tender, $item];
    }
}
