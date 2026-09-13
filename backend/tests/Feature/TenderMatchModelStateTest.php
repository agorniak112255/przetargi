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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Opis bez kodu ma być dobierany przez model. Gdy model nie odpowiedział, pozycja czeka na
 * ponowienie; gdy odpowiedział „nic nie pasuje”, heurystyka zostaje propozycją z sufitem 70%.
 * Kod SKU w wymaganiu (RNITZ-M) rozstrzyga bez modelu — jak dotąd.
 */
final class TenderMatchModelStateTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTIVE = 'Rękawice robocze nitrylowe ze ściągaczem, dzianina bawełniana, do prac montażowych';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
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

    public function test_descriptive_line_waits_when_model_did_not_answer(): void
    {
        $this->glove('RNITZ-M');
        $this->stubModel(static fn (): array => []); // każde wywołanie modelu padło (kontrakt klienta: pusta tablica)
        [$tender, $item] = $this->tenderWith(self::DESCRIPTIVE);

        $result = app(ProductMatchService::class)->matchTender($tender, true);
        $item->refresh();

        $this->assertNull($item->main_product_id, 'bez odpowiedzi modelu opis nie może dostać karty po słowach');
        $this->assertSame('brak', $item->status);
        $this->assertNull($item->ai_match_percent);
        $this->assertSame('model_unavailable', $item->ai_match_reasons[0]['code'] ?? null);
        $this->assertSame(1, $result['model_unavailable']);
    }

    public function test_descriptive_line_gets_capped_heuristic_when_model_found_nothing(): void
    {
        $glove = $this->glove('RNITZ-M');
        $this->stubModel(static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => []]
            : []);
        [$tender, $item] = $this->tenderWith(self::DESCRIPTIVE);

        $result = app(ProductMatchService::class)->matchTender($tender, true);
        $item->refresh();

        $this->assertSame((int) $glove->id, (int) $item->main_product_id);
        $this->assertSame('heuristic', $item->match_source);
        $this->assertLessThanOrEqual(70, (int) $item->ai_match_percent, 'heurystyka bez modelu nie może udawać pewności');
        $this->assertGreaterThanOrEqual(app(ProductMatchService::class)->minMatchScore(), (int) $item->ai_match_percent);
        $this->assertSame('heuristic_only', $item->ai_match_reasons[0]['code'] ?? null);
        $this->assertSame(0, $result['model_unavailable']);
    }

    public function test_line_with_sku_code_matches_without_model(): void
    {
        $glove = $this->glove('RNITZ-M');
        $this->stubModel(static fn (): array => []);
        [$tender, $item] = $this->tenderWith('Rękawice robocze RNITZ-M ze ściągaczem');

        app(ProductMatchService::class)->matchTender($tender, true);
        $item->refresh();

        $this->assertSame((int) $glove->id, (int) $item->main_product_id);
        $this->assertNotContains('heuristic_only', array_column($item->ai_match_reasons ?? [], 'code'));
        $this->assertGreaterThan(70, (int) $item->ai_match_percent, 'kod SKU w wymaganiu to twardy dowód — bez sufitu');
    }

    /**
     * Przetarg 1 z produkcji: po „Dopasuj wszystkie” poz. 1 i 2 zostały z 81% i 79% sprzed poprawek,
     * bez żadnej etykiety — nowy przebieg nic nie wybrał, a stara karta zostawała ze starym procentem.
     */
    public function test_previous_card_is_kept_but_not_presented_as_fresh_when_model_did_not_answer(): void
    {
        $glove = $this->glove('RNITZ-M');
        $this->stubModel(static fn (): array => []);
        [$tender, $item] = $this->tenderWith(self::DESCRIPTIVE);
        $item->forceFill([
            'main_product_id' => $glove->id,
            'status' => 'matched',
            'match_source' => 'heuristic',
            'ai_match_percent' => 99,
            'ai_match_reasons' => [['code' => 'overlap', 'label' => 'Wynik sprzed poprawek', 'points' => 99]],
            'offer_price' => 5,
        ])->save();

        $result = app(ProductMatchService::class)->matchTender($tender, false);
        $item->refresh();

        $this->assertSame((int) $glove->id, (int) $item->main_product_id, 'oferta nie znika, gdy model nie odpowiedział');
        $this->assertLessThanOrEqual(70, (int) $item->ai_match_percent, 'stary procent nie może udawać świeżej oceny');
        $this->assertSame('not_reconfirmed', $item->ai_match_reasons[0]['code'] ?? null);
        $this->assertStringContainsString('Model nie odpowiedział', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
        $this->assertNotContains('Wynik sprzed poprawek', array_column($item->ai_match_reasons, 'label'));
        $this->assertSame(1, $result['model_unavailable']);
    }

    public function test_manual_pick_is_not_capped_when_run_does_not_confirm_it(): void
    {
        $glove = $this->glove('RNITZ-M');
        $this->stubModel(static fn (): array => []);
        [$tender, $item] = $this->tenderWith(self::DESCRIPTIVE);
        $reasons = [['code' => 'overlap', 'label' => 'Wybrane ręcznie', 'points' => 88]];
        $item->forceFill([
            'main_product_id' => $glove->id,
            'status' => 'matched',
            'match_source' => 'manual',
            'ai_match_percent' => 88,
            'ai_match_reasons' => $reasons,
            'offer_price' => 5,
        ])->save();

        app(ProductMatchService::class)->matchTender($tender, false);
        $item->refresh();

        $this->assertSame((int) $glove->id, (int) $item->main_product_id);
        $this->assertSame(88, (int) $item->ai_match_percent, 'decyzji człowieka przebieg nie tnie');
        $this->assertSame('manual', $item->match_source);
        $this->assertSame($reasons, $item->ai_match_reasons);
    }

    /**
     * Przetarg 1, poz. 8 i 10: karta wybrana wcześniej przez model została wyparta przez wybór po
     * słowach karty, gdy model w kolejnym przebiegu nic nie wskazał. Karta modelu ma zostać.
     */
    public function test_word_only_pick_does_not_replace_card_chosen_earlier_by_model(): void
    {
        $this->glove('RNITZ-M');
        $modelCard = $this->plainGlove();
        $this->stubModel(static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => []]
            : []);
        [$tender, $item] = $this->tenderWith(self::DESCRIPTIVE);
        $item->forceFill([
            'main_product_id' => $modelCard->id,
            'status' => 'matched',
            'match_source' => 'ai',
            'ai_match_percent' => 90,
            'ai_match_reasons' => [['code' => 'ai', 'label' => 'Model: rękawice nitrylowe', 'points' => 90]],
            'offer_price' => 5,
        ])->save();

        app(ProductMatchService::class)->matchTender($tender, false);
        $item->refresh();

        $this->assertSame((int) $modelCard->id, (int) $item->main_product_id, 'wybór po słowach nie wypiera karty modelu');
        $this->assertSame('not_reconfirmed', $item->ai_match_reasons[0]['code'] ?? null);
        $this->assertLessThanOrEqual(70, (int) $item->ai_match_percent);
    }

    /** Kontrola: karta dobrana wcześniej po słowach może zostać zastąpiona lepszym wyborem po słowach. */
    public function test_word_only_pick_still_replaces_earlier_word_only_card(): void
    {
        $better = $this->glove('RNITZ-M');
        $wordCard = $this->plainGlove();
        $this->stubModel(static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => []]
            : []);
        [$tender, $item] = $this->tenderWith(self::DESCRIPTIVE);
        $item->forceFill([
            'main_product_id' => $wordCard->id,
            'status' => 'matched',
            'match_source' => 'heuristic',
            'ai_match_percent' => 70,
            'ai_match_reasons' => [['code' => 'heuristic_only', 'label' => 'po słowach', 'points' => 70]],
            'offer_price' => 5,
        ])->save();

        app(ProductMatchService::class)->matchTender($tender, false);
        $item->refresh();

        $this->assertSame((int) $better->id, (int) $item->main_product_id, 'fixture: słowa karty wskazują lepszą kartę');
        $this->assertSame('heuristic_only', $item->ai_match_reasons[0]['code'] ?? null);
    }

    /**
     * Ranking: karta bez dowodu kluczowego warunku dostaje od modelu najwyżej 50 (przetarg 1, poz. 8:
     * 3M 9312+ bez węgla aktywnego). Dobór po słowach nie może jej potem zapisać z 70%.
     */
    public function test_card_scored_below_threshold_by_model_is_not_saved_by_word_fallback(): void
    {
        $glove = $this->glove('RNITZ-M');
        $gloveId = (int) $glove->id;
        $this->stubModel(static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => [['id' => $gloveId, 'score' => 50, 'reason' => 'brak dowodu kluczowego warunku']]]
            : []);
        [$tender, $item] = $this->tenderWith(self::DESCRIPTIVE);

        app(ProductMatchService::class)->matchTender($tender, true);
        $item->refresh();

        $this->assertNull($item->main_product_id, 'niska ocena modelu nie zamienia się w 70% po słowach');
        $this->assertSame('model_low_score', $item->ai_match_reasons[0]['code'] ?? null, 'nie „brak produktu w katalogu”');
        $this->assertStringContainsString('RNITZ-M', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
        $this->assertStringContainsString('50%', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
    }

    /** Poprzednia karta oceniona przez model poniżej progu zostaje z oceną modelu i jasną etykietą. */
    public function test_previous_card_scored_low_by_model_keeps_model_score_in_label(): void
    {
        $glove = $this->glove('RNITZ-M');
        $gloveId = (int) $glove->id;
        $this->stubModel(static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? ['matches' => [['id' => $gloveId, 'score' => 50, 'reason' => 'brak dowodu kluczowego warunku']]]
            : []);
        [$tender, $item] = $this->tenderWith(self::DESCRIPTIVE);
        $item->forceFill([
            'main_product_id' => $gloveId,
            'status' => 'matched',
            'match_source' => 'ai',
            'ai_match_percent' => 95,
            'ai_match_reasons' => [['code' => 'ai', 'label' => 'stara ocena 95%', 'points' => 95]],
            'offer_price' => 5,
        ])->save();

        app(ProductMatchService::class)->matchTender($tender, false);
        $item->refresh();

        $this->assertSame($gloveId, (int) $item->main_product_id);
        $this->assertSame('not_reconfirmed', $item->ai_match_reasons[0]['code'] ?? null);
        $this->assertStringContainsString('Model ocenił poprzednią kartę na 50%', (string) ($item->ai_match_reasons[0]['label'] ?? ''));
        $this->assertSame(50, (int) $item->ai_match_percent, 'procent nie wyższy niż ocena modelu');
    }

    /**
     * Przetarg 1: model nie wskazał właściwych kart przy poz. 5 (wodery przy „spodniobutach”) i 12
     * (brak rozmiarów/numerów seryjnych na karcie odrzucał kartę), a dał 95% karcie bez węgla aktywnego
     * przy poz. 8. Ranking dostaje trzy przypadki: sprzeczność, brak kluczowego, brak drugorzędnego.
     */
    public function test_rank_prompt_separates_contradiction_missing_key_and_missing_minor_condition(): void
    {
        $this->glove('RNITZ-M');
        $prompts = [];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $messageSets) use (&$prompts): array {
            foreach ($messageSets as $messages) {
                if (FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK) {
                    $prompts[] = (string) ($messages[0]['content'] ?? '').'||'.(string) ($messages[1]['content'] ?? '');
                }
            }

            return array_map(static fn (): array => ['matches' => []], $messageSets);
        });
        $llm->shouldReceive('chatJson')->andThrow(new RuntimeException('model niedostępny'));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
        [$tender] = $this->tenderWith(self::DESCRIPTIVE);

        app(ProductMatchService::class)->matchTender($tender, true);

        $this->assertNotSame([], $prompts, 'pozycja opisowa idzie do rankingu modelu');
        $prompt = $prompts[0];
        $this->assertStringContainsString('PRZECZY', $prompt);
        $this->assertStringContainsString('KLUCZOWY', $prompt);
        $this->assertStringContainsString('score najwyżej 50', $prompt);
        $this->assertStringContainsString('DRUGORZĘDNY', $prompt);
        $this->assertStringContainsString('NIE odrzucaj', $prompt);
        $this->assertStringContainsString('spodniobuty=wodery', $prompt);
        $this->assertStringContainsString('2: 17 kV', $prompt);
        $this->assertStringContainsString('missing_key', $prompt);
        // przetarg 1, poz. 5: SBM01B w zwykłym kolorze dostało 95% przy wymaganym kolorze fluorescencyjnym
        $this->assertStringContainsString('fluorescencyjny / odblaskowy (zwiększona widzialność) to funkcja ochronna → KLUCZOWY', $prompt);
        $this->assertStringContainsString('zwykły kolor', $prompt);
        $this->assertStringNotContainsString('Brak potwierdzenia → nie zwracaj', $prompt);
    }

    private function plainGlove(): Product
    {
        return Product::query()->create([
            'sku' => 'NITRO-1',
            'name' => 'Rękawice nitrylowe',
            'manufacturer' => 'INNY',
            'category' => 'Rękawice',
            'description' => 'Rękawice nitrylowe.',
            'catalog_price_net' => 4,
            'purchase_price' => 3,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    private function glove(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice nitrylowe ze ściągaczem',
            'manufacturer' => 'REJS',
            'category' => 'Rękawice',
            'description' => 'Rękawice robocze nitrylowe RNITZ ze ściągaczem, dzianina bawełniana, powlekane nitrylem, do prac montażowych i magazynowych.',
            'catalog_price_net' => 3,
            'purchase_price' => 2,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['materials' => ['nitryl', 'bawełna']],
            'enriched_at' => now(),
        ]);
    }

    /** @param  callable(array): array  $rankAnswer  odpowiedź modelu na każdy zestaw wiadomości */
    private function stubModel(callable $rankAnswer): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $messageSets) use ($rankAnswer): array {
            $out = [];
            foreach ($messageSets as $messages) {
                $out[] = $rankAnswer($messages);
            }

            return $out;
        });
        $llm->shouldReceive('chatJson')->andThrow(new RuntimeException('model niedostępny'));
        $llm->shouldNotReceive('chat');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    /** @return array{0: Tender, 1: TenderItem} */
    private function tenderWith(string $requirement): array
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/MODEL/'.mb_substr(md5($requirement), 0, 6),
            'title' => 'Stan modelu',
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
