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
 * Decyzja właściciela z 27.09.2026: po skróceniu uzasadnienia modelu (same braki) pomiar golden pokazał płukankę 200 ml
 * z „pełną zgodnością” przy wymaganiu butelki 500 ml. Karta, której pojemność jest wprost mniejsza od wymaganej o więcej
 * niż 5%, dostaje najwyżej 50 i dopisek z cytatem karty i wymagania — niezależnie od oceny modelu. Większa butelka nie
 * przeczy wymaganiu (jak wymiar bez „max.”), zestaw „2x500 ml” liczy się pojemnością sztuki, a kilka pojemności na jednej
 * karcie to „do sprawdzenia”, nie sprzeczność.
 */
final class ProductAiSearchCapacityContradictionTest extends TestCase
{
    use RefreshDatabase;

    private const EYE_WASH = 'Płukanka do oczu – sterylny roztwór soli fizjologicznej. Wymagane: butelka o pojemności 500 ml z końcówką w kształcie wanienki.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_smaller_bottle_is_capped_and_larger_or_multipack_keeps_model_score(): void
    {
        $exact = $this->eyeWash('PLUK-500', 'Płukanka do oczu 500 ml');
        $small = $this->eyeWash('PLUK-200', 'Płukanka do oczu 200 ml');
        $pack = $this->eyeWash('PLUK-2X500', 'Płukanka do oczu 2x500 ml');
        $large = $this->eyeWash('PLUK-1000', 'Płukanka do oczu 1000 ml');
        // Model w nowym formacie: przy pełnej zgodności samo id i score, bez reason (tak ocenił kartę 200 ml w pomiarze).
        $this->stubRanking([
            ['id' => $exact->id, 'score' => 99],
            ['id' => $small->id, 'score' => 99],
            ['id' => $pack->id, 'score' => 95],
            ['id' => $large->id, 'score' => 90],
        ]);

        $rows = $this->search(self::EYE_WASH);

        $this->assertSame(50, $this->percent($rows, 'PLUK-200'));
        $this->assertSame(
            'Karta przeczy wymaganiu: Pojemność: karta 200 ml, wymagane 500 ml.',
            (string) $rows['PLUK-200']['ai_match_reason'],
        );
        $this->assertSame(99, $this->percent($rows, 'PLUK-500'));
        $this->assertSame(95, $this->percent($rows, 'PLUK-2X500'), 'zestaw 2x500 ml ma butelki o wymaganej pojemności');
        $this->assertSame(90, $this->percent($rows, 'PLUK-1000'), 'większa butelka nie przeczy wymaganiu wprost');
        $this->assertStringNotContainsString('Pojemność', (string) ($rows['PLUK-1000']['ai_match_reason'] ?? ''));
        $this->assertSame('PLUK-500', $rows->keys()->first());
    }

    public function test_card_listing_several_capacities_is_not_a_contradiction(): void
    {
        $variants = $this->eyeWash('PLUK-WARIANTY', 'Płukanka do oczu', 'Sterylna płukanka do oczu, dostępna w butelkach 200 ml i 500 ml.');
        $this->stubRanking([['id' => $variants->id, 'score' => 92]]);

        $rows = $this->search(self::EYE_WASH);

        $this->assertSame(92, $this->percent($rows, 'PLUK-WARIANTY'), 'nie wiadomo, której wersji dotyczy karta — to nie sprzeczność');
    }

    public function test_requirement_without_capacity_changes_nothing(): void
    {
        $small = $this->eyeWash('PLUK-200', 'Płukanka do oczu 200 ml');
        $this->stubRanking([['id' => $small->id, 'score' => 93]]);

        $rows = $this->search('Płukanka do oczu – sterylny roztwór soli fizjologicznej z końcówką w kształcie wanienki.');

        $this->assertSame(93, $this->percent($rows, 'PLUK-200'));
    }

    private function search(string $query): Collection
    {
        return collect(
            $this->postJson('/api/products/ai-search', ['query' => $query, 'limit' => 10])
                ->assertOk()
                ->json('products')
        )->keyBy('sku');
    }

    private function percent(Collection $rows, string $sku): int
    {
        $this->assertTrue($rows->has($sku), $sku.' wypadła z wyniku: '.json_encode($rows->map(static fn (array $r): mixed => $r['ai_match_percent'] ?? null)->all()));

        return (int) $rows[$sku]['ai_match_percent'];
    }

    private function eyeWash(string $sku, string $name, string $description = 'Sterylna płukanka do oczu z roztworem soli fizjologicznej, końcówka w kształcie wanienki.'): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'TEST',
            'category' => 'Pierwsza pomoc',
            'description' => $description,
            'catalog_price_net' => 60,
            'purchase_price' => 40,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }

    /** @param  list<array<string, mixed>>  $matches */
    private function stubRanking(array $matches): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function (array $messages) use ($matches): array {
            $system = (string) ($messages[0]['content'] ?? '');
            $intent = ['needed' => 'płukanka do oczu', 'search_phrases' => ['płukanka do oczu', 'płukanka'], 'constraints' => []];

            return str_contains($system, '"matches"') ? $intent + ['matches' => $matches] : $intent;
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
