<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Warunek szczególny z wiersza klienta a treść oferty.
 *
 * Skąd to się wzięło: na zapytanie „Kombinezon chemoodporny (w szczególności
 * na kwas siarkowy 96%)” poszła oferta z kombinezonem, którego karta ani słowem
 * nie mówi o kwasie siarkowym. List o warunku milczał, a milczenie klient czyta
 * jak potwierdzenie. Odtąd karta bez potwierdzenia nie wchodzi do listu.
 */
final class ClientInquiryRequirementApiTest extends TestCase
{
    use RefreshDatabase;

    private const MAIL = "Dzień dobry\n\n8szt Kombinezon chemoodporny "
        .'( w szczególności na kwas siarkowy 96%) antyelektrostatyczny, rozmiar uniwersalny';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function suit(string $description): Product
    {
        return Product::query()->create([
            'sku' => 'AT5000-G05',
            'name' => 'Kombinezon AlphaTec 5000',
            'manufacturer' => 'Ansell',
            'norms' => 'EN 1149-5, EN 14126',
            'description' => $description,
            'catalog_price_net' => 270.30,
            'purchase_price' => 180.00,
            'stock' => 20,
        ]);
    }

    private function mockAnalysis(Product $product): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->andReturn([
                'subject' => 'Kombinezony',
                'questions' => [],
                'product_queries' => ['kombinezon chemoodporny'],
                'line_items' => [],
                'cards' => [],
            ]);
        });

        $row = [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'manufacturer' => $product->manufacturer,
            'norms' => (string) $product->norms,
            'catalog_price_net' => '270.30',
            'currency' => 'PLN',
            'stock' => 20,
            // model ocenił dopasowanie wysoko — bez warunku wszedłby do listu
            'ai_match_percent' => 92,
        ];
        $this->mock(ProductInquirySearch::class, function ($mock) use ($row): void {
            $mock->shouldReceive('findMany')->andReturnUsing(
                fn (array $queries): array => array_map(
                    fn (string $q): array => ['query' => $q, 'products' => [$row]],
                    $queries
                )
            );
        });
    }

    public function test_card_silent_about_the_acid_does_not_enter_the_letter(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->suit(
            'Kombinezon ochronny do pracy w środowisku zagrożonym działaniem niebezpiecznych '
            .'substancji chemicznych. Czas przenikania dla 14 z 15 substancji wymienionych '
            .'w normie EN ISO 6529 przekracza 480 minut.'
        );
        $this->mockAnalysis($product);

        Sanctum::actingAs($user);
        $res = $this->postJson('/api/inquiries', ['body' => self::MAIL, 'tone' => 'handlowy'])
            ->assertCreated();

        // Do klienta idzie to samo co przy braku w katalogu — nie propozycja.
        $body = (string) $res->json('reply_body');
        $this->assertStringContainsString('Pozycję potwierdzimy po weryfikacji', $body);
        $this->assertStringNotContainsString('AlphaTec', $body);

        // Handlowiec musi to zobaczyć w aplikacji, razem z treścią warunku.
        $item = $res->json('items.0');
        $this->assertContains('requirement_unconfirmed', $item['flags']);
        $this->assertSame('check', $item['chosen']);
        $this->assertSame('kwas siarkowy 96%', $item['requirements'][0]['text']);
        $this->assertTrue($item['requirements'][0]['checkable']);
        $this->assertFalse($item['candidates'][0]['requirements_ok']);
        $this->assertGreaterThan(0, (int) $res->json('attention_count'));
    }

    public function test_card_confirming_the_acid_is_offered_normally(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->suit(
            'Kombinezon ochronny do prac chemicznych. Tabela czasów przenikania: '
            .'kwas siarkowy 96% — powyżej 480 minut, wodorotlenek sodu 40% — powyżej 480 minut.'
        );
        $this->mockAnalysis($product);

        Sanctum::actingAs($user);
        $res = $this->postJson('/api/inquiries', ['body' => self::MAIL, 'tone' => 'handlowy'])
            ->assertCreated();

        $body = (string) $res->json('reply_body');
        $this->assertStringContainsString('Kombinezon AlphaTec 5000', $body);

        $item = $res->json('items.0');
        $this->assertNotContains('requirement_unconfirmed', $item['flags']);
        $this->assertSame('p:'.$product->id, $item['chosen']);
        $this->assertTrue($item['requirements'][0]['ok']);
        $this->assertTrue($item['candidates'][0]['requirements_ok']);
    }

    public function test_salesman_can_still_choose_the_unconfirmed_card_himself(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->suit('Kombinezon ochronny do prac chemicznych, EN 14126.');
        $this->mockAnalysis($product);

        Sanctum::actingAs($user);
        $created = $this->postJson('/api/inquiries', ['body' => self::MAIL, 'tone' => 'handlowy'])
            ->assertCreated();
        $id = (int) $created->json('id');
        $itemId = (string) $created->json('items.0.id');

        // Decyzja człowieka wygrywa — bramka pilnuje tylko domyślnego wyboru.
        $res = $this->postJson("/api/inquiries/{$id}/compose", [
            'answers' => ['product:'.$itemId => ['option_id' => 'p:'.$product->id]],
        ])->assertOk();

        $this->assertStringContainsString('Kombinezon AlphaTec 5000', (string) $res->json('reply_body'));
        // Ostrzeżenie zostaje: stan pozycji nadal wymaga sprawdzenia.
        $this->assertContains('requirement_unconfirmed', $res->json('items.0.flags'));
    }

    public function test_clause_without_a_substance_only_warns_the_salesman(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->suit('Kombinezon ochronny do prac chemicznych, EN 14126.');
        $this->mockAnalysis($product);

        Sanctum::actingAs($user);
        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n8szt Kombinezon ochronny, w szczególności do prac w kanalizacji",
            'tone' => 'handlowy',
        ])->assertCreated();

        $item = $res->json('items.0');
        // Takiego warunku żadna reguła nie sprawdzi — nie udajemy, że sprawdziliśmy,
        // ale i nie blokujemy oferty; człowiek musi to przeczytać.
        $this->assertContains('requirement_note', $item['flags']);
        $this->assertNotContains('requirement_unconfirmed', $item['flags']);
        $this->assertSame('do prac w kanalizacji', $item['requirements'][0]['text']);
        $this->assertFalse($item['requirements'][0]['checkable']);
        $this->assertStringContainsString('Kombinezon AlphaTec 5000', (string) $res->json('reply_body'));
    }

    public function test_position_without_any_requirement_works_as_before(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->suit('Kombinezon ochronny do prac chemicznych, EN 14126.');
        $this->mockAnalysis($product);

        Sanctum::actingAs($user);
        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n8szt Kombinezon ochronny rozmiar XL",
            'tone' => 'handlowy',
        ])->assertCreated();

        $item = $res->json('items.0');
        $this->assertSame([], $item['requirements']);
        $this->assertNotContains('requirement_unconfirmed', $item['flags']);
        $this->assertNull($item['candidates'][0]['requirements_ok']);
        $this->assertStringContainsString('Kombinezon AlphaTec 5000', (string) $res->json('reply_body'));
    }
}
