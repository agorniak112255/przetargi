<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zapytanie #67 (24.09.2026): klient prosił o BW100SCF/LB101/AZ011/023, a pozycja miała wybraną wycofaną kartę
 * PROTEKT BW200/AZ011. Panel tego nie pokazywał, za to list w szablonie oficjalnym przepisał klientowi dopisek
 * łącznika: „UWAGA: produkt wycofany przez producenta — Wycofany — zastąpiony przezBW100.”
 */
final class ClientInquiryWithdrawnProductTest extends TestCase
{
    use RefreshDatabase;

    private const FEATURES = "Cechy szczególne:\n- Dopuszczone do prac w strefach zagrożonych wybuchem";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_withdrawn_choice_is_flagged_with_successor_and_kept_out_of_the_letter(): void
    {
        [$user, $inquiry, $withdrawn, $current] = $this->inquiry();
        Sanctum::actingAs($user);

        $res = $this->postJson("/api/inquiries/{$inquiry->id}/compose", ['answers' => []])->assertOk();

        $res->assertJsonPath('items.0.chosen', 'p:'.$withdrawn->id)
            ->assertJsonPath('items.0.candidates.0.withdrawn', ['successor' => 'BW100'])
            ->assertJsonPath('items.0.candidates.1.withdrawn', null)
            ->assertJsonPath('items.0.substitutes.0.withdrawn', ['successor' => null])
            // wynik 99 to „pasuje do wymagania”, nie „da się zamówić” — najwyżej „sprawdź”
            ->assertJsonPath('items.0.confidence', 'medium')
            ->assertJsonPath('attention_count', 1);
        $this->assertContains('withdrawn', (array) $res->json('items.0.flags'));

        $body = (string) $res->json('reply_body');
        $this->assertStringContainsString('ABM - Amortyzator bezpieczeństwa z zatrzaśnikiem AZ011', $body);
        $this->assertStringContainsString('Dopuszczone do prac w strefach zagrożonych wybuchem', $body);
        $this->assertStringNotContainsString('wycofany', mb_strtolower($body));
        // Cytat klienta w liście ma „BW100SCF” — sprawdzamy sam dopisek o następcy.
        $this->assertStringNotContainsString('zastąpiony', $body);

        // Dane źródłowe bez zmian — odstęp „przez BW100” to sprawa wyświetlania, nie karty.
        $this->assertStringStartsWith(
            'UWAGA: produkt wycofany przez producenta — Wycofany — zastąpiony przezBW100.',
            (string) $withdrawn->fresh()->description,
        );

        // Bieżący wyrób: bez flagi i z pełną pewnością.
        $res = $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => ['product:item_1' => ['option_id' => 'p:'.$current->id]],
        ])->assertOk();
        $res->assertJsonPath('items.0.confidence', 'high')
            ->assertJsonPath('attention_count', 0);
        $this->assertNotContains('withdrawn', (array) $res->json('items.0.flags'));
    }

    public function test_withdrawn_substitute_in_the_letter_is_flagged_too(): void
    {
        [$user, $inquiry, , $current, $substitute] = $this->inquiry();
        Sanctum::actingAs($user);

        $res = $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => [
                'product:item_1' => ['option_id' => 'p:'.$current->id],
                'substitutes:item_1' => ['option_id' => 'p:'.$substitute->id],
            ],
        ])->assertOk();

        $this->assertContains('withdrawn', (array) $res->json('items.0.flags'));
        $this->assertStringNotContainsString('wycofany', mb_strtolower((string) $res->json('reply_body')));
    }

    /**
     * @return array{0: User, 1: ClientInquiry, 2: Product, 3: Product, 4: Product}
     */
    private function inquiry(): array
    {
        $withdrawn = $this->product('BW200/AZ011', 'ABM - Amortyzator bezpieczeństwa z zatrzaśnikiem AZ011',
            "UWAGA: produkt wycofany przez producenta — Wycofany — zastąpiony przezBW100.\n\n".self::FEATURES);
        $current = $this->product('BW100/LB101/AZ011/AZ023', 'BW100/LB101 - Amortyzator bezpieczeństwa z linką',
            self::FEATURES);
        $substitute = $this->product('AW170/LB101', 'AW170/LB101 - Amortyzator bezpieczeństwa z linką',
            "UWAGA: produkt wycofany przez producenta — Wycofany.\n\n".self::FEATURES);

        $row = fn (Product $p, int $score): array => [
            'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'manufacturer' => 'PROTEKT', 'norms' => 'EN 355',
            'catalog_price_net' => '259.00', 'currency' => 'PLN', 'catalog_pln' => 259.0, 'offer_pln' => 300.0,
            'stock' => 3, 'score' => $score,
        ];

        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_subject' => 'Amortyzator',
            'source_body' => 'Amortyzator bezpieczeństwa z linką 1,5m BW100SCF/LB101/AZ011/023 PROTEKT z zatrzaskiem - 1 szt',
            'analysis' => [
                'line_items' => [[
                    'id' => 'item_1',
                    'quote' => 'Amortyzator bezpieczeństwa z linką 1,5m BW100SCF/LB101/AZ011/023 PROTEKT z zatrzaskiem - 1 szt',
                    'qty' => '1', 'unit' => 'szt', 'size' => null,
                    'query' => 'Amortyzator bezpieczeństwa BW100SCF/LB101/AZ011/023',
                ]],
                'matches' => [[
                    'query' => 'Amortyzator bezpieczeństwa BW100SCF/LB101/AZ011/023',
                    'products' => [$row($withdrawn, 99), $row($current, 70)],
                ]],
                'substitutes' => [$withdrawn->id => [$row($substitute, 0)]],
                'cards' => [],
            ],
            'answers' => ['price' => ['option_id' => 'none']],
        ]);

        return [$user, $inquiry, $withdrawn, $current, $substitute];
    }

    private function product(string $sku, string $name, string $description): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'PROTEKT',
            'description' => $description,
            'catalog_price_net' => 259,
            'purchase_price' => 142.45,
            'stock' => 3,
        ]);
    }
}
