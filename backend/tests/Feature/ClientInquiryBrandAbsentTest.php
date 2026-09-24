<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Zapytanie #67 (24.09.2026): „Rękawiczki nitrylowe MedaSept EASYGRIP PURPLE” — marki nie ma w katalogu, wyszukiwarka
 * dała zamienniki innej marki z 95%, a ekran zapytania pokazał Unicare (niebieskie) jako „pewne” do listu.
 */
final class ClientInquiryBrandAbsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_substitute_of_absent_brand_waits_for_the_salesperson(): void
    {
        $unicare = $this->unicare();
        $res = $this->analyze($unicare, 'EASYGRIP');

        $res->assertJsonPath('items.0.brand_not_in_catalog', 'EASYGRIP')
            ->assertJsonPath('items.0.confidence', 'medium')
            ->assertJsonPath('items.0.chosen', 'check')
            ->assertJsonPath('items.0.candidates.0.sku', 'GS003/W');
        $this->assertContains('brand_not_in_catalog', (array) $res->json('items.0.flags'));
        $this->assertStringNotContainsString('GS003/W', (string) $res->json('reply_body'), 'zamiennik nie wchodzi do listu bez wyboru');
    }

    public function test_brand_present_keeps_confident_match(): void
    {
        $unicare = $this->unicare();
        $res = $this->analyze($unicare, null);

        $res->assertJsonPath('items.0.brand_not_in_catalog', null)
            ->assertJsonPath('items.0.confidence', 'high')
            ->assertJsonPath('items.0.chosen', 'p:'.$unicare->id);
    }

    public function test_search_passes_absent_brand_from_parsed_intent(): void
    {
        // AiProductSearch jest final — sprawdzamy odczyt wyniku wyszukiwarki wprost.
        $read = new ReflectionMethod(ProductInquirySearch::class, 'absentBrand');
        $search = app(ProductInquirySearch::class);

        $this->assertSame('EASYGRIP', $read->invoke($search, [
            'parsed_intent' => ['manufacturer_requested' => 'EASYGRIP', 'manufacturer_absent_in_catalog' => true],
        ]));
        $this->assertNull($read->invoke($search, ['parsed_intent' => ['manufacturer_requested' => 'Ansell']]), 'marka w katalogu — to nie zamiennik');
        $this->assertNull($read->invoke($search, ['products' => []]), 'wynik bez rozpoznanej marki');
    }

    private function unicare(): Product
    {
        return Product::query()->create([
            'sku' => 'GS003/W',
            'name' => 'Rękawice nitrylowe Unicare bezpudrowe',
            'manufacturer' => 'UNIGLOVES',
            'catalog_price_net' => 12.5,
            'purchase_price' => 8,
            'stock' => 40,
        ]);
    }

    private function analyze(Product $product, ?string $absentBrand): TestResponse
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawiczki nitrylowe',
                'questions' => [],
                'product_queries' => ['rękawiczki nitrylowe MedaSept EASYGRIP PURPLE'],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock) use ($product, $absentBrand): void {
            $mock->shouldReceive('findMany')->once()->andReturn([[
                'query' => 'rękawiczki nitrylowe MedaSept EASYGRIP PURPLE',
                'products' => [[
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'manufacturer' => $product->manufacturer,
                    'catalog_price_net' => '12.50',
                    'purchase_price' => '8.00',
                    'currency' => 'PLN',
                    'stock' => 40,
                    'ai_match_percent' => 95,
                ]],
                'model_state' => 'ranked',
                'requested_brand_absent' => $absentBrand,
            ]]);
        });
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        return $this->postJson('/api/inquiries', [
            'body' => 'Dzień dobry, proszę o ofertę na rękawiczki nitrylowe MedaSept EASYGRIP PURPLE.',
            'tone' => 'handlowy',
        ])->assertCreated();
    }
}
