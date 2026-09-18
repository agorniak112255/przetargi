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
 * Zapytanie klienta z maila: marka, kod wyrobu zapisany z kropką i klasa obuwia.
 * Katalog trzyma kod z ukośnikami („8543/8/35”), a „S1” z zapytania wygląda jak kod
 * i trafia w setki nazw butów. Dotąd te trafienia zajmowały całą pulę kodową i karta
 * z prawdziwym kodem nie wchodziła do oceny — wyszukiwarka oddawała same obce marki.
 */
final class ProductAiSearchModelCodeFloodTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'BUTY UVEX BUSINESS CASUAL 8543.8 S1 SRC ROZMIAR 44';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_code_hit_survives_a_pool_flooded_by_the_footwear_class(): void
    {
        $uvex = $this->seedCatalog();

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => 'buty ochronne',
            'manufacturer' => 'UVEX',
            'model_name' => '8543.8',
            'search_phrases' => ['buty uvex 8543.8', 'obuwie ochronne S1 SRC'],
            'search_steps' => ['buty', 'ochronne', 'S1', 'SRC', 'UVEX'],
            'constraints' => ['SRC'],
            'matches' => [
                ['id' => $uvex->id, 'score' => 95, 'reason' => 'Uvex 8543/8, S1 SRC'],
            ],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $response = $this->postJson('/api/products/ai-search', [
            'query' => self::QUERY,
            'limit' => 5,
        ])->assertOk();

        $skus = array_column($response->json('products') ?? [], 'sku');
        $this->assertContains('8543/8/35', $skus, 'Karta z kodem z zapytania nie weszła do wyniku.');
        $this->assertSame('8543/8/35', $skus[0] ?? null);
    }

    /** Karta z kodem z zapytania zakładana jako ostatnia — tak jak świeżo pobrana z B2B. */
    private function seedCatalog(): Product
    {
        // więcej wabików niż wynosi pula kandydatów, żeby test odtwarzał zatłoczenie z produkcji
        for ($i = 1; $i <= 90; $i++) {
            Product::query()->create([
                'sku' => 'AROX-733-'.$i,
                'name' => 'Trzewik AROX 733 6414'.$i.' S1 ESD',
                'manufacturer' => 'ARTRA',
                'category' => 'Obuwie',
                'search_blob' => 'trzewik arox 733 s1 esd obuwie ochronne artra',
                'ppe_family' => 'footwear',
                'catalog_price_net' => 150,
                'purchase_price' => 100,
                'stock' => 5,
            ]);
        }

        return Product::query()->create([
            'sku' => '8543/8/35',
            'name' => 'Półbut Uvex 1 8543/8',
            'manufacturer' => 'UVEX',
            'category' => 'Obuwie',
            'description' => 'Półbut roboczy uvex 1 business casual, klasa ochrony S1 SRC, EN ISO 20345.',
            'norms' => 'EN ISO 20345 S1 SRC',
            'search_blob' => '8543/8/35 polbut uvex 1 8543/8 uvex obuwie s1 src en iso 20345',
            'ppe_family' => 'footwear',
            'catalog_price_net' => 304.50,
            'purchase_price' => 210,
            'stock' => 1,
        ]);
    }
}
