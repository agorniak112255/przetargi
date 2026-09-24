<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zapytanie #67 (24.09.2026): „Rękawice drelichowe pięciopalcowe EN374, EN420” — drelich nie spełnia EN 374,
 * więc żadna karta nie może pasować, a handlowiec widział tylko niskie oceny. Model wskazuje sprzeczność przy
 * rozbiorze maila; pozycja pokazuje ją jako wniosek do wyjaśnienia z klientem.
 */
final class ClientInquiryConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_contradictory_row_is_shown_to_the_salesperson(): void
    {
        $res = $this->analyzeRow(
            'Rękawice drelichowe pięciopalcowe EN374, EN420 - 20 par',
            'Drelich (bawełna) nie spełnia EN 374 — tkanina przepuszcza chemikalia.',
        );

        $res->assertJsonPath('items.0.conflict', 'Drelich (bawełna) nie spełnia EN 374 — tkanina przepuszcza chemikalia.');
        $this->assertContains('requirement_conflict', (array) $res->json('items.0.flags'));
        $this->assertGreaterThanOrEqual(1, (int) $res->json('attention_count'));
    }

    public function test_row_without_contradiction_has_no_conflict(): void
    {
        $res = $this->analyzeRow('Rękawice nitrylowe EN374 - 20 par', null);

        $res->assertJsonPath('items.0.conflict', null);
        $this->assertNotContains('requirement_conflict', (array) $res->json('items.0.flags'));
    }

    /** Parser znalazł więcej wierszy niż model i wygrał — uwaga modelu trafia do swojego wiersza, nie do sąsiada. */
    public function test_conflict_follows_its_row_when_the_parser_wins(): void
    {
        $row = 'Rękawice drelichowe pięciopalcowe EN374, EN420 20 par';
        $res = $this->analyzeRow(
            $row,
            'Drelich nie spełnia EN 374.',
            "Dzień dobry,\nproszę o ofertę:\n1. Kalosze gumowe S5 rozmiar 43 4 pary\n2. ".$row."\n3. Okulary ochronne bezbarwne 10 szt.\nPozdrawiam",
        );

        $items = (array) $res->json('items');
        $this->assertCount(3, $items, 'wygrał parser wierszy');
        foreach ($items as $item) {
            $expected = str_contains((string) $item['quote'], 'drelichowe') ? 'Drelich nie spełnia EN 374.' : null;
            $this->assertSame($expected, $item['conflict'] ?? null, 'uwaga tylko przy swoim wierszu: '.$item['quote']);
        }
    }

    private function analyzeRow(string $row, ?string $conflict, ?string $body = null): TestResponse
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use ($row, $conflict): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => ['rękawice'],
                'line_items' => [[
                    'id' => 'item_1',
                    'quote' => $row,
                    'qty' => '20',
                    'unit' => 'par',
                    'query' => 'rękawice',
                    'size' => null,
                    'conflict' => $conflict,
                ]],
                'cards' => [],
            ]);
        });
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->once()->andReturnUsing(
                static fn (array $queries): array => array_map(
                    static fn (string $q): array => ['query' => $q, 'products' => [], 'model_state' => 'empty'],
                    $queries
                )
            );
        });
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        return $this->postJson('/api/inquiries', [
            'body' => $body ?? "Dzień dobry,\nproszę o ofertę:\n".$row."\nPozdrawiam",
            'tone' => 'handlowy',
        ])->assertCreated();
    }
}
