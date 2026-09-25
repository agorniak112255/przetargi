<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Golden opisowy15-01 (pomiar na produkcji 25.09.2026, ta sama pula i te same karty w obu przebiegach): model daje
 * właściwym rękawom HyFlex 19'' ocenę 95 z `missing_key`, kod obcina ją do 50, a rękawom złej długości model daje
 * raz 40, raz 50. Przy 50 remis rozstrzygała niższa cena i właściwe karty spadały z 1.–3. na 9.–12. miejsce.
 * Remis procentu rozstrzyga ocena modelu sprzed jego własnego limitu; procent karty zostaje 50.
 */
final class ProductAiSearchMissingKeyTieTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Ochraniacz przedramienia (rękaw) chroniący przed przecięciem, długość ok. 475 mm (19\'\'), w kolorze '
        .'fluorescencyjnym żółtym; regulowane zapięcie na rzep; wyrób antystatyczny, bez lateksu. Wymagane: ŚOI kategorii III; '
        .'EN 420:2003+A1:2009; EN 388 z poziomami min. 2.X.4.2.C; EN 407 – odporność na ciepło kontaktowe poziom 1.';

    protected function setUp(): void
    {
        parent::setUp();
        // Pula w remisie trafności idzie od najświeższego opisu — karty z now() mają remisować w każdym przebiegu.
        $this->freezeTime();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_card_capped_from_95_by_missing_key_ranks_before_cheaper_card_scored_50(): void
    {
        $this->sleevesRankedByModel();

        $rows = $this->postJson('/api/products/ai-search', ['query' => self::REQUIREMENT, 'limit' => 10])
            ->assertOk()
            ->json('products');

        $this->assertCappedSleeveFirst($rows);
    }

    /** Fala wyszukiwania — ta sama kolejność trafia do dopasowania przetargu i zapytań klientów. */
    public function test_wave_search_orders_the_tie_the_same_way(): void
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
        $this->sleevesRankedByModel();

        $rows = app(ProductAiSearchService::class)
            ->searchMany([self::REQUIREMENT], 20, false, AiTask::ProductSearch, 4)[0]['products'];

        $this->assertCappedSleeveFirst($rows);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function assertCappedSleeveFirst(array $rows): void
    {
        $seen = implode(', ', array_map(
            static fn (array $row): string => $row['sku'].'='.$row['ai_match_percent'],
            $rows,
        ));
        $this->assertSame(['11202000', '11281160-N'], array_slice(array_column($rows, 'sku'), 0, 2), 'remis 50%: '.$seen);
        $this->assertSame(50, (int) $rows[0]['ai_match_percent'], 'limit missing_key zostaje w procencie karty');
        $this->assertSame(50, (int) $rows[1]['ai_match_percent']);
        $this->assertStringContainsString('Brak dowodu kluczowego warunku: ŚOI kategorii III', (string) $rows[0]['ai_match_reason']);
        // Ocena sprzed limitu służy tylko kolejności — wiersz ściętej karty ma te same pola co każdy inny.
        $this->assertSame(array_keys($rows[1]), array_keys($rows[0]));
    }

    private function sleevesRankedByModel(): void
    {
        $right = $this->sleeve('11202000', 'HyFlex 11202 SIZE 19\'\'/47,5 cm', 30,
            'Rękaw ochronny Ansell HyFlex 11-202 o wysokiej widoczności chroni przedramię przed przecięciem, wyrób antystatyczny, zapięcie na rzep.',
            'EN 407: poziom 1 (ochrona termiczna do 100°C), EN ISO 13997: odporność na przecięcie poziom C');
        $tooShort = $this->sleeve('11281160-N', 'HyFlex 11281 SIZE 16\'\'/40 cm', 12,
            'Rękaw ochronny Ansell HyFlex 11-281 chroni przedramię przed przecięciem, wyrób antystatyczny, zapięcie na kciuk.',
            'EN 388: 2X43C');
        $matches = [
            ['id' => (int) $tooShort->id, 'score' => 50, 'reason' => 'Rękaw HyFlex 11-281, 40 cm — nie spełnia wymaganej długości 19\'\'.', 'missing_key' => []],
            ['id' => (int) $right->id, 'score' => 95, 'reason' => 'Rękaw HyFlex 11-202, 47,5 cm (19\'\').', 'missing_key' => ['ŚOI kategorii III']],
        ];
        $intent = [
            'needed' => 'rękaw ochronny przeciwprzecięciowy',
            'search_phrases' => ['rękaw ochronny', 'rękaw przeciwprzecięciowy', 'ochraniacz przedramienia'],
            'constraints' => ['EN 388 min. 2.X.4.2.C', 'EN 407 poziom 1', 'ŚOI kategorii III'],
        ];
        $answer = static fn (array $messages): array => FakeSearchLlm::kind($messages) === FakeSearchLlm::KIND_RANK
            ? $intent + ['matches' => $matches]
            : $intent;
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static fn (array $messages): array => $answer($messages));
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static fn (array $sets): array => array_map($answer, $sets));
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    private function sleeve(string $sku, string $name, float $purchase, string $description, string $norms): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Ansell',
            'description' => $description,
            'norms' => $norms,
            'catalog_price_net' => $purchase * 1.4,
            'purchase_price' => $purchase,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
