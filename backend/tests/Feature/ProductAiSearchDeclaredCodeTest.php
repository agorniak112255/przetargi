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
 * Zapytanie z maila 23.09.2026 (zapytanie #50): klient podał symbol z katalogu REIS, a pozycja
 * wyszła „brak w katalogu”. „ściągaczem-symbol” (bez spacji) był igłą nazwanego modelu, więc
 * bramka modelu odrzucała każdą ocenę, a sam kod RNITz (bez cyfr) igłą nie był wcale. Zostawała
 * lista zapasowa („ten sam rodzaj”) z innymi rękawicami, której oferty nie przyjmują.
 */
final class ProductAiSearchDeclaredCodeTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Rękawice ochronne tkaninowe pięciopalcowe, powlekane nitrylem żółtym, '
        .'zakończone ściągaczem-symbol RNITz - 432 pary';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        // pula w remisie idzie od najświeższej karty — karty zakładane na przełomie sekundy
        // zmieniały kolejność między przebiegami (jak w ProductAiSearchCascadeTest)
        $this->freezeTime();
    }

    public function test_code_declared_by_symbol_label_points_at_the_catalog_card(): void
    {
        $decoy = $this->glove('X-NITRON', 'Rękawice X-NITRON', 'Rękawice powlekane nitrylem, żółte, ściągacz.', 2.10);
        $this->glove('RNITNL', 'Rękawice ochronne NITNL.', 'Rękawice powlekane nitrylem, ściągacz.', 2.00);
        $this->glove('RNITZ-SUPER', 'Rękawice ochronne NITZ-SUPER.', 'Dzianina bawełna z poliestrem, powlekane nitrylem, ściągacz.', 3.32);
        $this->glove('RNITZ', 'Rękawice ochronne NITZ.', 'Dzianina 100% bawełna, powlekane nitrylem, zakończone dzianinowym ściągaczem.', 2.74);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'rękawice ochronne powlekane nitrylem',
            'search_phrases' => ['rękawice powlekane nitrylem'],
            'search_steps' => ['rękawice', 'nitryl'],
            'constraints' => ['ściągacz'],
            'matches' => [
                ['id' => $decoy->id, 'score' => 90, 'reason' => 'rękawice powlekane nitrylem'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $products = $this->postJson('/api/products/ai-search', [
            'query' => self::QUERY,
            'limit' => 5,
        ])->assertOk()->json('products') ?? [];

        $skus = array_column($products, 'sku');
        $this->assertSame('RNITZ', $skus[0] ?? null, 'Karta z symbolem z maila nie stoi na czele wyniku.');
        // Trafienie po kodzie to nie wiersz zapasowy — oferty przyjmują tylko takie bez źródła „catalog”/„rule”.
        $this->assertNull($products[0]['ai_match_source'] ?? null);
        // kod przepisany przez klienta to nie „literówka dopuszczalna”
        $this->assertSame('Kod z zapytania klienta.', $products[0]['ai_match_reason'] ?? null);
        // RNITZ-SUPER zawiera kod, ale nim nie jest — nie udaje kodu klienta
        $super = array_values(array_filter($products, static fn (array $p): bool => $p['sku'] === 'RNITZ-SUPER'))[0] ?? null;
        $this->assertNotNull($super);
        $this->assertNotSame('Kod z zapytania klienta.', $super['ai_match_reason'] ?? null);
        // Kod przepisany z katalogu nie ma literówki: RNITNL to inny wyrób, nie „RNITz z błędem”.
        $this->assertNotContains('RNITNL', $skus);
        $this->assertNotContains('X-NITRON', $skus);
    }

    private function glove(string $sku, string $name, string $description, float $purchase): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'REIS',
            'category' => 'Rękawice ochronne',
            'description' => $description,
            'norms' => 'EN 388, EN ISO 21420',
            'search_blob' => mb_strtolower($sku.' '.$name.' '.$description).' rękawice ochronne nitryl',
            'ppe_family' => 'gloves',
            'catalog_price_net' => $purchase * 1.4,
            'purchase_price' => $purchase,
            'stock' => 10,
        ]);
    }
}
