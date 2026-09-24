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
 * Zapytanie MESKO (#71, 24.09.2026), pozycja 2: „… symbol RNITz - 432 pary Rozmiar: 8-108par,9-108par,10-216par.”
 * Pozycja jest sumą, a rozmiary stoją tylko w cytacie. Handlowiec chce w odpowiedzi wycenę każdego rozmiaru
 * i podsumowanie w obrębie pozycji — z jedną ceną karty, bo cen per rozmiar karta nie ma.
 */
final class ClientInquirySizeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTE = 'Rękawice ochronne tkaninowe pięciopalcowe, powlekane nitrylem żółtym, zakończone ściągaczem-symbol RNITz - 432 pary Rozmiar: 8-108par,9-108par,10-216par.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_sum_position_lists_every_size_with_its_value_and_a_total(): void
    {
        $res = $this->compose($this->inquiry(self::QUOTE, '432', 'pary'), 'p:51');

        $res->assertJsonPath('items.0.size_breakdown.rows.0', ['size' => '8', 'qty' => '108', 'unit' => 'par'])
            ->assertJsonPath('items.0.size_breakdown.rows.2', ['size' => '10', 'qty' => '216', 'unit' => 'par'])
            ->assertJsonPath('items.0.size_breakdown.total_qty', '432 pary')
            ->assertJsonPath('items.0.size_breakdown.matches_qty', true)
            ->assertJsonPath('items.0.confidence', 'high')
            ->assertJsonPath('attention_count', 0);
        $this->assertNotContains('size_breakdown_mismatch', (array) $res->json('items.0.flags'));

        $body = (string) $res->json('reply_body');
        $this->assertStringContainsString("Cena: 3,23 zł netto\nWedług rozmiarów z zapytania:\n", $body);
        $this->assertStringContainsString('– rozm. 8: 108 par × 3,23 zł = 348,84 zł netto', $body);
        $this->assertStringContainsString('– rozm. 9: 108 par × 3,23 zł = 348,84 zł netto', $body);
        $this->assertStringContainsString('– rozm. 10: 216 par × 3,23 zł = 697,68 zł netto', $body);
        $this->assertStringContainsString('Razem: 432 pary – 1 395,36 zł netto', $body);

        $html = (string) $res->json('reply_html');
        $this->assertStringContainsString('Wycena według rozmiarów z Państwa zapytania:', $html);
        $this->assertStringContainsString('697,68 zł', $html);
        // wartość pozycji: w nagłówku kafla, w wierszu „Razem” tabelki i w sumie listu
        $this->assertSame(3, substr_count($html, '1 395,36 zł'));
        // linie tekstowe nie wracają w kaflu jako opis, a ilość stoi w wierszu „Razem”
        $this->assertStringNotContainsString('rozm. 8', $html);
        $this->assertStringNotContainsString('Ilość: ', $html);
    }

    public function test_sizes_that_do_not_add_up_are_flagged_and_the_value_comes_from_the_sizes(): void
    {
        $quote = 'Rękawice RNITz - 432 pary Rozmiar: 8-100par,9-100par,10-200par.';
        $res = $this->compose($this->inquiry($quote, '432', 'pary'), 'p:51');

        $res->assertJsonPath('items.0.size_breakdown.matches_qty', false)
            ->assertJsonPath('items.0.size_breakdown.total_qty', '400 par')
            ->assertJsonPath('items.0.confidence', 'medium')
            ->assertJsonPath('attention_count', 1);
        $this->assertContains('size_breakdown_mismatch', (array) $res->json('items.0.flags'));
        $this->assertStringContainsString('Razem: 400 par – 1 292,00 zł netto', (string) $res->json('reply_body'));
        // suma listu podaje ilość, z której policzono kwotę — nie 432 pary klienta
        $html = (string) $res->json('reply_html');
        $this->assertStringContainsString('#bbf7d0">400 par</div>', $html);
        $this->assertStringNotContainsString('#bbf7d0">432 pary</div>', $html);
    }

    public function test_quantity_without_unit_is_compared_by_number(): void
    {
        $res = $this->compose($this->inquiry(self::QUOTE, '432', null), 'p:51');

        $res->assertJsonPath('items.0.size_breakdown.matches_qty', true)
            ->assertJsonPath('items.0.size_breakdown.total_qty', '432 par');
    }

    public function test_letter_without_prices_keeps_the_breakdown_only_in_the_panel(): void
    {
        $res = $this->compose($this->inquiry(self::QUOTE, '432', 'pary', 'none'), 'p:51');

        $res->assertJsonPath('items.0.size_breakdown.total_qty', '432 pary');
        $this->assertStringNotContainsString('Według rozmiarów', (string) $res->json('reply_body'));
        $this->assertStringNotContainsString('Wycena według rozmiarów', (string) $res->json('reply_html'));
    }

    public function test_position_without_our_product_gets_no_size_prices(): void
    {
        $res = $this->compose($this->inquiry(self::QUOTE, '432', 'pary'), 'check');

        $res->assertJsonPath('items.0.size_breakdown.matches_qty', true);
        $this->assertStringNotContainsString('Według rozmiarów', (string) $res->json('reply_body'));
    }

    public function test_no_breakdown_for_a_sized_position_or_a_single_size(): void
    {
        // pozycja jednego rozmiaru z tego samego wiersza — rozbicia już dokonano
        $this->compose($this->inquiry(self::QUOTE, '108', 'par', 'catalog', '8'), 'p:51')
            ->assertJsonPath('items.0.size_breakdown', null);
        // jedna para to rozmiar pozycji, nie rozbicie
        $this->compose($this->inquiry('Rękawice RNITz rozm. 9 - 50 par', '50', 'par'), 'p:51')
            ->assertJsonPath('items.0.size_breakdown', null);
        // pary i kartony w jednym wierszu — nie dodajemy
        $this->compose($this->inquiry('Rękawice RNITz: 8-100par, 9-2 kartony', '100', 'par'), 'p:51')
            ->assertJsonPath('items.0.size_breakdown', null);
    }

    private function inquiry(string $quote, ?string $qty, ?string $unit, string $priceMode = 'catalog', ?string $size = null): ClientInquiry
    {
        return ClientInquiry::query()->create([
            'user_id' => User::factory()->withRole('handlowiec')->create()->id,
            'tone' => 'handlowy',
            'source_subject' => 'Zapytanie ofertowe',
            'source_body' => $quote,
            'analysis' => [
                'line_items' => [
                    ['id' => 'item_1', 'quote' => $quote, 'qty' => $qty, 'unit' => $unit, 'size' => $size, 'query' => 'rękawice RNITz'],
                ],
                'matches' => [
                    ['query' => 'rękawice RNITz', 'products' => [
                        ['id' => 51, 'sku' => 'RNITZ', 'name' => 'Rękawice ochronne NITZ.', 'manufacturer' => 'Reis', 'norms' => '', 'catalog_price_net' => '3.23', 'currency' => 'PLN', 'catalog_pln' => 3.23, 'offer_pln' => 3.8, 'stock' => 3, 'score' => 94],
                    ]],
                ],
                'cards' => [],
            ],
            'answers' => [
                'price' => ['option_id' => $priceMode, 'custom' => '18'],
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
