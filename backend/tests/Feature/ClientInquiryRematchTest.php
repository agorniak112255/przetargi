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
 * Produkcja 24.09.2026: zapytania przeanalizowane, gdy model nie odpowiadał albo wyszukiwarka
 * miała błąd, zostały z „brak w katalogu”. inquiries:rematch szuka jeszcze raz tymi samymi frazami.
 */
final class ClientInquiryRematchTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'rękawice spawalnicze RS SPLIT KEV';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_apply_finds_product_missed_at_analysis_and_rewrites_letter(): void
    {
        $product = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $inquiry = $this->analyzed([]);
        $this->assertSame('check', $inquiry->answers['product:item_1']['option_id']);
        $this->assertStringNotContainsString('RS-SPLIT-KEV', (string) $inquiry->reply_body);

        $this->searchReturns([$this->row($product, 92)]);
        $this->artisan('inquiries:rematch', ['ids' => [$inquiry->id], '--apply' => true])
            ->expectsOutputToContain('#'.$inquiry->id.' — zapisane')
            ->doesntExpectOutputToContain('Nic nie zapisano')
            ->assertSuccessful();

        $after = $inquiry->fresh();
        $this->assertSame('p:'.$product->id, $after->answers['product:item_1']['option_id']);
        $this->assertStringContainsString('SKU RS-SPLIT-KEV', (string) $after->reply_body);
        $this->assertNotNull($after->analysis['rematched_at'] ?? null);
        $this->assertSame([self::QUERY], $after->analysis['product_queries']);
        $this->assertArrayNotHasKey('model_failed', $after->analysis['matches'][0]);
    }

    public function test_report_shows_before_and_after_for_the_item(): void
    {
        $product = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $inquiry = $this->analyzed([]);

        $this->searchReturns([$this->row($product, 92)]);
        $report = app(ClientInquiryService::class)->rematch($inquiry, true);

        $this->assertNull($report['skipped']);
        $this->assertSame('item_1', $report['items'][0]['id']);
        $this->assertNull($report['items'][0]['before']);
        $this->assertSame(['sku' => 'RS-SPLIT-KEV', 'name' => 'Rękawice spawalnicze RS SPLIT KEV', 'score' => 92, 'link' => false], $report['items'][0]['after']);
        $this->assertSame('check', $report['items'][0]['answer_before']);
        $this->assertSame('p:'.$product->id, $report['items'][0]['answer_after']);
    }

    public function test_dry_run_saves_nothing(): void
    {
        $product = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $inquiry = $this->analyzed([]);
        $snapshot = $this->state($inquiry);

        $this->searchReturns([$this->row($product, 92)]);
        $this->artisan('inquiries:rematch', ['ids' => [$inquiry->id]])
            ->expectsOutputToContain('#'.$inquiry->id.' — podgląd')
            ->expectsOutputToContain('Nic nie zapisano')
            ->assertSuccessful();

        $this->assertSame($snapshot, $this->state($inquiry->fresh()));
        $this->searchReturns([$this->row($product, 92)]);
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), false);
        // podgląd pokazuje to, co zrobiłby zapis
        $this->assertSame('p:'.$product->id, $report['items'][0]['answer_after']);
        $this->assertSame($snapshot, $this->state($inquiry->fresh()));
    }

    public function test_replied_inquiry_is_skipped_and_untouched(): void
    {
        $inquiry = $this->analyzed([]);
        $inquiry->forceFill(['replied_at' => now()])->save();
        $snapshot = $this->state($inquiry->fresh());

        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldNotReceive('findMany');
        });
        $this->artisan('inquiries:rematch', ['ids' => [$inquiry->id], '--apply' => true])
            ->expectsOutputToContain('pominięte — odpowiedź już wysłana')
            ->assertSuccessful();

        $this->assertSame($snapshot, $this->state($inquiry->fresh()));
    }

    public function test_inquiry_waiting_to_be_sent_is_skipped(): void
    {
        $inquiry = $this->analyzed([]);
        $inquiry->forceFill(['send_requested_at' => now()])->save();

        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldNotReceive('findMany');
        });
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), true);

        $this->assertSame('list czeka na wysłanie', $report['skipped']);
        $this->assertSame([], $report['items']);
    }

    /** Ręczna poprawka treści listu (bez tabeli HTML) — zapis złożyłby list od nowa i poprawki by przepadły. */
    public function test_hand_edited_letter_is_skipped_and_untouched(): void
    {
        $inquiry = $this->analyzed([]);
        $inquiry->forceFill(['reply_body' => 'List poprawiony ręcznie przez handlowca.', 'reply_html' => null])->save();
        $snapshot = $this->state($inquiry->fresh());

        $this->mock(ProductInquirySearch::class, function ($mock): void {
            $mock->shouldNotReceive('findMany');
        });
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), true);

        $this->assertStringStartsWith('list poprawiony ręcznie', (string) $report['skipped']);
        $this->assertSame($snapshot, $this->state($inquiry->fresh()));
    }

    public function test_unknown_id_is_reported(): void
    {
        $this->artisan('inquiries:rematch', ['ids' => [999999]])
            ->expectsOutputToContain('#999999: nie ma takiego zapytania')
            ->assertSuccessful();
    }

    public function test_product_chosen_by_salesperson_is_kept(): void
    {
        $best = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $other = $this->product('RS-SPLIT-2', 'Rękawice spawalnicze RS SPLIT 2');
        $inquiry = $this->analyzed([$this->row($best, 90), $this->row($other, 72)]);
        $this->assertSame('p:'.$best->id, $inquiry->answers['product:item_1']['option_id']);
        // handlowiec wybrał drugi wyrób
        $answers = $inquiry->answers;
        $answers['product:item_1'] = ['option_id' => 'p:'.$other->id];
        $inquiry->forceFill(['answers' => $answers])->save();

        $this->searchReturns([$this->row($best, 95), $this->row($other, 75)]);
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), true);

        $this->assertSame('p:'.$other->id, $report['items'][0]['answer_before']);
        $this->assertSame('p:'.$other->id, $report['items'][0]['answer_after']);
        $this->assertSame('p:'.$other->id, $inquiry->fresh()->answers['product:item_1']['option_id']);
        $this->assertStringContainsString('SKU RS-SPLIT-2', (string) $inquiry->fresh()->reply_body);
    }

    public function test_check_on_item_that_had_candidates_is_kept(): void
    {
        $best = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $inquiry = $this->analyzed([$this->row($best, 90)]);
        // handlowiec miał kandydata i świadomie zostawił pozycję do sprawdzenia
        $answers = $inquiry->answers;
        $answers['product:item_1'] = ['option_id' => 'check'];
        $inquiry->forceFill(['answers' => $answers])->save();

        $this->searchReturns([$this->row($best, 95)]);
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), true);

        $this->assertSame('check', $report['items'][0]['answer_after']);
        $this->assertSame('check', $inquiry->fresh()->answers['product:item_1']['option_id']);
    }

    public function test_warns_when_chosen_product_drops_out_of_candidates(): void
    {
        $best = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze RS SPLIT KEV');
        $other = $this->product('RS-SPLIT-2', 'Rękawice spawalnicze RS SPLIT 2');
        $inquiry = $this->analyzed([$this->row($best, 90), $this->row($other, 72)]);
        $answers = $inquiry->answers;
        $answers['product:item_1'] = ['option_id' => 'p:'.$other->id];
        $inquiry->forceFill(['answers' => $answers])->save();

        $this->searchReturns([$this->row($best, 95)]);
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), false);

        $this->assertSame('p:'.$best->id, $report['items'][0]['answer_after']);
        $this->assertCount(1, $report['warnings']);
        $this->assertStringContainsString('p:'.$other->id, $report['warnings'][0]);
    }

    /**
     * Zapytanie #69 zapisane przed rozpoznawaniem linków (b6063f7): adres strony stoi we frazie
     * pozycji i w `product_queries`, `link_candidates` nie ma. Ponowne szukanie tymi frazami
     * dawało dalej ROLEX 1 po 99%, a klient linkiem wskazał ROLEX 5.
     */
    public function test_old_record_with_url_in_query_gets_the_linked_card_first(): void
    {
        $url = 'https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356';
        $rolex1 = $this->product('AH210', 'ROLEX 1 - Urządzenie samohamowne do pracy w pionie - kolor czarny', [
            'shop_source_url' => 'https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p1700~c5356',
        ]);
        $rolex5 = $this->product('WRAH220', 'ROLEX 5 - Urządzenie samohamowne do pracy w pionie - kolor czarny', [
            'shop_source_url' => $url,
        ]);
        $linostop = $this->product('AC06115', 'LINOSTOP II - Urządzenie samozaciskowe dł. liny 15 m');
        $manual = $this->product('RECZNY-1', 'Urządzenie samohamowne dobrane przez handlowca');

        // kształt zapisu z produkcji (#69, odczyt 24.09.2026)
        $line1 = 'proszę mi wycenić urządzenia samohamowne jak w złączniku lub w linku '.$url;
        $line2 = 'podać cenę na urządzenie 3 sztuk urządzeń Linostop (linka długości min. 15 m)';
        $oldQuery2 = 'podać cenę na urządzenie urządzeń Linostop (linka długości min. 15 m)';
        $service = app(ClientInquiryService::class);
        // zapisane wiersze wyników to safeProduct() z oceną modelu
        $rows = fn (array $scored): array => array_map(
            fn (array $pair): array => (array) $service->safeProduct($this->row($pair[0], $pair[1])),
            $scored,
        );
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'handlowy',
            'source_channel' => 'web',
            'source_body' => "Pani Ewelino,\n\n".$line1.' oraz  '.$line2,
            'analysis' => [
                'subject' => 'Urządzenia samohamowne',
                'analyzed_body' => "Pani Ewelino,\n\n".$line1.' oraz  '.$line2,
                'questions' => [],
                'product_queries' => [$line1, $oldQuery2, 'urządzenie samohamowne do pracy w pionie', 'Linostop linka długości min. 15 m'],
                'line_items' => [
                    ['id' => 'item_1', 'quote' => $line1, 'qty' => null, 'unit' => null, 'query' => 'urządzenie samohamowne do pracy w pionie', 'size' => null, 'query_source' => 'mail', 'search_query' => $line1],
                    ['id' => 'item_2', 'quote' => $line2, 'qty' => '3', 'unit' => 'szt.', 'query' => 'Linostop linka długości min. 15 m', 'size' => null, 'query_source' => 'mail', 'search_query' => $oldQuery2],
                ],
                'matches' => [
                    ['query' => $line1, 'products' => $rows([[$rolex1, 99], [$rolex5, 99]])],
                    ['query' => $oldQuery2, 'products' => $rows([[$linostop, 95]])],
                    ['query' => 'urządzenie samohamowne do pracy w pionie', 'products' => $rows([[$rolex1, 49]])],
                    ['query' => 'Linostop linka długości min. 15 m', 'products' => $rows([[$linostop, 95]])],
                ],
                'manual_candidates' => ['item_1' => [$service->safeProduct([
                    'id' => $manual->id, 'sku' => $manual->sku, 'name' => $manual->name, 'ai_match_source' => 'manual',
                ])]],
                'substitutes' => [],
                'cards' => [],
            ],
            'answers' => [
                'product:item_1' => ['option_id' => 'p:'.$rolex1->id],
                'product:item_2' => ['option_id' => 'p:'.$linostop->id],
                'price' => ['option_id' => 'catalog_margin', 'custom' => '18'],
            ],
        ]);

        $searched = [];
        $this->mock(ProductInquirySearch::class, function ($mock) use (&$searched, $rolex1, $rolex5, $linostop): void {
            $mock->shouldReceive('findMany')->twice()->andReturnUsing(function (array $queries) use (&$searched, $rolex1, $rolex5, $linostop): array {
                $searched = [...$searched, ...$queries];

                return array_map(fn (string $q): array => ['query' => $q, 'products' => str_contains(mb_strtolower($q), 'linostop')
                    ? [$this->row($linostop, 95)]
                    : [$this->row($rolex1, 99), $this->row($rolex5, 99)]], $queries);
            });
        });
        // podgląd: karta z linku bez udawanego wyniku
        $this->artisan('inquiries:rematch', ['ids' => [$inquiry->id]])
            ->expectsOutputToContain('(z linku)')
            ->expectsOutputToContain('link z zapytania wskazuje WRAH220')
            ->assertSuccessful();
        $searched = [];
        // usługa po podmianie wyszukiwarki — ta sprzed atrapy trzyma prawdziwą
        $report = app(ClientInquiryService::class)->rematch($inquiry->fresh(), true);

        $this->assertNull($report['skipped']);
        $this->assertSame('AH210', $report['items'][0]['before']['sku']);
        $this->assertSame(['sku' => 'WRAH220', 'name' => $rolex5->name, 'score' => 0, 'link' => true], $report['items'][0]['after']);
        // adres nie idzie do wyszukiwarki — ani fraza pozycji, ani fraza modelu
        $this->assertNotSame([], $searched);
        foreach ($searched as $query) {
            $this->assertStringNotContainsString('http', $query);
            $this->assertStringNotContainsString('p8511', $query);
        }

        $after = $inquiry->fresh();
        // W planie fraz zostają zapasy pozycji — bez adresu — ale szukamy ich dopiero wtedy,
        // gdy klucz pozycji nic nie znajdzie (matchInRounds). Tu oba klucze znalazły karty.
        $this->assertSame([
            $after->analysis['line_items'][0]['search_query'],
            $oldQuery2,
        ], $searched);
        $this->assertSame([
            ...$searched,
            'urządzenie samohamowne do pracy w pionie',
            'Linostop linka długości min. 15 m',
        ], $after->analysis['product_queries']);
        $this->assertSame([$rolex5->id], array_column($after->analysis['link_candidates']['item_1'], 'id'));
        $item = $after->analysis['line_items'][0];
        $this->assertStringStartsWith('ROLEX 5', $item['search_query']);
        $this->assertSame('link', $item['query_source']);
        $this->assertSame([['url' => $url, 'origin' => 'quote', 'match' => 'exact', 'product_ids' => [$rolex5->id]]], $item['links']);
        // cytat klienta zostaje z adresem — to dane źródłowe; pozycja bez linku bez zmian
        $this->assertSame($line1, $item['quote']);
        $this->assertSame($inquiry->analysis['line_items'][1], $after->analysis['line_items'][1]);
        $this->assertSame($inquiry->analysis['manual_candidates'], $after->analysis['manual_candidates']);

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/inquiries/'.$inquiry->id)->assertOk();
        $res->assertJsonPath('items.0.candidates.0.sku', 'WRAH220')
            ->assertJsonPath('items.0.candidates.0.source', 'link')
            ->assertJsonPath('items.1.candidates.0.sku', 'AC06115');
        $skus = array_column($res->json('items.0.candidates'), 'sku');
        // karta z linku raz, alternatywa z wyszukiwarki i wyrób dobrany ręcznie zostają
        $this->assertSame(1, array_count_values($skus)['WRAH220']);
        $this->assertContains('AH210', $skus);
        $this->assertContains('RECZNY-1', $skus);
        // wybór zapisany przed ponownym szukaniem zostaje — jak każda decyzja w rematchPlan() —
        // a raport mówi, że link wskazuje inną kartę
        $this->assertSame('p:'.$rolex1->id, $after->answers['product:item_1']['option_id']);
        $this->assertSame('p:'.$linostop->id, $after->answers['product:item_2']['option_id']);
        $this->assertSame('p:'.$rolex1->id, $report['items'][0]['answer_after']);
        $this->assertSame(
            ["item_1: link z zapytania wskazuje WRAH220, a zapisany wybór p:{$rolex1->id} (AH210) zostaje — zmień go przy pozycji."],
            $report['warnings'],
        );
    }

    /**
     * Fraza pozycji równa frazie modelu (model podaje te same) — jak w analyze() zostaje
     * bez adresu jako zapas pozycji, a do wyszukiwarki idzie też fraza z nazwą karty z linku.
     */
    public function test_old_item_phrase_that_is_also_a_model_phrase_stays_without_the_url(): void
    {
        $url = 'https://sklep-bhp.pl/szelki/41-szelki-bezpieczenstwa-p30.html';
        $card = $this->product('P-30', 'Szelki bezpieczeństwa P-30 z pasem biodrowym', ['shop_source_url' => $url]);
        $phrase = 'szelki bezpieczeństwa z pasem biodrowym jak w linku '.$url;
        $user = User::factory()->withRole('handlowiec')->create();
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'handlowy',
            'source_channel' => 'web',
            'source_body' => 'Szelki: '.$url,
            'analysis' => [
                'subject' => 'Szelki',
                'questions' => [],
                'product_queries' => [$phrase],
                'line_items' => [
                    ['id' => 'item_1', 'quote' => 'Szelki: '.$url, 'qty' => null, 'unit' => null, 'query' => $phrase, 'size' => null, 'query_source' => 'mail', 'search_query' => $phrase],
                ],
                'matches' => [['query' => $phrase, 'products' => []]],
                'substitutes' => [],
                'cards' => [],
            ],
            'answers' => ['product:item_1' => ['option_id' => 'check']],
        ]);

        $searched = [];
        $this->mock(ProductInquirySearch::class, function ($mock) use (&$searched): void {
            // klucz z nazwą karty nic nie znalazł, więc druga runda szuka jeszcze frazy pozycji
            $mock->shouldReceive('findMany')->twice()->andReturnUsing(function (array $queries) use (&$searched): array {
                $searched = [...$searched, ...$queries];

                return array_map(static fn (string $q): array => ['query' => $q, 'products' => []], $queries);
            });
        });
        $report = app(ClientInquiryService::class)->rematch($inquiry, true);

        $this->assertNull($report['skipped']);
        $this->assertSame([
            'Szelki bezpieczeństwa P-30 z pasem biodrowym szelki bezpieczeństwa z pasem biodrowym jak w linku',
            'szelki bezpieczeństwa z pasem biodrowym jak w linku',
        ], $searched);
        $after = $inquiry->fresh();
        $this->assertSame($searched, $after->analysis['product_queries']);
        $this->assertSame('szelki bezpieczeństwa z pasem biodrowym jak w linku', $after->analysis['line_items'][0]['query']);
        // pusta lista przed ponownym szukaniem: „do sprawdzenia” nie było wyborem — wchodzi karta z linku
        $this->assertSame('p:'.$card->id, $report['items'][0]['answer_after']);
        $this->assertSame('p:'.$card->id, $after->answers['product:item_1']['option_id']);
        $this->assertSame([], $report['warnings']);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function analyzed(array $products): ClientInquiry
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice spawalnicze',
                'questions' => [],
                'product_queries' => [self::QUERY],
                'cards' => [],
            ]);
        });
        // pusta lista po awarii modelu — tak wyglądały zapytania #64, #65, #67
        $this->searchReturns($products, $products === [] ? 'unavailable' : null);
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $id = (int) $this->postJson('/api/inquiries', [
            'body' => 'Dzień dobry, proszę o ofertę na rękawice spawalnicze RS SPLIT KEV.',
            'tone' => 'handlowy',
        ])->assertCreated()->json('id');

        return ClientInquiry::query()->findOrFail($id);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function searchReturns(array $products, ?string $modelState = null): void
    {
        $this->mock(ProductInquirySearch::class, function ($mock) use ($products, $modelState): void {
            $group = ['query' => self::QUERY, 'products' => $products];
            if ($modelState !== null) {
                $group['model_state'] = $modelState;
            }
            $mock->shouldReceive('findMany')->once()->andReturn([$group]);
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function product(string $sku, string $name, array $extra = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Supon',
            'catalog_price_net' => 12.50,
            'purchase_price' => 8.00,
            'stock' => 40,
        ], $extra));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product, int $score): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'manufacturer' => $product->manufacturer,
            'catalog_price_net' => '12.50',
            'purchase_price' => '8.00',
            'currency' => 'PLN',
            'stock' => 40,
            'ai_match_percent' => $score,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(ClientInquiry $inquiry): array
    {
        return [
            'analysis' => $inquiry->analysis,
            'answers' => $inquiry->answers,
            'reply_subject' => $inquiry->reply_subject,
            'reply_body' => $inquiry->reply_body,
            'reply_html' => $inquiry->reply_html,
            'updated_at' => (string) $inquiry->updated_at,
        ];
    }
}
