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
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Szukanie pozycji zapytania w dwóch rundach (ClientInquiryService::matchInRounds).
 *
 * Fraza modelu dla pozycji jest zapasem jej klucza. Dotąd szła w tej samej fali co klucze —
 * zapytanie #71 (MESKO, 24.09.2026): 10 ocen modelu dla 5 wyrobów, 160 s, a na produkcji
 * żadna z 37 takich fraz (zapytania #41–#71) nie trafiła do pozycji.
 */
final class ClientInquirySearchRoundsTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "Dzień dobry,\nproszę o ofertę:\nRękawice nitrylowe NITRAN rozmiar 9 - 20 par\nOkulary ochronne PRIMO bezbarwne - 5 szt.\nPozdrawiam";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_item_phrase_is_searched_only_when_the_item_key_finds_nothing(): void
    {
        $gloves = $this->product('NIT-9', 'Rękawice nitrylowe NITRAN');
        $goggles = $this->product('OKU-1', 'Okulary ochronne bezbarwne');
        $calls = $this->mockSearch(fn (string $q): array => match (true) {
            str_contains(mb_strtolower($q), 'nitran') => [$this->row($gloves, 92)],
            mb_strtolower($q) === 'okulary ochronne' => [$this->row($goggles, 85)],
            default => [],
        });

        $inquiry = $this->analyze();

        $items = $inquiry->analysis['line_items'];
        $this->assertCount(2, $items);
        [$glovesKey, $gogglesKey] = array_column($items, 'search_query');
        $this->assertSame([$glovesKey, $gogglesKey], $calls[0], 'pierwsza runda: same klucze pozycji');
        $this->assertSame(['okulary ochronne'], $calls[1], 'druga runda: tylko zapas pozycji, której klucz nic nie dał');
        $this->assertCount(2, $calls);
        // plan zostaje cały — ponowne szukanie (rematch) idzie tą samą drogą
        $this->assertSame([$glovesKey, $gogglesKey, 'rękawice nitrylowe', 'okulary ochronne'], $inquiry->analysis['product_queries']);

        Sanctum::actingAs($inquiry->user);
        $res = $this->getJson('/api/inquiries/'.$inquiry->id)->assertOk();
        $res->assertJsonPath('items.0.candidates.0.sku', 'NIT-9')
            ->assertJsonPath('items.1.candidates.0.sku', 'OKU-1');
    }

    public function test_no_second_round_after_the_model_failed_on_the_item_key(): void
    {
        $gloves = $this->product('NIT-9', 'Rękawice nitrylowe NITRAN');
        $calls = $this->mockSearch(
            fn (string $q): array => str_contains(mb_strtolower($q), 'nitran') ? [$this->row($gloves, 92)] : [],
            static fn (string $q): ?string => str_contains(mb_strtolower($q), 'primo') ? 'unavailable' : null,
        );

        $inquiry = $this->analyze();

        $this->assertCount(1, $calls, 'model przed chwilą nie odpowiedział — nie czekamy na niego drugi raz');
        $groups = collect($inquiry->analysis['matches'])->keyBy('query');
        $this->assertTrue($groups[$inquiry->analysis['line_items'][1]['search_query']]['model_failed']);
    }

    public function test_no_second_round_when_every_item_key_finds_cards(): void
    {
        $gloves = $this->product('NIT-9', 'Rękawice nitrylowe NITRAN');
        $calls = $this->mockSearch(fn (string $q): array => [$this->row($gloves, 70)]);

        $inquiry = $this->analyze();

        $this->assertCount(1, $calls);
        $this->assertSame(array_column($inquiry->analysis['line_items'], 'search_query'), $calls[0]);
    }

    public function test_phrase_that_is_the_only_search_of_an_old_item_goes_first(): void
    {
        $gloves = $this->product('NIT-9', 'Rękawice nitrylowe NITRAN');
        $user = User::factory()->withRole('handlowiec')->create();
        // stary rekord: klucza pozycji nie ma w planie, fraza modelu jest jej jedynym szukaniem
        $inquiry = ClientInquiry::query()->create([
            'user_id' => $user->id,
            'tone' => 'handlowy',
            'source_channel' => 'web',
            'source_body' => 'Rękawice nitrylowe rozmiar 9',
            'analysis' => [
                'subject' => 'Rękawice',
                'questions' => [],
                'product_queries' => ['rękawice nitrylowe'],
                'line_items' => [
                    ['id' => 'item_1', 'quote' => 'Rękawice nitrylowe rozmiar 9', 'qty' => null, 'unit' => null, 'query' => 'rękawice nitrylowe', 'size' => '9', 'query_source' => 'mail', 'search_query' => 'Rękawice nitrylowe rozmiar 9'],
                ],
                'matches' => [['query' => 'rękawice nitrylowe', 'products' => []]],
                'substitutes' => [],
                'cards' => [],
            ],
            'answers' => ['product:item_1' => ['option_id' => 'check']],
        ]);
        $calls = $this->mockSearch(fn (string $q): array => [$this->row($gloves, 90)]);

        $report = app(ClientInquiryService::class)->rematch($inquiry, true);

        $this->assertNull($report['skipped']);
        $this->assertSame([['rękawice nitrylowe']], $calls->getArrayCopy());
        $this->assertSame('NIT-9', $report['items'][0]['after']['sku']);
    }

    public function test_analysis_logs_where_the_time_went(): void
    {
        Log::spy();
        $gloves = $this->product('NIT-9', 'Rękawice nitrylowe NITRAN');
        $this->mockSearch(fn (string $q): array => str_contains(mb_strtolower($q), 'nitran') ? [$this->row($gloves, 92)] : []);

        $inquiry = $this->analyze();

        Log::shouldHaveReceived('info')->with('client-inquiry.timings', Mockery::on(function (array $context) use ($inquiry): bool {
            $this->assertSame($inquiry->id, $context['inquiry_id']);
            $this->assertSame(2, $context['line_items']);
            $this->assertSame(['extract', 'search', 'other', 'total'], array_keys($context['timings_ms']));
            $this->assertSame([2, 1], array_column($context['search_rounds'], 'queries'));
            // czasy etapów wyszukiwarki przechodzą z wiersza fali
            $this->assertSame(['rank_llm' => 7], $context['search_rounds'][0]['stages_ms']);

            return true;
        }))->once();
    }

    private function analyze(): ClientInquiry
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock): void {
            $mock->shouldReceive('chatJson')->once()->andReturn([
                'subject' => 'Rękawice i okulary',
                'questions' => [],
                'product_queries' => ['rękawice nitrylowe', 'okulary ochronne'],
                'line_items' => [
                    ['id' => 'item_1', 'quote' => 'Rękawice nitrylowe NITRAN rozmiar 9 - 20 par', 'qty' => '20', 'unit' => 'par', 'query' => 'rękawice nitrylowe', 'size' => '9'],
                    ['id' => 'item_2', 'quote' => 'Okulary ochronne PRIMO bezbarwne - 5 szt.', 'qty' => '5', 'unit' => 'szt.', 'query' => 'okulary ochronne', 'size' => null],
                ],
                'cards' => [],
            ]);
        });
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $id = $this->postJson('/api/inquiries', ['body' => self::BODY, 'tone' => 'handlowy'])
            ->assertCreated()
            ->json('id');

        return ClientInquiry::query()->findOrFail($id);
    }

    /**
     * Atrapa wyszukiwarki, która zapisuje frazy każdego wywołania.
     *
     * @param  callable(string): list<array<string, mixed>>  $products
     * @param  (callable(string): ?string)|null  $modelState
     * @return \ArrayObject<int, list<string>>
     */
    private function mockSearch(callable $products, ?callable $modelState = null): \ArrayObject
    {
        $calls = new \ArrayObject;
        $this->mock(ProductInquirySearch::class, function ($mock) use ($calls, $products, $modelState): void {
            $mock->shouldReceive('findMany')->andReturnUsing(function (array $queries) use ($calls, $products, $modelState): array {
                $calls[] = $queries;

                return array_map(static fn (string $q): array => [
                    'query' => $q,
                    'products' => $products($q),
                    'model_state' => $modelState === null ? null : $modelState($q),
                    'timings_ms' => ['rank_llm' => 7],
                ], $queries);
            });
        });

        return $calls;
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
}
