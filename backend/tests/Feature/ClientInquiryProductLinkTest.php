<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientInquiry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\InquiryProductLinks;
use App\Services\ProductInquirySearch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Link do strony wyrobu w zapytaniu wskazuje kartę, przy której łącznik B2B zapisał ten adres.
 *
 * Materiał z produkcji: zapytanie #69 (24.09.2026) — klient podał link do ROLEX 5 (WRAH220),
 * a propozycją został ROLEX 1, bo słowa z adresu pasowały do każdego ROLEX-a po 99%.
 * Zapytanie #62 — link MAVIBO z inną kombinacją (kolor, rozmiar) niż adres zapisany przy karcie.
 */
final class ClientInquiryProductLinkTest extends TestCase
{
    use RefreshDatabase;

    private const ROLEX5_URL = 'https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356';

    /** @var list<string> */
    private array $searched = [];

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
            'manufacturer' => 'PROTEKT',
            'catalog_price_net' => 451.00,
            'purchase_price' => 315.70,
            'stock' => 0,
        ], $extra));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product, int $percent): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'manufacturer' => $product->manufacturer,
            'catalog_price_net' => (string) $product->catalog_price_net,
            'currency' => 'PLN',
            'stock' => 0,
            'ai_match_percent' => $percent,
            'ai_match_reason' => 'Marka i model z SIWZ (literówka w nazwie modelu jest dopuszczalna).',
        ];
    }

    /**
     * Wyszukiwarka zwraca te same wiersze na każdą frazę, której dotyczy klucz (fragment frazy).
     *
     * @param  array<string, list<array<string, mixed>>>  $byFragment
     */
    private function mockSearch(array $byFragment): void
    {
        $this->mock(ProductInquirySearch::class, function ($mock) use ($byFragment): void {
            $mock->shouldReceive('findMany')->andReturnUsing(function (array $queries) use ($byFragment): array {
                $out = [];
                foreach ($queries as $q) {
                    $this->searched[] = $q;
                    $products = [];
                    foreach ($byFragment as $fragment => $rows) {
                        if (str_contains(mb_strtolower($q), mb_strtolower($fragment))) {
                            $products = $rows;
                            break;
                        }
                    }
                    $out[] = ['query' => $q, 'products' => $products];
                }

                return $out;
            });
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     */
    private function mockExtractor(array $lineItems): void
    {
        $this->mock(OpenAiCompatibleClient::class, function ($mock) use ($lineItems): void {
            $mock->shouldReceive('chatJson')->andReturn([
                'subject' => 'Oferta',
                'questions' => [],
                'product_queries' => array_map(static fn (array $i): string => (string) $i['query'], $lineItems),
                'line_items' => $lineItems,
                'cards' => [],
            ]);
        });
    }

    public function test_link_to_the_card_page_puts_that_card_first_and_chooses_it(): void
    {
        $rolex1 = $this->product('AH210', 'ROLEX 1 - Urządzenie samohamowne do pracy w pionie - kolor czarny', [
            'shop_source_url' => 'https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p1700~c5356',
        ]);
        $rolex2 = $this->product('AH220', 'ROLEX 2 - Urządzenie samohamowne do pracy w pionie - kolor czarny', [
            'shop_source_url' => 'https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p1705~c5356',
        ]);
        $rolex5 = $this->product('WRAH220', 'ROLEX 5 - Urządzenie samohamowne do pracy w pionie - kolor czarny', [
            'shop_source_url' => self::ROLEX5_URL,
        ]);
        $linostop = $this->product('AC06115', 'LINOSTOP II - Urządzenie samozaciskowe z zatrzaśnikiem AZ002 dł. liny 15 m', [
            'catalog_price_net' => 297.36,
        ]);

        $line1 = 'proszę mi wycenić urządzenia samohamowne jak w złączniku lub w linku '.self::ROLEX5_URL;
        $line2 = 'podać cenę na urządzenie 3 sztuk urządzeń Linostop (linka długości min. 15 m)';
        $this->mockExtractor([
            ['id' => 'item_1', 'quote' => $line1, 'qty' => null, 'unit' => null, 'query' => 'urządzenia samohamowne '.self::ROLEX5_URL, 'size' => null],
            ['id' => 'item_2', 'quote' => $line2, 'qty' => '3', 'unit' => 'szt.', 'query' => 'urządzenie Linostop linka min. 15 m', 'size' => null],
        ]);
        $this->mockSearch([
            'samohamowne' => [$this->row($rolex1, 99), $this->row($rolex2, 99), $this->row($rolex5, 99)],
            'linostop' => [$this->row($linostop, 95)],
        ]);

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry,\n\n".$line1."\n\n".$line2."\n",
            'tone' => 'formal',
        ])->assertCreated();

        $res->assertJsonPath('items.0.candidates.0.sku', 'WRAH220')
            ->assertJsonPath('items.0.candidates.0.source', 'link')
            ->assertJsonPath('items.0.confidence', 'high')
            ->assertJsonPath('items.0.chosen', 'p:'.$rolex5->id);
        $this->assertNotContains('ambiguous', $res->json('items.0.flags'));
        $this->assertStringContainsString('protekt.pl', (string) $res->json('items.0.candidates.0.reason'));
        // karta z linku nie powtarza się z wyszukiwarki, a ROLEX-y 1 i 2 zostają alternatywami
        $skus = array_column($res->json('items.0.candidates'), 'sku');
        $this->assertSame(1, array_count_values($skus)['WRAH220']);
        $this->assertContains('AH210', $skus);
        $this->assertStringContainsString('Produkt: ROLEX 5', (string) $res->json('reply_body'));

        // pozycja bez linku idzie po staremu
        $res->assertJsonPath('items.1.candidates.0.sku', 'AC06115');
        $this->assertNotContains('link', array_column($res->json('items.1.candidates'), 'source'));

        // adres nie idzie do wyszukiwarki; pozycja z linku szuka nazwą wskazanej karty
        foreach ($this->searched as $query) {
            $this->assertStringNotContainsString('http', $query);
            $this->assertStringNotContainsString('p8511', $query);
        }
        $inquiry = ClientInquiry::query()->findOrFail((int) $res->json('id'));
        $item = $inquiry->analysis['line_items'][0];
        $this->assertStringStartsWith('ROLEX 5', $item['search_query']);
        $this->assertSame('link', $item['query_source']);
        // ślad: jaki adres, skąd przy pozycji i jak zgodny
        $this->assertSame([[
            'url' => self::ROLEX5_URL,
            'origin' => 'quote',
            'match' => 'exact',
            'product_ids' => [$rolex5->id],
        ]], $item['links']);
        // cytat klienta zostaje z adresem — to dane źródłowe
        $this->assertSame($line1, $item['quote']);
        $this->assertArrayNotHasKey('links', $inquiry->analysis['line_items'][1]);
    }

    public function test_unconfirmed_condition_never_swaps_the_linked_card_for_another_product(): void
    {
        $url = 'https://sklep-bhp.pl/rekawice/12-rekawice-chemiczne-x.html';
        $linkedCard = $this->product('X-1', 'Rękawice chemiczne X', [
            'description' => 'Rękawice nitrylowe, EN 374.',
            'shop_source_url' => $url,
        ]);
        $other = $this->product('Y-1', 'Rękawice chemiczne Y', [
            'description' => 'Rękawice nitrylowe odporne na aceton, EN 374.',
        ]);

        $quote = 'Rękawice chemiczne jak w linku '.$url.' odporne na aceton - 10 par';
        $this->mockExtractor([
            ['id' => 'item_1', 'quote' => $quote, 'qty' => '10', 'unit' => 'par', 'query' => 'rękawice chemiczne odporne na aceton', 'size' => null],
        ]);
        $this->mockSearch(['rękawice' => [$this->row($other, 97)]]);

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $res = $this->postJson('/api/inquiries', ['body' => $quote, 'tone' => 'formal'])->assertCreated();

        $res->assertJsonPath('items.0.candidates.0.id', $linkedCard->id);
        // karta z linku nie potwierdza warunku — „sprawdzimy”, a nie inny wyrób wbrew linkowi
        $res->assertJsonPath('items.0.chosen', 'check');
    }

    public function test_link_on_its_own_line_goes_to_the_only_item_and_another_combination_needs_a_check(): void
    {
        // jak na produkcji: kolory jednego wyrobu pod wspólnym adresem, więcej niż limit pięciu kart
        $cards = [];
        foreach (['20', '20/70', '22', '22/70', '24', '26/70', '26'] as $colour) {
            $cards[$colour] = $this->product('61920_'.$colour, 'GEFFER 620 61920, kolor '.$colour, [
                'manufacturer' => 'GEFFER',
                'shop_source_url' => 'https://mavibo.pl/bluzy/138-2740-geffer-620-61920.html',
            ]);
        }
        // inny wyrób w tym samym sklepie — nie może się podpiąć
        $this->product('61921_20', 'GEFFER 621 61921, kolor 20', [
            'manufacturer' => 'GEFFER',
            'shop_source_url' => 'https://mavibo.pl/bluzy/139-2750-geffer-621-61921.html',
        ]);

        $this->mockExtractor([
            ['id' => 'item_1', 'quote' => 'Zamów proszę 1 szt - kolor czarny - rozmiar L', 'qty' => '1', 'unit' => 'szt', 'query' => 'kolor czarny', 'size' => 'L'],
        ]);
        $this->mockSearch([]);

        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $res = $this->postJson('/api/inquiries', [
            'body' => "Dzień dobry\n\nhttps://mavibo.pl/bluzy/138-2702-geffer-620-61920.html#/3-rozmiar-l/34-kolor-26\n"
                ."Zamów proszę 1 szt - kolor czarny - rozmiar L\n",
            'tone' => 'formal',
        ])->assertCreated();

        // kolor z kotwicy linku („34-kolor-26”) na czele, potem pozostałe po kolei — do limitu pięciu
        $this->assertSame(
            [$cards['26']->id, $cards['20']->id, $cards['20/70']->id, $cards['22']->id, $cards['22/70']->id],
            array_column($res->json('items.0.candidates'), 'id'),
        );
        $res->assertJsonPath('items.0.chosen', 'p:'.$cards['26']->id);
        // inna kombinacja adresu i kilka kart — wybór zostaje do sprawdzenia
        $res->assertJsonPath('items.0.confidence', 'medium');
        $this->assertContains('ambiguous', $res->json('items.0.flags'));
        $reason = (string) $res->json('items.0.candidates.0.reason');
        $this->assertStringContainsString('innym wariancie', $reason);
        $this->assertStringContainsString('Wariant z linku: kolor 26.', $reason);
        $this->assertStringNotContainsString('Wariant z linku', (string) $res->json('items.0.candidates.1.reason'));

        $item = ClientInquiry::query()->findOrFail((int) $res->json('id'))->analysis['line_items'][0];
        $this->assertSame('mail', $item['links'][0]['origin']);
        $this->assertSame('shop_product', $item['links'][0]['match']);
        $this->assertStringStartsWith('GEFFER 620 61920', $item['search_query']);
    }

    public function test_footer_link_does_not_touch_the_item_and_a_link_between_items_belongs_to_none(): void
    {
        $this->product('WRAH220', 'ROLEX 5 - Urządzenie samohamowne do pracy w pionie - kolor czarny', [
            'shop_source_url' => self::ROLEX5_URL,
        ]);
        $links = app(InquiryProductLinks::class);

        // jedna pozycja, adres ze stopki nie prowadzi do karty — fraza bez zmian
        $one = [['id' => 'item_1', 'quote' => '1. Rękawice nitrylowe 100 szt.', 'query' => 'Rękawice nitrylowe', 'search_query' => 'Rękawice nitrylowe']];
        $result = $links->attach($one, "1. Rękawice nitrylowe 100 szt.\n\nPozdrawiam\nhttps://www.facebook.com/firma");
        $this->assertSame($one, $result['items']);
        $this->assertSame([], $result['products']);

        // dwie pozycje i link w osobnym wierszu — nie wiadomo, której dotyczy
        $two = [
            ['id' => 'item_1', 'quote' => '1. Rękawice nitrylowe 100 szt.', 'query' => 'Rękawice nitrylowe', 'search_query' => 'Rękawice nitrylowe'],
            ['id' => 'item_2', 'quote' => '2. Urządzenie samohamowne 2 szt.', 'query' => 'Urządzenie samohamowne', 'search_query' => 'Urządzenie samohamowne'],
        ];
        $result = $links->attach($two, "1. Rękawice nitrylowe 100 szt.\n".self::ROLEX5_URL."\n2. Urządzenie samohamowne 2 szt.");
        $this->assertSame($two, $result['items']);
        $this->assertSame([], $result['products']);
    }

    public function test_link_is_found_in_the_items_mail_line_and_in_variant_addresses(): void
    {
        $url = 'https://www.supon.rzeszow.pl/rekawice-spawalnicze/3880-rekawice-spawalnicze-trudnopalne-rs-split-kev.html';
        $card = $this->product('RS-SPLIT-KEV', 'Rękawice spawalnicze trudnopalne RS SPLIT KEV', [
            'shop_source_url' => 'https://inny-sklep.pl/rs-split-kev',
        ]);
        ProductVariant::query()->create([
            'product_id' => $card->id,
            'source' => 'test',
            'remote_id' => 'RS-SPLIT-KEV-10',
            'label' => 'rozmiar 10',
            'source_url' => $url,
        ]);

        // Outlook podaje adres za nazwą, a model skraca cytat i adres gubi (#64)
        $body = 'Czy mają Państwo w sprzedaży rękawice Rękawice spawalnicze trudnopalne RS SPLIT KEV - rozmiar 10 - Supon Rzeszów<'
            .$url.'#/71-rozmiar_rekawic-10>  w r. 11 - 4 pary';
        $items = [
            ['id' => 'item_1', 'quote' => 'Rękawice spawalnicze trudnopalne RS SPLIT KEV - rozmiar 10 - Supon Rzeszów ... w r. 11 - 4 pary', 'query' => 'rękawice', 'search_query' => 'Rękawice spawalnicze trudnopalne RS SPLIT KEV Supon Rzeszów'],
            ['id' => 'item_2', 'quote' => 'Buty robocze S3 rozmiar 43 - 2 pary', 'query' => 'buty', 'search_query' => 'Buty robocze S3'],
        ];

        $result = app(InquiryProductLinks::class)->attach($items, $body);

        $this->assertSame([$card->id], array_map(static fn (array $hit): int => (int) $hit['product']->id, $result['products']['item_1']));
        $this->assertSame('line', $result['items'][0]['links'][0]['origin']);
        $this->assertSame('exact', $result['items'][0]['links'][0]['match']);
        $this->assertArrayNotHasKey('item_2', $result['products']);
        $this->assertArrayNotHasKey('links', $result['items'][1]);

        // wariant zdjęty ze strony dostawcy nie wskazuje już karty
        ProductVariant::query()->update(['removed_at' => now()]);
        $again = app(InquiryProductLinks::class)->attach($items, $body);
        $this->assertSame([], $again['products']);
    }

    public function test_encoded_and_plain_polish_letters_in_the_address_are_the_same_page(): void
    {
        $card = $this->product('RK-1', 'Rękawice ochronne X', [
            'shop_source_url' => 'https://sklep-bhp.pl/r%C4%99kawice-ochronne-x.html',
        ]);
        $url = 'https://sklep-bhp.pl/rękawice-ochronne-x.html';

        $result = app(InquiryProductLinks::class)->attach(
            [['id' => 'item_1', 'quote' => '10 par '.$url, 'query' => 'rękawice', 'search_query' => '10 par '.$url]],
            '10 par '.$url,
        );

        $this->assertSame($card->id, $result['products']['item_1'][0]['product']->id);
        $this->assertSame('exact', $result['items'][0]['links'][0]['match']);
    }

    public function test_unknown_link_lends_its_words_only_to_a_phrase_that_lacks_them(): void
    {
        $links = app(InquiryProductLinks::class);
        $allegro = 'https://allegro.pl/oferta/spodnie-do-pasa-z-polipropylenu-sfi-r-2xl-15914130007';

        $result = $links->attach(
            [['id' => 'item_1', 'quote' => 'Proszę o 5 szt. '.$allegro, 'query' => 'Proszę o '.$allegro, 'search_query' => 'Proszę o 5 szt. '.$allegro]],
            'Proszę o 5 szt. '.$allegro,
        );
        $item = $result['items'][0];
        $this->assertStringStartsWith('spodnie do pasa z polipropylenu sfi r 2xl', $item['search_query']);
        $this->assertStringNotContainsString('15914130007', $item['search_query']);
        $this->assertStringNotContainsString('http', $item['query']);
        $this->assertSame('link', $item['query_source']);
        $this->assertNull($item['links'][0]['match']);
        $this->assertSame([], $result['products']);

        // cytat już nazywa ten wyrób — słowa z adresu nic nie wnoszą
        $shop = 'https://www.supon.rzeszow.pl/rekawice-spawalnicze/3880-rekawice-spawalnicze-trudnopalne-rs-split-kev.html';
        $result = $links->attach(
            [['id' => 'item_1', 'quote' => 'Rękawice spawalnicze trudnopalne RS SPLIT KEV '.$shop, 'query' => 'rękawice', 'search_query' => 'Rękawice spawalnicze trudnopalne RS SPLIT KEV '.$shop]],
            'Rękawice spawalnicze trudnopalne RS SPLIT KEV '.$shop,
        );
        $this->assertSame('Rękawice spawalnicze trudnopalne RS SPLIT KEV', $result['items'][0]['search_query']);
        $this->assertArrayNotHasKey('query_source', $result['items'][0]);
    }
}
