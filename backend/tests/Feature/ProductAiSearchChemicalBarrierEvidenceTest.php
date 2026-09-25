<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Limit D6 (decyzja właściciela z 25.09.2026): przy „kombinezonie chemoodpornym” kombinezon bez dowodu bariery dla
 * cieczy (typ 5/6) zostaje na liście, ale z oceną najwyżej 45 — pod progiem zapisu przetargu (65) i pod kartą typu 3/4,
 * której brakuje tylko dowodu na konkretną substancję (50). mail-kombinezon-chemo-kwas-siarkowy.
 */
final class ProductAiSearchChemicalBarrierEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Kombinezon chemoodporny antyelektrostatyczny, w szczególności na kwas siarkowy 96%';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_type_5_6_coverall_is_capped_below_type_3(): void
    {
        [$typ56, $typ3] = $this->coveralls();
        $this->stubRanking([
            ['id' => $typ56->id, 'score' => 95, 'reason' => 'Kombinezon chemoodporny, EN 1149-5.', 'missing_key' => []],
            ['id' => $typ3->id, 'score' => 95, 'reason' => 'Kombinezon typu 3, EN 1149-5.', 'missing_key' => []],
        ]);

        $rows = $this->search(self::REQUIREMENT);

        $this->assertSame(95, $this->percent($rows, 'TYP3'));
        $this->assertSame(45, $this->percent($rows, 'TYP56'));
        $this->assertStringContainsString('brak dowodu bariery (typ 3/4, EN 14605 / EN 943)', (string) $rows['TYP56']['ai_match_reason']);
        $this->assertStringStartsWith('Kombinezon chemoodporny, EN 1149-5.', (string) $rows['TYP56']['ai_match_reason'], 'uzasadnienie modelu zostaje');
        $this->assertSame(['TYP3', 'TYP56'], $rows->keys()->all());
    }

    /**
     * Test remisu: karta obcięta kodem nie wyprzedza karty, którą model sam ocenił na 50 (brak dowodu H2SO4), choć jest tańsza.
     */
    public function test_capped_type_5_6_does_not_overtake_type_3_rated_fifty_by_model(): void
    {
        [$typ56, $typ3] = $this->coveralls();
        $this->stubRanking([
            ['id' => $typ56->id, 'score' => 50, 'reason' => 'ok', 'missing_key' => ['odporność na kwas siarkowy 96%']],
            ['id' => $typ3->id, 'score' => 50, 'reason' => 'ok', 'missing_key' => ['odporność na kwas siarkowy 96%']],
        ]);

        $rows = $this->search(self::REQUIREMENT);

        $this->assertSame(50, $this->percent($rows, 'TYP3'));
        $this->assertSame(45, $this->percent($rows, 'TYP56'));
        $this->assertSame('TYP3', $rows->keys()->first(), 'tańszy typ 5/6 nie wygrywa remisu ceną');
    }

    public function test_waterproof_coverall_with_boots_is_not_capped(): void
    {
        $waders = $this->card('KOMB-WODO', 'Kombinezon wodoochronny z wgrzanymi kaloszami', 'Kombinezon PVC wodoochronny, EN 343, kalosze S5.', 50);
        $this->stubRanking([
            ['id' => $waders->id, 'score' => 88, 'reason' => 'ok', 'missing_key' => []],
        ], 'kombinezon wodoochronny');

        $rows = $this->search('Kombinezon wodoochronny z wgrzanymi kaloszami');

        $this->assertSame(88, $this->percent($rows, 'KOMB-WODO'));
    }

    public function test_requirement_asking_for_type_5_6_is_not_capped(): void
    {
        [$typ56] = $this->coveralls();
        $this->stubRanking([
            ['id' => $typ56->id, 'score' => 92, 'reason' => 'ok', 'missing_key' => []],
        ], 'kombinezon ochronny');

        $rows = $this->search('Kombinezon ochronny typ 5/6');

        $this->assertSame(92, $this->percent($rows, 'TYP56'));
    }

    /**
     * @return array{0: Product, 1: Product}
     */
    private function coveralls(): array
    {
        return [
            $this->card('TYP56', 'Kombinezon ochronny jednorazowy', 'Kombinezon ochronny, EN ISO 13982-1 typ 5, EN 13034 typ 6, EN 1149-5.', 20),
            $this->card('TYP3', 'Kombinezon ochronny chemiczny', 'Kombinezon ochronny, EN 14605 typ 3-B/4-B, EN 1149-5.', 90),
        ];
    }

    private function card(string $sku, string $name, string $description, float $purchase): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'TEST',
            'category' => 'Odzież ochronna',
            'description' => $description,
            'catalog_price_net' => $purchase * 1.5,
            'purchase_price' => $purchase,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
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

    /**
     * @param  list<array<string, mixed>>  $matches
     */
    private function stubRanking(array $matches, string $needed = 'kombinezon chemoodporny'): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function (array $messages) use ($matches, $needed): array {
            $system = (string) ($messages[0]['content'] ?? '');
            $intent = ['needed' => $needed, 'search_phrases' => [$needed, 'kombinezon ochronny'], 'constraints' => []];

            return str_contains($system, '"matches"') ? $intent + ['matches' => $matches] : $intent;
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
