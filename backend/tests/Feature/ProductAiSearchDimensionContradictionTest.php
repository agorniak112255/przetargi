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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSearchLlm;
use Tests\Support\Opisowy15Fixture;
use Tests\TestCase;

/**
 * Limit D10 (decyzja właściciela z 25.09.2026): karta, której wymiar jest wprost mniejszy od wymaganego o więcej
 * niż 5%, dostaje najwyżej 50 i dopisek z cytatem karty i wymagania. Wymiar bez „min./max.” to minimum — dłuższy
 * rękaw nie przeczy wymaganiu; „max.” to maksimum.
 */
final class ProductAiSearchDimensionContradictionTest extends TestCase
{
    use RefreshDatabase;

    private const SLEEVE = 'Rękaw ochronny antyprzecięciowy, długość ok. 475 mm, dzianina z włóknem szklanym';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_shorter_card_is_capped_and_longer_card_keeps_model_score(): void
    {
        $short = $this->sleeve('REKAW-30', 'Długość: 30 cm');
        $exact = $this->sleeve('REKAW-475', 'Długość: 47,5 cm');
        $long = $this->sleeve('REKAW-60', 'Długość: 60 cm');
        $this->stubRanking([
            ['id' => $short->id, 'score' => 95, 'reason' => 'Rękaw antyprzecięciowy.', 'missing_key' => []],
            ['id' => $exact->id, 'score' => 90, 'reason' => 'Rękaw antyprzecięciowy 47,5 cm.', 'missing_key' => []],
            ['id' => $long->id, 'score' => 88, 'reason' => 'Rękaw antyprzecięciowy.', 'missing_key' => []],
        ]);

        $rows = $this->search(self::SLEEVE);

        $this->assertSame(50, $this->percent($rows, 'REKAW-30'));
        $this->assertStringContainsString('Karta przeczy wymaganiu: Długość: karta 30 cm, wymagane ok. 475 mm', (string) $rows['REKAW-30']['ai_match_reason']);
        $this->assertStringStartsWith('Rękaw antyprzecięciowy.', (string) $rows['REKAW-30']['ai_match_reason'], 'uzasadnienie modelu zostaje');
        $this->assertSame(90, $this->percent($rows, 'REKAW-475'));
        $this->assertSame(88, $this->percent($rows, 'REKAW-60'), 'dłuższy rękaw nie przeczy wymaganiu (wymiar bez max. to minimum)');
        $this->assertStringNotContainsString('przeczy', (string) $rows['REKAW-60']['ai_match_reason']);
    }

    public function test_explicit_maximum_caps_larger_card(): void
    {
        $small = $this->sleeve('REKAW-40', 'Długość: 40 cm');
        $large = $this->sleeve('REKAW-60', 'Długość: 60 cm');
        $this->stubRanking([
            ['id' => $small->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $large->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękaw ochronny antyprzecięciowy, długość max. 45 cm');

        $this->assertSame(90, $this->percent($rows, 'REKAW-40'));
        $this->assertSame(50, $this->percent($rows, 'REKAW-60'));
        $this->assertStringContainsString('Długość: karta 60 cm, wymagane max. 45 cm', (string) $rows['REKAW-60']['ai_match_reason']);
    }

    public function test_unitless_or_conflicting_card_values_are_not_a_contradiction(): void
    {
        $unitless = $this->sleeve('REKAW-BEZ-JEDN', 'DŁUGOŚĆ 300 / 11.8');
        $conflicting = $this->sleeve('REKAW-SPRZECZNA', "Długość: 30 cm\nDługość: 47,5 cm");
        $this->stubRanking([
            ['id' => $unitless->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
            ['id' => $conflicting->id, 'score' => 90, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search(self::SLEEVE);

        $this->assertSame(90, $this->percent($rows, 'REKAW-BEZ-JEDN'));
        $this->assertSame(90, $this->percent($rows, 'REKAW-SPRZECZNA'), 'pola karty sobie przeczą — do sprawdzenia, nie sprzeczność');
    }

    public function test_requirement_without_dimensions_changes_nothing(): void
    {
        $short = $this->sleeve('REKAW-30', 'Długość: 30 cm');
        $this->stubRanking([
            ['id' => $short->id, 'score' => 93, 'reason' => 'ok', 'missing_key' => []],
        ]);

        $rows = $this->search('Rękaw ochronny antyprzecięciowy z włóknem szklanym');

        $this->assertSame(93, $this->percent($rows, 'REKAW-30'));
    }

    /**
     * Pozycje opisowy15 z wymiarem w wymaganiu, na kartach z produkcji: karta wzorcowa nie przeczy wymiarowi
     * (poz. 1 rękaw 47,5 cm, poz. 4 fartuch, poz. 6 soczewka 2,3 mm, poz. 11 płukanka) i zachowuje ocenę modelu.
     *
     * @return array<string, array{0: int}>
     */
    public static function fixtureLinesWithDimensions(): array
    {
        return ['poz. 1' => [1], 'poz. 4' => [4], 'poz. 6' => [6], 'poz. 11' => [11]];
    }

    #[DataProvider('fixtureLinesWithDimensions')]
    public function test_opisowy15_expected_card_is_not_a_contradiction(int $line): void
    {
        $this->seedFixture();
        $expected = (string) Opisowy15Fixture::line($line)['expected_sku'];

        $rows = $this->fixtureRows($line);

        $this->assertTrue($rows->has($expected), "poz. {$line}: brak karty wzorcowej {$expected} wśród ocen modelu");
        $this->assertSame(90, (int) $rows[$expected]['ai_match_percent']);
        $this->assertStringNotContainsString('przeczy', (string) $rows[$expected]['ai_match_reason']);
    }

    /**
     * Poz. 15: 87063100BP ma 320 mm przy „długość 300 mm” — dłuższa rękawica nie przeczy wymaganiu (decyzja D10),
     * więc limit wymiaru jej nie obcina; wzorcowa 87320100-BULK („300 / 11.8” bez jednostki) też nie.
     */
    public function test_opisowy15_line_15_longer_glove_is_not_a_contradiction(): void
    {
        $this->seedFixture();

        $rows = $this->fixtureRows(15);

        $this->assertSame(90, (int) ($rows['87320100-BULK']['ai_match_percent'] ?? 0));
        if ($rows->has('87063100BP')) {
            $this->assertStringNotContainsString('przeczy', (string) $rows['87063100BP']['ai_match_reason']);
        }
    }

    /**
     * @return array<string, int>
     */
    private function seedFixture(): array
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
        $ids = Opisowy15Fixture::seed();
        $this->app->instance(
            OpenAiCompatibleClient::class,
            FakeSearchLlm::replay(Opisowy15Fixture::items(), $ids, FakeSearchLlm::rankExpectedAndForbidden()),
        );

        return $ids;
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function fixtureRows(int $line): Collection
    {
        $result = app(ProductAiSearchService::class)
            ->searchMany([Opisowy15Fixture::requirement($line)], 80, false, AiTask::ProductSearch, 16);

        return collect($result[0]['products'] ?? [])
            ->filter(static fn (array $row): bool => ($row['ai_match_source'] ?? null) === null)
            ->keyBy('sku');
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function search(string $query): Collection
    {
        return collect(
            $this->postJson('/api/products/ai-search', ['query' => $query, 'limit' => 10])
                ->assertOk()
                ->json('products')
        )->keyBy('sku');
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $rows
     */
    private function percent(Collection $rows, string $sku): int
    {
        $this->assertTrue($rows->has($sku), $sku.' wypadła z wyniku: '.json_encode($rows->map(static fn (array $r): mixed => $r['ai_match_percent'] ?? null)->all()));

        return (int) $rows[$sku]['ai_match_percent'];
    }

    private function sleeve(string $sku, string $specs): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękaw ochronny antyprzecięciowy',
            'manufacturer' => 'TEST',
            'category' => 'Rękawice',
            'description' => 'Rękaw ochronny antyprzecięciowy z dzianiny z włóknem szklanym, EN 388.',
            'enrichment_payload' => ['specs' => preg_split('/\n/', $specs)],
            'catalog_price_net' => 60,
            'purchase_price' => 40,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     */
    private function stubRanking(array $matches): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function (array $messages) use ($matches): array {
            $system = (string) ($messages[0]['content'] ?? '');
            $intent = ['needed' => 'rękaw ochronny', 'search_phrases' => ['rękaw ochronny', 'rękaw antyprzecięciowy'], 'constraints' => []];

            return str_contains($system, '"matches"') ? $intent + ['matches' => $matches] : $intent;
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
