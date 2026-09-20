<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Stopień zaciemnienia filtra spawalniczego to funkcja ochronna. Model potrafił dać 90–95% goglom,
 * których karta o filtrze milczy, nazywając ten brak „drugorzędnym” — a wtedy kod nie miał czego
 * egzekwować, bo sufit działał tylko przy wypełnionym `missing_key`. Dowód sprawdzamy sami,
 * tak jak typ obuwia przy sandałach. Karta, która filtr pokazuje, zachowuje ocenę modelu.
 */
final class ProductAiSearchWeldingFilterEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Gogle ochronne szczelne, spawalnicze, z zaciemnieniem 5.0 do spawania gazowego';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_goggles_without_any_welding_filter_evidence_are_capped_at_fifty(): void
    {
        // Jak prawdziwa karta uvex megasonic 9320.281: mówi o spawaniu (iskry), ale nie pokazuje filtra
        // ani stopnia zaciemnienia. Karta, która o spawaniu milczy w ogóle, odpada wcześniej na bramce żargonu.
        $silent = $this->goggles('GOGLE-BEZ-FILTRA', 'Gogle ochronne szczelne', 'Gogle ochronne EN 166, odporność B 120 m/s, odporne na iskry spawalnicze, można nosić na okularach korekcyjnych.');
        $proven = $this->goggles('GOGLE-Z-FILTREM', 'Gogle spawalnicze szczelne', 'Gogle szczelne, zaciemnienie spawalnicze 5.0, EN 166, EN 169.');
        $this->stubRanking([
            ['id' => $silent->id, 'score' => 95, 'reason' => 'Gogle szczelne EN 166. Brak dowodu na zaciemnienie 5.0 (drugorzędne).', 'missing_key' => []],
            ['id' => $proven->id, 'score' => 95, 'reason' => 'Gogle spawalnicze z zaciemnieniem 5.0, EN 169.', 'missing_key' => []],
        ]);

        $rows = collect(
            $this->postJson('/api/products/ai-search', ['query' => self::REQUIREMENT, 'limit' => 10])
                ->assertOk()
                ->json('products')
        )->keyBy('sku');

        $seen = json_encode($rows->map(static fn (array $r): array => [$r['ai_match_percent'] ?? null, $r['ai_match_source'] ?? 'model'])->all());
        $this->assertSame(95, (int) ($rows['GOGLE-Z-FILTREM']['ai_match_percent'] ?? 0), 'karta z dowodem filtra straciła ocenę modelu: '.$seen);
        $this->assertSame(50, (int) ($rows['GOGLE-BEZ-FILTRA']['ai_match_percent'] ?? 0), 'gogle bez dowodu filtra zachowały wysoką ocenę albo wypadły z wyniku: '.$seen);
        $this->assertStringContainsString('filtr spawalniczy', (string) ($rows['GOGLE-BEZ-FILTRA']['ai_match_reason'] ?? ''));
        $this->assertSame('GOGLE-Z-FILTREM', $rows->keys()->first(), 'karta z dowodem ma stać przed kartą bez dowodu');
    }

    public function test_requirement_without_welding_filter_does_not_cap_plain_goggles(): void
    {
        $plain = $this->goggles('GOGLE-ZWYKLE', 'Gogle ochronne szczelne', 'Gogle ochronne EN 166, odporność B 120 m/s.');
        $this->stubRanking([
            ['id' => $plain->id, 'score' => 92, 'reason' => 'Gogle ochronne szczelne, EN 166.', 'missing_key' => []],
        ]);

        $rows = $this->postJson('/api/products/ai-search', ['query' => 'Gogle ochronne szczelne EN 166 do prac z pyłem', 'limit' => 10])
            ->assertOk()
            ->json('products');

        $this->assertSame(92, (int) (collect($rows)->firstWhere('sku', 'GOGLE-ZWYKLE')['ai_match_percent'] ?? 0));
    }

    private function goggles(string $sku, string $name, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'TEST',
            'category' => 'Ochrona oczu',
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

            return str_contains($system, '"matches"')
                ? ['needed' => 'gogle ochronne', 'search_phrases' => ['gogle ochronne', 'gogle spawalnicze'], 'constraints' => [], 'matches' => $matches]
                : ['needed' => 'gogle ochronne', 'search_phrases' => ['gogle ochronne', 'gogle spawalnicze'], 'constraints' => []];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }
}
