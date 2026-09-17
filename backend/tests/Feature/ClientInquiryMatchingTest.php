<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ClientInquiryService;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Jakość doboru produktów do zapytania mailowego.
 *
 * Materiał wzięty z produkcji (zapytanie #6/#7 „OFERTA Skalmierzyce”), gdzie
 * pod wycieraczkę 80×120 cm podstawiał się zestaw serwisowy 3M za 27 879,90 zł.
 */
final class ClientInquiryMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function product(string $sku, string $name, array $extra = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Test',
            'catalog_price_net' => 100.00,
            'purchase_price' => 60.00,
            'stock' => 5,
        ], $extra));
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $byQuery
     */
    private function mockSearch(array $byQuery): void
    {
        $this->mock(ProductInquirySearch::class, function ($mock) use ($byQuery): void {
            $mock->shouldReceive('findMany')->andReturnUsing(
                fn (array $queries): array => array_map(
                    fn (string $q): array => ['query' => $q, 'products' => $byQuery[$q] ?? []],
                    $queries
                )
            );
        });
    }

    private function mockExtractor(): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->andReturn([
                'subject' => 'Zapytanie',
                'questions' => [],
                'product_queries' => [],
                'line_items' => [],
                'cards' => [],
            ]);
        });
    }

    public function test_numbered_list_is_not_read_as_quantities(): void
    {
        $service = app(ClientInquiryService::class);

        $mail = implode("\n", [
            '1. Wycieraczka gumowa 40x60cm',
            '2. Łopata do śniegu',
            '3. Drabina elektroizolacyjna KRAUSE 815446',
        ]);

        $items = $service->resolveLineItems($mail, []);

        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertNull($item['qty'], 'numer listy nie jest ilością');
        }
    }

    public function test_real_quantities_with_units_are_kept(): void
    {
        $service = app(ClientInquiryService::class);

        $mail = implode("\n", [
            '10 szt. rękawice nitrylowe rozmiar 9',
            '4 pary buty robocze S3 rozmiar 43',
        ]);

        $items = $service->resolveLineItems($mail, []);

        $this->assertSame('10', $items[0]['qty']);
        $this->assertSame('4', $items[1]['qty']);
    }

    public function test_size_only_line_inherits_the_product_name_and_says_so(): void
    {
        $service = app(ClientInquiryService::class);

        $items = $service->resolveLineItems('', [
            ['id' => 'item_1', 'quote' => 'Wycieraczka gumowa:rozm: 40x60cm, c. netto......24,00 PLN/szt', 'query' => 'wycieraczka gumowa'],
            ['id' => 'item_2', 'quote' => '50x100cm, c. netto...... 39,00 PLN/szt.', 'query' => ''],
            ['id' => 'item_3', 'quote' => '80x120cm c. netto......89,00 PLN/szt.', 'query' => ''],
        ]);

        $this->assertSame('mail', $items[0]['query_source']);
        $this->assertStringContainsString('wycieraczka gumowa', mb_strtolower($items[1]['search_query']));
        $this->assertStringContainsString('50x100cm', $items[1]['search_query']);
        // dziedziczenie to nasz wniosek, nie treść maila — musi być odnotowane
        $this->assertSame('inherited', $items[1]['query_source']);
        $this->assertStringContainsString('wycieraczka gumowa', mb_strtolower($items[2]['search_query']));
    }

    public function test_search_query_never_carries_the_price(): void
    {
        $service = app(ClientInquiryService::class);

        $items = $service->resolveLineItems('', [
            ['id' => 'item_1', 'quote' => '(poz9). Łopata do śniegu,c. netto......97,00 PLN/szt.', 'query' => 'łopata do śniegu'],
        ]);

        $this->assertStringNotContainsString('PLN', $items[0]['search_query']);
        $this->assertStringNotContainsString('netto', $items[0]['search_query']);
        $this->assertStringNotContainsString('poz9', $items[0]['search_query']);
        $this->assertStringContainsString('opata do', $items[0]['search_query']);
    }

    public function test_offer_never_repeats_the_price_from_the_customers_mail(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();

        $this->mockExtractor();
        $this->mockSearch([]);

        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'body' => implode("\n", [
                'Dzień dobry,',
                '',
                '1. Wycieraczka gumowa 40x60cm, c. netto......24,00 PLN/szt',
                '2. Łopata do śniegu, c. netto......97,00 PLN/szt.',
            ]),
            'tone' => 'formal',
        ])->assertCreated();

        $letter = (string) $res->json('reply_body');
        $table = (string) $res->json('reply_html');

        // treść pozycji zostaje, cena z cudzej oferty nie
        $this->assertStringContainsString('Wycieraczka gumowa 40x60cm', $letter);
        $this->assertStringContainsString('Łopata do śniegu', $letter);
        $this->assertStringNotContainsString('24,00', $letter);
        $this->assertStringNotContainsString('PLN/szt', $letter);
        $this->assertStringNotContainsString('97,00', $table);

        // w zapisie zapytania cytat zostaje nietknięty — to dane źródłowe
        $this->assertStringContainsString('24,00 PLN/szt', (string) $res->json('source_body'));
        $this->assertStringContainsString('24,00', (string) $res->json('items.0.quote'));
    }

    public function test_catalog_row_without_model_rating_is_not_offered_to_the_customer(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $junk = $this->product('PF-SK-01', 'Zestaw serwisowy 3M PF-SK-01', ['catalog_price_net' => 27879.90]);

        $this->mockExtractor();
        $this->mockSearch([
            'Wycieraczka gumowa 80x120cm' => [[
                'id' => $junk->id,
                'sku' => $junk->sku,
                'name' => $junk->name,
                'manufacturer' => '3M',
                'catalog_price_net' => '27879.90',
                'currency' => 'PLN',
                'stock' => 1,
                'ai_match_percent' => 48,
                'ai_match_reason' => 'Nieocenione przez model — ten sam rodzaj w katalogu',
                'ai_match_source' => 'catalog',
            ]],
        ]);

        Sanctum::actingAs($user);

        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n6 szt. Wycieraczka gumowa 80x120cm",
            'tone' => 'formal',
        ])->assertCreated();

        $res->assertJsonPath('items.0.candidates', [])
            ->assertJsonPath('items.0.confidence', 'none')
            ->assertJsonPath('items.0.chosen', 'check');
        $this->assertStringNotContainsString('PF-SK-01', (string) $res->json('reply_body'));
    }

    public function test_product_rated_by_the_model_stays_even_with_a_very_different_name(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        // w handlu to ten sam wyrób — model to ocenił i nie wolno tego odsiać
        $waders = $this->product('SB-44', 'Spodniobuty wędkarskie PCV', ['catalog_price_net' => 189.00]);

        $this->mockExtractor();
        $this->mockSearch([
            'Wodery' => [[
                'id' => $waders->id,
                'sku' => $waders->sku,
                'name' => $waders->name,
                'manufacturer' => 'Demar',
                'catalog_price_net' => '189.00',
                'currency' => 'PLN',
                'stock' => 3,
                'ai_match_percent' => 88,
                'ai_match_reason' => 'Ten sam wyrób pod inną nazwą handlową',
                'ai_match_source' => 'ai',
            ]],
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\n2 pary Wodery rozmiar 44",
            'tone' => 'formal',
        ])
            ->assertCreated()
            ->assertJsonPath('items.0.candidates.0.sku', 'SB-44')
            ->assertJsonPath('items.0.confidence', 'high')
            ->assertJsonPath('items.0.chosen', 'p:'.$waders->id);
    }

    public function test_quoted_sku_below_the_threshold_does_not_push_out_a_rated_candidate(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $glasses = $this->product('SF201AF', 'Okulary 3M SecureFit');
        $filter = $this->product('2820', 'Filtr do polmaski');

        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_body' => '20 szt. okulary 3M 2820 bezbarwne',
            'analysis' => [
                'line_items' => [[
                    'id' => 'item_1',
                    'quote' => '20 szt. okulary 3M 2820 bezbarwne',
                    'qty' => '20',
                    'unit' => 'szt.',
                    'query' => 'okulary 3M 2820 bezbarwne',
                ]],
                'matches' => [[
                    'query' => 'okulary 3M 2820 bezbarwne',
                    'products' => [
                        [
                            'id' => $glasses->id,
                            'sku' => $glasses->sku,
                            'name' => $glasses->name,
                            'manufacturer' => '3M',
                            'norms' => '',
                            'score' => 92,
                            'reason' => 'Zgodny rodzaj',
                            'catalog_pln' => 30.0,
                            'offer_pln' => 35.4,
                            'stock' => 4,
                        ],
                        // ten sam ciag znakow co w cytacie, ale model ocenil wiersz nisko
                        [
                            'id' => $filter->id,
                            'sku' => $filter->sku,
                            'name' => $filter->name,
                            'manufacturer' => '3M',
                            'norms' => '',
                            'score' => 30,
                            'reason' => 'Inny rodzaj wyrobu',
                            'catalog_pln' => 12.0,
                            'offer_pln' => 14.16,
                            'stock' => 9,
                        ],
                    ],
                ]],
            ],
            'reply_subject' => 'Oferta',
            'reply_body' => 'Tresc listu.',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertOk()
            ->assertJsonPath('items.0.candidates.0.sku', 'SF201AF')
            ->assertJsonPath('items.0.confidence', 'high')
            ->assertJsonPath('items.0.chosen', 'p:'.$glasses->id);
    }

    public function test_old_record_row_with_only_a_size_keeps_the_only_group(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $mat = $this->product('WYC-50', 'Wycieraczka gumowa');

        // zapis sprzed „search_query”: wiersz z samym wymiarem nie ma wlasnej frazy
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_body' => "2 szt. Wycieraczka gumowa rozm: 40x60cm\n3 szt. rozm: 50x100cm",
            'analysis' => [
                'line_items' => [
                    ['id' => 'item_1', 'quote' => '2 szt. Wycieraczka gumowa rozm: 40x60cm', 'qty' => '2', 'unit' => 'szt.', 'query' => 'Wycieraczka gumowa', 'size' => '40x60cm'],
                    ['id' => 'item_2', 'quote' => '3 szt. rozm: 50x100cm', 'qty' => '3', 'unit' => 'szt.', 'query' => '', 'size' => '50x100cm'],
                ],
                'matches' => [[
                    'query' => 'Wycieraczka gumowa',
                    'products' => [[
                        'id' => $mat->id,
                        'sku' => $mat->sku,
                        'name' => $mat->name,
                        'manufacturer' => 'Supon',
                        'norms' => '',
                        'score' => 88,
                        'reason' => 'Zgodny rodzaj',
                        'catalog_pln' => 39.0,
                        'offer_pln' => 46.02,
                        'stock' => 3,
                    ]],
                ]],
            ],
            'reply_subject' => 'Oferta',
            'reply_body' => 'Tresc listu.',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertOk()
            ->assertJsonPath('items.1.candidates.0.sku', 'WYC-50');
    }

    public function test_missing_quantity_is_visible_but_does_not_inflate_the_attention_count(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $gloves = $this->product('RNITZ-100', 'Rekawice nitrylowe');

        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_body' => '1. Rekawice nitrylowe
2. Buty robocze',
            'analysis' => [
                'line_items' => [[
                    'id' => 'item_1',
                    'quote' => '1. Rekawice nitrylowe',
                    'qty' => null,
                    'qty_source' => 'enumeration',
                    'unit' => null,
                    'query' => 'Rekawice nitrylowe',
                ]],
                'matches' => [[
                    'query' => 'Rekawice nitrylowe',
                    'products' => [[
                        'id' => $gloves->id,
                        'sku' => $gloves->sku,
                        'name' => $gloves->name,
                        'manufacturer' => 'Supon',
                        'norms' => '',
                        'score' => 92,
                        'reason' => 'Zgodny rodzaj',
                        'catalog_pln' => 100.0,
                        'offer_pln' => 118.0,
                        'stock' => 5,
                    ]],
                ]],
            ],
            'answers' => ['price' => ['option_id' => 'none']],
            'reply_subject' => 'Oferta',
            'reply_body' => 'Tresc listu.',
        ]);

        Sanctum::actingAs($user);

        // brak ilosci widac przy pozycji, ale w liscie numerowanym dotyczy kazdego
        // wiersza i licznik „do sprawdzenia” przestalby cokolwiek znaczyc
        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertOk()
            ->assertJsonPath('items.0.flags', ['qty_unknown'])
            ->assertJsonPath('items.0.confidence', 'high')
            ->assertJsonPath('attention_count', 0);
    }

    public function test_item_without_a_query_gets_no_candidates_from_the_only_group(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->product('RNITZ-100', 'Rekawice nitrylowe');

        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_body' => 'Zapytanie.',
            'analysis' => [
                'line_items' => [
                    ['id' => 'item_1', 'quote' => '10 szt. rekawice nitrylowe', 'qty' => '10', 'unit' => 'szt.', 'query' => 'rekawice nitrylowe'],
                    // pozycja bez frazy: nic dla niej nie szukalismy
                    ['id' => 'item_2', 'quote' => 'prosze o wycene', 'qty' => null, 'unit' => null, 'query' => ''],
                ],
                'matches' => [[
                    'query' => 'rekawice nitrylowe',
                    'products' => [[
                        'id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'manufacturer' => 'Supon',
                        'norms' => '',
                        'score' => 91,
                        'reason' => 'Zgodny rodzaj',
                        'catalog_pln' => 100.0,
                        'offer_pln' => 118.0,
                        'stock' => 5,
                    ]],
                ]],
            ],
            'reply_subject' => 'Oferta',
            'reply_body' => 'Tresc listu.',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertOk()
            ->assertJsonPath('items.0.candidates.0.sku', 'RNITZ-100')
            ->assertJsonPath('items.1.candidates', [])
            // bez kandydatow list pisze „sprawdzimy i wrocimy”, a nie cudzy towar
            ->assertJsonPath('items.1.chosen', 'check');
    }

    public function test_old_inquiry_saved_before_the_change_keeps_its_candidates(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        $product = $this->product('RNITZ-100', 'Rękawice nitrylowe');

        // zapis w starym formacie: pozycje bez „search_query”, klucz grupy = cytat
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'formal',
            'source_body' => 'Stare zapytanie.',
            'analysis' => [
                'line_items' => [[
                    'id' => 'item_1',
                    'quote' => '10 szt. rękawice nitrylowe rozmiar 9',
                    'qty' => '10',
                    'unit' => 'szt.',
                    'query' => 'rękawice nitrylowe',
                    'size' => '9',
                ]],
                'matches' => [[
                    'query' => 'rękawice nitrylowe rozmiar 9',
                    'products' => [[
                        'id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'manufacturer' => 'Supon',
                        'norms' => '',
                        'score' => 91,
                        'reason' => 'Zgodny rodzaj',
                        'catalog_pln' => 100.0,
                        'offer_pln' => 118.0,
                        'stock' => 5,
                    ]],
                ]],
            ],
            'answers' => ['product:item_1' => ['option_id' => 'p:'.$product->id]],
            'reply_subject' => 'Oferta',
            'reply_body' => 'Treść listu.',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/inquiries/{$inquiry->id}")
            ->assertOk()
            ->assertJsonPath('items.0.candidates.0.sku', 'RNITZ-100')
            ->assertJsonPath('items.0.chosen', 'p:'.$product->id);
    }
}
