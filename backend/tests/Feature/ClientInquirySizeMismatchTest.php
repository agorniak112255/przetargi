<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zapytanie MESKO (24.09.2026), pozycja 6: „Szelki bezpieczeństwa Protekt p-50mX rozmiar M-XL AB15021 - 1 szt”.
 * Wybrana karta to „P-50mX - Szelki bezpieczeństwa - rozmiar S”, a kafelek w liście pisał „rozmiar M-XL” —
 * list wyglądał, jakbyśmy potwierdzali M-XL, oferując S. Handlowiec ma to zobaczyć przed wysłaniem.
 */
final class ClientInquirySizeMismatchTest extends TestCase
{
    use RefreshDatabase;

    private const HARNESS_S = 31;

    private const HARNESS_MXL = 32;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_card_in_other_size_than_the_inquiry_is_flagged_without_changing_the_choice(): void
    {
        $res = $this->compose($this->harnessInquiry('M-XL'), 'p:'.self::HARNESS_S);

        $res->assertJsonPath('items.0.chosen', 'p:'.self::HARNESS_S)
            ->assertJsonPath('items.0.size', 'M-XL')
            ->assertJsonPath('items.0.size_mismatch', 'S')
            ->assertJsonPath('items.0.confidence', 'medium')
            ->assertJsonPath('attention_count', 1);
        $this->assertContains('size_mismatch', (array) $res->json('items.0.flags'));

        // ostrzeżenie jest dla handlowca — list do klienta pisze to co dotąd: wybraną kartę i rozmiar z zapytania
        $body = (string) $res->json('reply_body');
        $this->assertStringContainsString('rozmiar z zapytania: M-XL', $body);
        $this->assertStringContainsString('P-50mX - Szelki bezpieczeństwa - rozmiar S', $body);
        // list HTML: rozmiar stoi tylko w bloku „Państwa zapytanie”, a nasza propozycja go nie powtarza —
        // nie wygląda na potwierdzenie z karty
        $html = (string) $res->json('reply_html');
        $this->assertStringContainsString('M-XL', (string) strstr($html, 'Nasza propozycja', true));
        $this->assertStringNotContainsString('M-XL', (string) strstr($html, 'Nasza propozycja'));
    }

    public function test_card_in_the_inquired_size_is_not_flagged(): void
    {
        $res = $this->compose($this->harnessInquiry('M-XL'), 'p:'.self::HARNESS_MXL);

        $res->assertJsonPath('items.0.size_mismatch', null)
            ->assertJsonPath('items.0.confidence', 'high')
            ->assertJsonPath('attention_count', 0);
        $this->assertNotContains('size_mismatch', (array) $res->json('items.0.flags'));
    }

    public function test_one_size_of_a_requested_range_does_not_cover_the_position(): void
    {
        // klient pyta o S-XL, karta ma jeden rozmiar z tego zakresu — pozycja nie jest pokryta
        $res = $this->compose($this->harnessInquiry('S-XL'), 'p:'.self::HARNESS_S);

        $res->assertJsonPath('items.0.size_mismatch', 'S');
    }

    public function test_same_size_in_another_notation_is_not_a_mismatch(): void
    {
        $this->compose($this->harnessInquiry('s'), 'p:'.self::HARNESS_S)
            ->assertJsonPath('items.0.size_mismatch', null);

        $gloves = [
            ['id' => 41, 'sku' => 'GL-10', 'name' => 'Rękawice nitrylowe SIZE 10,0', 'manufacturer' => 'ANSELL', 'norms' => 'EN ISO 374-1', 'catalog_price_net' => '9.00', 'currency' => 'PLN', 'catalog_pln' => 9.0, 'offer_pln' => 10.0, 'stock' => 3, 'score' => 92],
            ['id' => 42, 'sku' => 'KB-2XL', 'name' => 'Kurtka robocza rozmiar 2XL', 'manufacturer' => 'PORTWEST', 'norms' => '', 'catalog_price_net' => '90.00', 'currency' => 'PLN', 'catalog_pln' => 90.0, 'offer_pln' => 100.0, 'stock' => 3, 'score' => 91],
        ];
        $this->compose($this->harnessInquiry('10', $gloves), 'p:41')
            ->assertJsonPath('items.0.size_mismatch', null);
        $this->compose($this->harnessInquiry('XXL', $gloves), 'p:42')
            ->assertJsonPath('items.0.size_mismatch', null);
        // ta sama karta, inny rozmiar klienta — liczba po liczbie, nie tylko litery
        $this->compose($this->harnessInquiry('9', $gloves), 'p:41')
            ->assertJsonPath('items.0.size_mismatch', '10');
    }

    public function test_nothing_to_compare_gives_no_warning(): void
    {
        // pozycja bez rozmiaru
        $this->compose($this->harnessInquiry(null), 'p:'.self::HARNESS_S)
            ->assertJsonPath('items.0.size_mismatch', null);
        // rozmiar klienta, którego nie czytamy
        $this->compose($this->harnessInquiry('L/52'), 'p:'.self::HARNESS_S)
            ->assertJsonPath('items.0.size_mismatch', null);
        // „Sprawdzimy i wrócimy” — nie ma wybranej karty
        $this->compose($this->harnessInquiry('M-XL'), 'check')
            ->assertJsonPath('items.0.size_mismatch', null);
    }

    /**
     * @param  list<array<string, mixed>>|null  $products  kandydaci pozycji; null = dwa warianty szelek P-50mX
     */
    private function harnessInquiry(?string $size, ?array $products = null): ClientInquiry
    {
        $quote = '6.Szelki bezpieczeństwa Protekt p-50mX rozmiar M-XL AB15021- 1 szt';

        return ClientInquiry::query()->create([
            'user_id' => User::factory()->withRole('handlowiec')->create()->id,
            'tone' => 'handlowy',
            'source_subject' => 'Zapytanie ofertowe',
            'source_body' => $quote,
            'analysis' => [
                'line_items' => [
                    ['id' => 'item_1', 'quote' => $quote, 'qty' => '1', 'unit' => 'szt', 'size' => $size, 'query' => 'szelki bezpieczeństwa Protekt p-50mX'],
                ],
                'matches' => [
                    ['query' => 'szelki bezpieczeństwa Protekt p-50mX', 'products' => $products ?? [
                        ['id' => self::HARNESS_S, 'sku' => 'AB15021S', 'name' => 'P-50mX - Szelki bezpieczeństwa - rozmiar S', 'manufacturer' => 'PROTEKT', 'norms' => 'EN 361', 'catalog_price_net' => '150.00', 'currency' => 'PLN', 'catalog_pln' => 150.0, 'offer_pln' => 170.0, 'stock' => 3, 'score' => 92],
                        ['id' => self::HARNESS_MXL, 'sku' => 'AB15021', 'name' => 'P-50mX - Szelki bezpieczeństwa - rozmiar M-XL', 'manufacturer' => 'PROTEKT', 'norms' => 'EN 361', 'catalog_price_net' => '150.00', 'currency' => 'PLN', 'catalog_pln' => 150.0, 'offer_pln' => 170.0, 'stock' => 3, 'score' => 90],
                    ]],
                ],
                'cards' => [],
            ],
            'answers' => [
                'price' => ['option_id' => 'catalog', 'custom' => '18'],
            ],
        ]);
    }

    private function compose(ClientInquiry $inquiry, string $option): TestResponse
    {
        Sanctum::actingAs($inquiry->user);

        return $this->postJson("/api/inquiries/{$inquiry->id}/compose", [
            'answers' => ['product:item_1' => ['option_id' => $option]],
        ])->assertOk();
    }
}
