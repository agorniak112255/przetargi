<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zapytanie #71 (24.09.2026, mail MESKO): wiersz z rozbiciem na rozmiary poszedł do oferty
 * dwa razy (suma i rozmiary — 864 pary zamiast 432), a limit 8 pozycji zjadł bez śladu
 * szelki i amortyzator. Całą drogą przez API: model → pozycje → karty → widok.
 */
final class ClientInquiryLineItemLimitTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "1. RĘKAWICZKI DIAGNOSTYCZNE BEZPUDROWE,(wyposażenie apteczek) -10 OPAKOWAŃ PO 50 PAR = 500par\n"
        ."2. Rękawice ochronne tkaninowe pięciopalcowe, powlekane nitrylem żółtym, zakończone ściągaczem-symbol RNITz  - 432 pary\n"
        ."Rozmiar: 8-108par,9-108par,10-216par.\n"
        ."3.Rękawice białe dziane nakrapiane EN ISO 21420- 108 par,rozm.8\n"
        ."4. Rękawiczki nitrylowe \"MedaSept\" EASYGRIP PURPLE - 50 opk ,rozmiar:M\n"
        ."5. Rękawice drelichowe pięciopalcowe EN374,EN420(2)(brak rozmiaru) - 200 par\n"
        ."6.Szelki bezpieczeństwa Protekt p-50mX rozmiar M-XL AB15021- 1 szt\n"
        .'7.Amortyzator bezpieczeństwa z linką 1,5m BW100SCF/LB101/AZ011/023 PROTEKT z zatrzaskiem  - 1 szt';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldReceive('findMany')->andReturnUsing(
                static fn (array $queries): array => array_map(
                    static fn (string $q): array => ['query' => $q, 'products' => []],
                    $queries,
                ),
            );
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @param  list<array<string, mixed>>  $cards
     */
    private function mockModel(array $lineItems, array $cards = []): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use ($lineItems, $cards): void {
            $mock->shouldReceive('chatJson')->andReturn([
                'subject' => 'Zapytanie ofertowe',
                'questions' => [],
                'product_queries' => [],
                'line_items' => $lineItems,
                'cards' => $cards,
            ]);
        });
    }

    public function test_inquiry_71_quotes_432_pairs_and_keeps_every_row(): void
    {
        $rnitz = 'rękawice ochronne tkaninowe powlekane nitrylem żółtym zakończone ściągaczem';
        // odpowiedź modelu z #71: suma, trzy rozmiary, wiersze 3–5 i koniec na ósmej pozycji
        $this->mockModel([
            ['id' => 'item_1', 'quote' => 'RĘKAWICZKI DIAGNOSTYCZNE BEZPUDROWE,(wyposażenie apteczek) -10 OPAKOWAŃ PO 50 PAR = 500par', 'qty' => '10', 'unit' => 'opakowań', 'query' => 'rękawiczki diagnostyczne bezpudrowe', 'size' => null],
            ['id' => 'item_2', 'quote' => 'Rękawice ochronne tkaninowe pięciopalcowe, powlekane nitrylem żółtym, zakończone ściągaczem-symbol RNITz  - 432 pary', 'qty' => '432', 'unit' => 'pary', 'query' => $rnitz, 'size' => null],
            ['id' => 'item_3', 'quote' => 'Rozmiar: 8-108par', 'qty' => '108', 'unit' => 'par', 'query' => $rnitz, 'size' => '8'],
            ['id' => 'item_4', 'quote' => '9-108par', 'qty' => '108', 'unit' => 'par', 'query' => $rnitz, 'size' => '9'],
            ['id' => 'item_5', 'quote' => '10-216par', 'qty' => '216', 'unit' => 'par', 'query' => $rnitz, 'size' => '10'],
            ['id' => 'item_6', 'quote' => 'Rękawice białe dziane nakrapiane EN ISO 21420- 108 par,rozm.8', 'qty' => '108', 'unit' => 'par', 'query' => 'rękawice białe dziane nakrapiane EN ISO 21420', 'size' => '8'],
            ['id' => 'item_7', 'quote' => 'Rękawiczki nitrylowe "MedaSept" EASYGRIP PURPLE - 50 opk ,rozmiar:M', 'qty' => '50', 'unit' => 'opk', 'query' => 'rękawiczki nitrylowe MedaSept EASYGRIP PURPLE', 'size' => 'M'],
            ['id' => 'item_8', 'quote' => 'Rękawice drelichowe pięciopalcowe EN374,EN420(2)(brak rozmiaru) - 200 par', 'qty' => '200', 'unit' => 'par', 'query' => 'rękawice drelichowe pięciopalcowe EN374 EN420', 'size' => null],
        ], [[
            // karta modelu przy pozycji rozmiaru, która znika w sumie
            'id' => 'rnitz_color',
            'title' => 'Kolor powlekania',
            'prompt' => 'Czy żółty nitryl jest konieczny?',
            'options' => [['id' => 'yes', 'label' => 'Tak'], ['id' => 'any', 'label' => 'Dowolny']],
            'allow_custom' => false,
            'item_id' => 'item_4',
        ]]);

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $res = $this->postJson('/api/inquiries', ['body' => self::BODY, 'tone' => 'handlowy'])->assertCreated();

        $items = $res->json('items');
        $this->assertCount(7, $items);
        $pairs = 0;
        foreach ($items as $item) {
            if (str_contains((string) $item['quote'], 'RNITz')) {
                $pairs += (int) $item['qty'];
            }
        }
        $this->assertSame(432, $pairs, 'wiersz 2 ma być w ofercie raz, nie jako suma i rozmiary');
        $this->assertStringContainsString('Szelki bezpieczeństwa', (string) $items[5]['quote']);
        $this->assertStringContainsString('Amortyzator bezpieczeństwa', (string) $items[6]['quote']);
        $this->assertSame(['rnitz_color'], array_column($items[1]['cards'], 'id'), 'karta rozmiaru idzie do pozycji, która została');
        $res->assertJsonPath('omitted_items', []);
        $this->assertStringContainsString('Amortyzator', (string) $res->json('reply_body'));
    }

    public function test_rows_over_the_limit_are_shown_and_need_attention(): void
    {
        $lines = [];
        $fromAi = [];
        foreach (range(1, 10) as $n) {
            $lines[] = $n.'. Rękawice robocze model R'.$n.' - '.($n * 10).' par';
            if ($n <= 8) {
                $fromAi[] = ['id' => 'item_'.$n, 'quote' => 'Rękawice robocze model R'.$n.' - '.($n * 10).' par', 'qty' => (string) ($n * 10), 'unit' => 'par', 'query' => 'rękawice robocze R'.$n, 'size' => null];
            }
        }
        $this->mockModel($fromAi);

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $res = $this->postJson('/api/inquiries', ['body' => implode("\n", $lines), 'tone' => 'handlowy'])->assertCreated();

        $this->assertCount(8, $res->json('items'));
        $res->assertJsonPath('omitted_limit', 8)
            ->assertJsonPath('omitted_items.0.quote', '9. Rękawice robocze model R9 - 90 par')
            ->assertJsonPath('omitted_items.1.qty', '100');
        // pozycje bez kandydatów liczą się do sprawdzenia i tak — pominięte wiersze dochodzą do nich
        $items = collect($res->json('items'));
        $flagged = $items->filter(static fn (array $item): bool => $item['confidence'] !== 'high'
            || array_diff($item['flags'], ['qty_unknown', 'product_from_subject']) !== [])->count();
        $this->assertSame($flagged + 2, (int) $res->json('attention_count'));
        $id = (int) $res->json('id');
        $list = $this->getJson('/api/inquiries')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $id);
        $this->assertSame((int) $res->json('attention_count'), (int) $row['attention_count']);
    }
}
