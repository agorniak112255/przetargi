<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Services\Search\AiProductSearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * 24.09.2026: przy serii HTTP 429 ranking pozycji w fali padał, a pusta odpowiedź klienta była czytana jak
 * odpowiedź modelu — z niej powstawała intencja z całym akapitem SIWZ jako „szukany produkt”, fala szukała
 * kart od nowa i pytała model drugi raz o inną pulę. Awaria modelu ma zostać awarią: jedna ocena, pusta
 * pozycja, stan „model nie odpowiedział”, a intencja z kroku „zrozum” nietknięta.
 */
final class ProductAiSearchBatchRankFailureTest extends TestCase
{
    use RefreshDatabase;

    private const GLOVES = 'Rękawice powlekane nitrylem EN 388 do prac montażowych';

    private const GOGGLES = 'Okulary ochronne bezbarwne EN 166 z regulowanymi zausznikami';

    private const GLOVES_NEEDED = 'rękawice powlekane nitrylem';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_failed_rank_is_not_asked_again_with_intent_built_from_the_failure(): void
    {
        $glove = $this->product('RKW-NITRYL-OPIS', 'Rękawice robocze powlekane nitrylem', 'Rękawice robocze powlekane nitrylem, EN 388, do prac montażowych.');
        $goggles = $this->product('OKL-166', 'Okulary ochronne bezbarwne', 'Okulary ochronne bezbarwne, EN 166, regulowane zauszniki.');
        $rankCalls = ['gloves' => 0, 'goggles' => 0];
        $this->llm(function (string $kind, string $user) use ($glove, $goggles, &$rankCalls): array {
            $line = str_contains($user, self::GLOVES) ? 'gloves' : 'goggles';
            if ($kind === FakeSearchLlm::KIND_UNDERSTAND || $kind === FakeSearchLlm::KIND_REWRITE) {
                return $line === 'gloves' ? $this->glovesIntent() : $this->gogglesIntent();
            }
            $rankCalls[$line]++;
            if ($line === 'goggles') {
                return ['matches' => [['id' => $goggles->id, 'score' => 90, 'reason' => 'EN 166, bezbarwne']]];
            }

            // Pierwsza ocena rękawic padła (klient oddaje wtedy pustą tablicę); każda kolejna „odpowiada”.
            return $rankCalls['gloves'] === 1
                ? []
                : ['matches' => [['id' => $glove->id, 'score' => 95, 'reason' => 'ocena puli z intencji zbudowanej z awarii']]];
        });

        [$gloves, $gogglesRow] = $this->app->make(AiProductSearch::class)->findMany([self::GLOVES, self::GOGGLES], 10, AiTask::ProductSearch, 2);

        $this->assertSame([], array_column($gloves['products'] ?? [], 'sku'), 'karta z drugiej oceny weszła do wyniku pozycji, której ocena padła');
        $this->assertSame(1, $rankCalls['gloves'], 'po awarii oceny fala zapytała model drugi raz o pulę z intencji zbudowanej z pustej odpowiedzi');
        $this->assertSame(ProductAiSearchService::MODEL_STATE_UNAVAILABLE, $gloves['model_state'] ?? null);
        $this->assertSame(ProductAiSearchService::NOTE_MODEL_FAILED, $gloves['ai_note'] ?? null);
        $this->assertSame(self::GLOVES_NEEDED, $gloves['needed'] ?? null, 'awaria oceny nadpisała intencję z kroku „zrozum”');
        // Druga pozycja tej samej fali — bez zmian.
        $this->assertSame(1, $rankCalls['goggles']);
        $this->assertSame(['OKL-166'], array_column($gogglesRow['products'] ?? [], 'sku'));
    }

    public function test_failed_rank_after_rewrite_is_reported_as_model_failure(): void
    {
        // Pierwsza ocena: „nic nie pasuje” (karta bez opisu nie wchodzi do listy zapasowej, więc pozycja jest pusta).
        // Fala szuka ponownie i ocenia drugi raz, a ta ocena pada. Stan zostawał „empty”, więc dopasowanie
        // przetargu brało awarię za odpowiedź modelu i dobierało kartę po słowach.
        $this->product('RKW-NITRYL-1', 'Rękawice powlekane nitrylem', null);
        $rankCalls = 0;
        $this->llm(function (string $kind) use (&$rankCalls): array {
            if ($kind === FakeSearchLlm::KIND_UNDERSTAND) {
                return $this->glovesIntent();
            }
            if ($kind === FakeSearchLlm::KIND_REWRITE) {
                return [
                    ...$this->glovesIntent(),
                    'needed' => 'rękawice montażowe nitrylowe',
                    'search_phrases' => ['rękawice montażowe', 'rękawice nitrylowe montażowe'],
                ];
            }

            return ++$rankCalls === 1 ? [...$this->glovesIntent(), 'matches' => []] : [];
        });

        $result = $this->app->make(AiProductSearch::class)->findMany([self::GLOVES], 10, AiTask::ProductSearch, 2)[0];

        $this->assertSame(2, $rankCalls, 'fixture: po pustej ocenie fala ocenia drugi raz');
        $this->assertSame([], $result['products'] ?? null);
        $this->assertSame(ProductAiSearchService::MODEL_STATE_UNAVAILABLE, $result['model_state'] ?? null, 'awaria oceny po przepisaniu wygląda jak „model nic nie znalazł”');
        $this->assertSame(ProductAiSearchService::NOTE_MODEL_FAILED, $result['ai_note'] ?? null);
    }

    /** @return array<string, mixed> */
    private function glovesIntent(): array
    {
        return [
            'needed' => self::GLOVES_NEEDED,
            'search_steps' => ['rękawice', 'powlekane nitrylem'],
            'manufacturer' => null,
            'model_name' => null,
            'size_note' => null,
            'search_phrases' => ['rękawice powlekane nitrylem', 'rękawice nitrylowe', 'rękawice robocze'],
            'constraints' => ['EN 388'],
        ];
    }

    /** @return array<string, mixed> */
    private function gogglesIntent(): array
    {
        return [
            'needed' => 'okulary ochronne bezbarwne',
            'search_steps' => ['okulary ochronne', 'bezbarwne'],
            'manufacturer' => null,
            'model_name' => null,
            'size_note' => null,
            'search_phrases' => ['okulary ochronne', 'okulary bezbarwne'],
            'constraints' => ['EN 166'],
        ];
    }

    private function product(string $sku, string $name, ?string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'TEST',
            'description' => $description,
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 10,
            'enrichment_status' => $description === null ? Product::ENRICHMENT_NONE : Product::ENRICHMENT_DONE,
            'enriched_at' => $description === null ? null : now(),
        ]);
    }

    /** @param  callable(string, string): array<string, mixed>  $answer  (rodzaj promptu, wiadomość użytkownika) */
    private function llm(callable $answer): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $messageSets) use ($answer): array {
            return array_map(
                static fn (array $messages): array => $answer(FakeSearchLlm::kind($messages), (string) ($messages[1]['content'] ?? '')),
                $messageSets,
            );
        });
        $llm->shouldReceive('chatJson')->andThrow(new RuntimeException('fala nie powinna pytać modelu pojedynczo'));
        $llm->shouldNotReceive('chat');
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
