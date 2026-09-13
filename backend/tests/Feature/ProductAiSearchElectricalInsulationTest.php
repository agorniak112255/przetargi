<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Elektroizolacja (EN 50321 / kV) w wyszukiwarce działa jak antystatyka: wymagana w zapytaniu,
 * a karta jej nie pokazuje → karta odpada w `keepCompatible`, zanim skrót klasy obuwia (OB = 92%)
 * zrówna zwykłe półbuty ESD z elektroizolacyjnymi (poz. 12 audytu).
 */
final class ProductAiSearchElectricalInsulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_electrical_insulation_query_drops_ob_esd_shoes(): void
    {
        $plainOb = Product::query()->create([
            'sku' => 'ART 702 Air 6660 OB A E FO',
            'name' => 'ART 702 Air 6660 OB A E FO',
            'manufacturer' => 'ARTRA',
            'description' => 'Obuwie robocze ART 702 Air 6660 OB A E FO to lekkie buty do kontroli ładunków elektrostatycznych. '
                .'Spełnia normę EN ISO 20347:2012 w klasie OB A E FO SRC oraz wymagania ESD zgodnie z EN IEC 61340-4-3:2018.',
            'catalog_price_net' => 45,
            'purchase_price' => 31.70,
            'stock' => 10,
        ]);
        $insulating = Product::query()->create([
            'sku' => 'T5912100',
            'name' => 'Półbuty elektroizolacyjne 20 kV - ANTYAMPER',
            'manufacturer' => 'SECURA',
            'category' => '11.1 OBUWIE ELEKTROIZOLACYJNE',
            'description' => 'Półbuty elektroizolacyjne ANTYAMPER 20 kV marki SECURA do pracy przy instalacjach o napięciu do 17 kV. '
                .'Produkt klasy 2 AC zgodnie z normą EN 50321-1. Wykonane z gumy naturalnej. EN 20347:2012 kategorii OB, SRA.',
            'catalog_price_net' => 520,
            'purchase_price' => 384.73,
            'stock' => 3,
        ]);
        $this->assertSame('footwear', $plainOb->fresh()?->ppe_family);
        $this->assertSame('footwear', $insulating->fresh()?->ppe_family);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(static function (array $messages) use ($plainOb, $insulating): array {
            $system = (string) ($messages[0]['content'] ?? '');
            if (str_contains($system, '"manufacturer"')) {
                return [
                    'needed' => 'półbuty elektroizolacyjne 20 kV klasa 2 AC',
                    'manufacturer' => null,
                    'search_phrases' => ['półbuty elektroizolacyjne', 'EN 50321', 'obuwie OB'],
                    'constraints' => ['EN 50321-1', 'klasa 2 AC'],
                ];
            }

            return [
                'matches' => [
                    ['id' => $plainOb->id, 'score' => 80, 'reason' => 'obuwie OB'],
                    ['id' => $insulating->id, 'score' => 75, 'reason' => 'elektroizolacyjne'],
                ],
            ];
        });
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $response = $this->postJson('/api/products/ai-search', [
            'query' => 'Półbuty elektroizolacyjne do prac przy urządzeniach elektroenergetycznych o napięciu do 17 kV. '
                .'Wymagane: klasa 2 AC zgodnie z normą EN 50321-1; zgodność z EN 20347:2012 dla obuwia kategorii OB; SRA.',
            'limit' => 10,
        ])->assertOk();

        $skus = array_column($response->json('products'), 'sku');
        $this->assertContains('T5912100', $skus);
        $this->assertNotContains('ART 702 Air 6660 OB A E FO', $skus, 'zwykłe OB z ESD nie pokazuje elektroizolacji');
    }

    /**
     * `keepCompatible` sprawdza elektroizolację niezależnie od bramki rodzaju: zapytanie bez
     * rzeczownika rodziny („ochrona elektroizolacyjna…”) przechodzi `compatibleProduct` dla
     * każdej karty, a mimo to karta bez EN 50321 / kV ma odpaść.
     */
    public function test_keep_compatible_requires_insulation_even_without_family_noun(): void
    {
        $plainOb = Product::query()->create([
            'sku' => 'ART 702 Air 6660 OB A E FO',
            'name' => 'ART 702 Air 6660 OB A E FO',
            'manufacturer' => 'ARTRA',
            'description' => 'Obuwie robocze OB A E FO SRC, ESD EN IEC 61340-4-3:2018.',
            'catalog_price_net' => 45,
            'purchase_price' => 31.70,
            'stock' => 10,
        ]);
        $insulating = Product::query()->create([
            'sku' => 'T5912100',
            'name' => 'ANTYAMPER 20 kV',
            'manufacturer' => 'SECURA',
            'description' => 'Produkt klasy 2 AC zgodnie z normą EN 50321-1.',
            'catalog_price_net' => 520,
            'purchase_price' => 384.73,
            'stock' => 3,
        ]);
        $query = 'Ochrona elektroizolacyjna stóp do 17 kV, klasa 2 AC wg EN 50321-1';
        $this->assertNull(app(PpeAssortment::class)->family($query), 'zapytanie celowo bez rzeczownika rodziny');

        $method = new \ReflectionMethod(ProductAiSearchService::class, 'keepCompatible');
        $kept = $method->invoke(app(ProductAiSearchService::class), $query, collect([$plainOb, $insulating]));

        $this->assertSame(['T5912100'], $kept->pluck('sku')->all());
    }
}
