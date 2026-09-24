<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ClientInquiry;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ClientInquiryService;
use App\Services\NbpExchangeRateService;
use App\Services\ProductInquirySearch;
use App\Support\InquiryMailText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class ClientInquiryServiceTest extends TestCase
{
    // present() czyta z katalogu warunek zamawiania kandydatów (order_quantity) — potrzebny schemat bazy
    use RefreshDatabase;

    private function service(): ClientInquiryService
    {
        return new ClientInquiryService(
            Mockery::mock(OpenAiCompatibleClient::class),
            Mockery::mock(ProductInquirySearch::class),
            $this->app->make(NbpExchangeRateService::class),
            $this->app->make(AiSettingsService::class),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function safeProducts(array $products): array
    {
        $svc = $this->service();
        $out = [];
        foreach ($products as $row) {
            $safe = $svc->safeProduct($row);
            if ($safe !== null) {
                $out[] = $safe;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $scores
     * @return list<array<string, mixed>>
     */
    private function candidates(array $scores): array
    {
        $rows = [];
        foreach ($scores as $i => $score) {
            $rows[] = [
                'id' => $i + 1,
                'sku' => 'SKU'.($i + 1),
                'name' => 'Towar '.($i + 1),
                'manufacturer' => 'X',
                'catalog_price_net' => '10',
                'purchase_price' => '5',
                'currency' => 'PLN',
                'stock' => 1,
                'ai_match_percent' => $score,
                'ai_match_reason' => 'Powód '.($i + 1),
            ];
        }

        return $this->safeProducts($rows);
    }

    public function test_safe_product_drops_purchase_price(): void
    {
        $safe = $this->service()->safeProduct([
            'id' => 7,
            'sku' => 'RNITZ-100',
            'name' => 'Rękawice',
            'manufacturer' => 'Supon',
            'purchase_price' => '1.10',
            'catalog_price_net' => '2.40',
            'currency' => 'PLN',
            'stock' => 3,
            'ai_match_percent' => 88,
        ]);

        $this->assertNotNull($safe);
        $this->assertArrayNotHasKey('purchase_price', $safe);
        $this->assertSame('2.40', $safe['catalog_price_net']);
        $this->assertSame('PLN', $safe['currency']);
        $this->assertSame(2.4, $safe['catalog_pln']);
        $this->assertEqualsWithDelta(1.30, (float) $safe['offer_pln'], 0.001);
    }

    public function test_safe_product_converts_eur_catalog_to_pln(): void
    {
        Http::fake([
            'api.nbp.pl/api/exchangerates/tables/A/*' => Http::response([[
                'table' => 'A',
                'no' => '185/A/NBP/2026',
                'effectiveDate' => '2026-09-23',
                'rates' => [
                    ['currency' => 'dolar amerykański', 'code' => 'USD', 'mid' => 3.6412],
                    ['currency' => 'euro', 'code' => 'EUR', 'mid' => 4.2698],
                ],
            ]]),
        ]);

        $safe = $this->service()->safeProduct([
            'id' => 8,
            'sku' => '37900VP',
            'name' => 'AlphaTec',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => '4.67',
            'purchase_price' => '3.50',
            'currency' => 'EUR',
            'stock' => 0,
        ]);

        $this->assertNotNull($safe);
        $this->assertSame('PLN', $safe['currency']);
        $this->assertGreaterThan(15.0, (float) $safe['catalog_pln']);
        $this->assertGreaterThan(12.0, (float) $safe['offer_pln']);
        $this->assertLessThan((float) $safe['catalog_pln'], (float) $safe['offer_pln']);
        // kurs średni NBP 4,2698: katalog 4,67 × kurs, oferta = zakup 3,50 × kurs (14,94) + 18%
        $this->assertSame(19.94, $safe['catalog_pln']);
        $this->assertSame('19.94', $safe['catalog_price_net']);
        $this->assertSame(17.63, $safe['offer_pln']);
        $this->assertStringNotContainsString('EUR', (string) json_encode($safe));
    }

    public function test_safe_product_keeps_reason_and_stock(): void
    {
        $safe = $this->service()->safeProduct([
            'id' => 7,
            'sku' => 'RNITZ-100',
            'name' => 'Rękawice',
            'catalog_price_net' => '2.40',
            'stock' => 3,
            'ai_match_percent' => 88,
            'ai_match_reason' => 'Norma 374-1 na karcie',
        ]);

        $this->assertNotNull($safe);
        $this->assertSame('Norma 374-1 na karcie', $safe['reason']);
        $this->assertSame(3, $safe['stock']);
        $this->assertSame(88, $safe['score']);
    }

    public function test_build_cards_skips_modal_for_one_confident_match(): void
    {
        $cards = $this->service()->buildCards([], [[
            'query' => 'rękawice',
            'products' => [[
                'id' => 1,
                'sku' => 'RNITZ-100',
                'name' => 'Rękawice',
                'manufacturer' => 'Supon',
                'norms' => '',
                'catalog_price_net' => '2.40',
                'currency' => 'PLN',
                'stock' => 10,
                'score' => 91,
            ]],
        ]]);

        $this->assertSame([], $cards);
    }

    public function test_build_cards_asks_when_two_matches(): void
    {
        $cards = $this->service()->buildCards([], [[
            'query' => 'rękawice',
            'products' => [
                [
                    'id' => 1,
                    'sku' => 'A',
                    'name' => 'A',
                    'manufacturer' => 'X',
                    'norms' => '',
                    'catalog_price_net' => '1',
                    'currency' => 'PLN',
                    'stock' => 1,
                    'score' => 90,
                ],
                [
                    'id' => 2,
                    'sku' => 'B',
                    'name' => 'B',
                    'manufacturer' => 'X',
                    'norms' => '',
                    'catalog_price_net' => '2',
                    'currency' => 'PLN',
                    'stock' => 1,
                    'score' => 70,
                ],
            ],
        ]]);

        $this->assertSame('product', $cards[0]['id']);
        $this->assertSame('price', $cards[1]['id']);
        $priceIds = array_column($cards[1]['options'], 'id');
        $this->assertContains('catalog_margin', $priceIds);
        $this->assertLessThanOrEqual(12, count($cards));
    }

    public function test_price_policy_uses_catalog_plus_default_margin(): void
    {
        $block = $this->service()->pricePolicyBlock(
            ['price' => ['option_id' => 'catalog_margin', 'custom' => null]],
            [[
                'id' => 1,
                'sku' => 'G10',
                'catalog_price_net' => '150.00',
                'catalog_pln' => 150.0,
                'offer_pln' => 118.0,
                'currency' => 'PLN',
            ]],
        );

        $this->assertNotNull($block);
        $this->assertStringContainsString('+ 18%', (string) $block);
        $this->assertStringContainsString('oferta 118,00 zł', (string) $block);
        $this->assertSame(18.0, $this->service()->marginPercent(['price' => ['option_id' => 'catalog_margin']]));
    }

    public function test_parse_line_items_splits_qty_rows(): void
    {
        $body = "Dzień dobry\n\nProszę o przedstawienie oferty na\n\n"
            ."30szt Rękawice chemoodporne, antyelektrostatyczne z normą ISO 374-1. rozmiar 10\n\n"
            ."30szt Rękawice chemoodporne, antyelektrostatyczne z normą EN ISO 374-1 rozmiar 9\n\n"
            ."4szt Kalosze chemoodporne antyelektrostatyczne rozmiar 43\n\n"
            ."4szt Kalosze chemoodporne antyelektrostatyczne rozmiar 44\n\n"
            .'8szt Kombinezon chemoodporny ( w szczególności na kwas siarkowy 96%) antyelektrostatyczny, rozmiar uniwersaln';

        $items = $this->service()->parseLineItemsFromBody($body);

        $this->assertCount(5, $items);
        $this->assertSame('30', $items[0]['qty']);
        $this->assertSame('szt', $items[0]['unit']);
        $this->assertSame('10', $items[0]['size']);
        $this->assertSame('9', $items[1]['size']);
        $this->assertSame('43', $items[2]['size']);
        $this->assertStringContainsString('Kombinezon chemoodporny', $items[4]['quote']);
    }

    public function test_parse_line_items_keeps_unit_from_mail_or_null(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            "4 pary rękawic spawalniczych\n2 op. maseczek FFP2\n30 Rękawice nitrylowe rozmiar 9\n10 szt. okularów ochronnych"
        );

        $this->assertCount(4, $items);
        $this->assertSame(['4', 'pary'], [$items[0]['qty'], $items[0]['unit']]);
        $this->assertSame('rękawic spawalniczych', $items[0]['query']);
        $this->assertSame(['2', 'op.'], [$items[1]['qty'], $items[1]['unit']]);
        // brak jednostki w mailu → null, nie dopisujemy „szt.”
        $this->assertSame(['30', null], [$items[2]['qty'], $items[2]['unit']]);
        $this->assertSame('Rękawice nitrylowe', $items[2]['query']);
        $this->assertSame(['10', 'szt.'], [$items[3]['qty'], $items[3]['unit']]);
    }

    public function test_size_reads_after_colon_and_skips_universal(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            '2 szt. Wycieraczka gumowa rozm: 40x60cm'
            .'
10 szt. Rekawice nitrylowe rozmiar uniwersalny'
            .'
4 pary Kalosze rozmiar 43.'
        );

        // dwukropek po „rozm” wycinal rozmiar z frazy, ale nie zapisywal go w pozycji
        $this->assertSame('40x60cm', $items[0]['size']);
        // „uniwersalny” to brak rozmiaru, a nie rozmiar podany przez klienta
        $this->assertNull($items[1]['size']);
        $this->assertSame('43', $items[2]['size']);

        // opis to nie rozmiar: w naglowku pozycji czytalby sie jak rozmiar podany przez klienta
        $opis = $this->service()->parseLineItemsFromBody(
            '2 pary Buty robocze rozmiar do uzgodnienia'
            ."\n3 pary Rekawice rozmiar duzy"
            ."\n4 pary Buty robocze rozmiar 2XL"
        );
        $this->assertNull($opis[0]['size']);
        $this->assertNull($opis[1]['size']);
        $this->assertSame('2XL', $opis[2]['size']);
        // polskie litery w klasie znakow: z frazy nie zostaje ogryzek po wycietym rozmiarze
        $this->assertSame('Rekawice', $opis[1]['query']);
    }

    public function test_build_cards_one_block_per_line_item_with_quote(): void
    {
        $gloves = [
            'id' => 1,
            'sku' => 'G10',
            'name' => 'Rękawice',
            'manufacturer' => 'X',
            'norms' => '',
            'catalog_price_net' => '1',
            'currency' => 'PLN',
            'stock' => 1,
            'score' => 80,
        ];
        $items = $this->service()->parseLineItemsFromBody(
            "30szt Rękawice chemoodporne rozmiar 10\n30szt Rękawice chemoodporne rozmiar 9"
        );

        $cards = $this->service()->buildCards([], [[
            'query' => $items[0]['query'],
            'products' => [$gloves],
        ]], $items);

        // bez zatwierdzonych zamienników nie ma karty zamienników
        $this->assertSame('product:item_1', $cards[0]['id']);
        $this->assertSame('product:item_2', $cards[1]['id']);
        $this->assertSame('price', $cards[2]['id']);
        $this->assertSame('30szt Rękawice chemoodporne rozmiar 10', $cards[0]['quote']);
        $this->assertSame('30szt Rękawice chemoodporne rozmiar 9', $cards[1]['quote']);
        $this->assertSame('30 szt', $cards[0]['qty']);
        $this->assertSame('item', $cards[0]['kind']);
        $this->assertNotContains('substitutes:item_1', array_column($cards, 'id'));
        $this->assertNotContains('category', array_column($cards[0]['options'], 'id'));
    }

    public function test_build_cards_lists_item_substitutes_before_next_position(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            "30szt Rękawice chemoodporne rozmiar 10\n4szt Kalosze chemoodporne rozmiar 43"
        );
        $gloves = [
            'id' => 1,
            'sku' => 'G10',
            'name' => 'Rękawice',
            'manufacturer' => 'X',
            'norms' => '',
            'catalog_price_net' => '1',
            'currency' => 'PLN',
            'stock' => 1,
            'score' => 80,
        ];
        $alt = [
            'id' => 9,
            'sku' => 'G11',
            'name' => 'Rękawice alt',
            'manufacturer' => 'X',
            'norms' => '',
            'catalog_price_net' => '1',
            'currency' => 'PLN',
            'stock' => 1,
            'score' => 70,
        ];

        $cards = $this->service()->buildCards([], [
            ['query' => $items[0]['query'], 'products' => [$gloves]],
            ['query' => $items[1]['query'], 'products' => [[
                'id' => 2,
                'sku' => 'K43',
                'name' => 'Kalosze',
                'manufacturer' => 'X',
                'norms' => '',
                'catalog_price_net' => '2',
                'currency' => 'PLN',
                'stock' => 1,
                'score' => 80,
            ]]],
        ], $items, [1 => [$alt]]);

        $this->assertSame('Zamiennik: G11 · Rękawice alt', $cards[1]['options'][1]['label']);
        $this->assertSame('product:item_2', $cards[2]['id']);
        $ids = array_column($cards, 'id');
        $this->assertNotContains('substitutes', $ids);
    }

    public function test_catalog_search_query_keeps_constraint_from_quote(): void
    {
        $svc = $this->service();

        $this->assertSame(
            'Kombinezon chemoodporny na kwas siarkowy 96%',
            $svc->catalogSearchQuery(
                'kombinezon',
                '8szt Kombinezon chemoodporny na kwas siarkowy 96% rozmiar uniwersalny'
            )
        );
        $this->assertSame(
            'Rękawice chemoodporne',
            $svc->catalogSearchQuery('Rękawice chemoodporne', '30szt Rękawice chemoodporne rozmiar 10')
        );
    }

    public function test_resolve_line_items_prefers_more_body_rows_than_ai(): void
    {
        $body = "30szt Rękawice rozmiar 10\n30szt Rękawice rozmiar 9";
        $resolved = $this->service()->resolveLineItems($body, [[
            'id' => 'item_1',
            'quote' => 'Rękawice',
            'qty' => '60 szt.',
            'query' => 'rękawice',
            'size' => null,
        ]]);

        $this->assertCount(2, $resolved);
        $this->assertSame('10', $resolved[0]['size']);
    }

    /** Zapytanie #51 z 23.09.2026: model zacytował same rozmiary, a symbol wyrobu przepadł. */
    public function test_size_fragment_quote_gets_the_product_row_above_it(): void
    {
        $body = "Proszę o przesłanie oferty cenowej na:\n"
            ."1. RĘKAWICZKI DIAGNOSTYCZNE BEZPUDROWE,(wyposażenie apteczek) -10 OPAKOWAŃ PO 50 PAR = 500par\n"
            ."2. Rękawice ochronne tkaninowe pięciopalcowe, powlekane nitrylem żółtym, zakończone ściągaczem-symbol RNITz  - 432 pary\n"
            .'Rozmiar: 8-108par,9-108par,10-216par.';
        $query = 'rękawice ochronne tkaninowe nitryl żółty ściągacz RNITz';
        $resolved = $this->service()->resolveLineItems($body, [
            ['id' => 'item_1', 'quote' => 'RĘKAWICZKI DIAGNOSTYCZNE BEZPUDROWE,(wyposażenie apteczek) -10 OPAKOWAŃ PO 50 PAR = 500par', 'qty' => '10', 'unit' => 'OPAKOWAŃ', 'query' => 'rękawiczki diagnostyczne bezpudrowe apteczki', 'size' => null],
            ['id' => 'item_2', 'quote' => 'Rozmiar: 8-108par', 'qty' => '108', 'unit' => 'par', 'query' => $query, 'size' => '8'],
            ['id' => 'item_3', 'quote' => '9-108par', 'qty' => '108', 'unit' => 'par', 'query' => $query, 'size' => '9'],
            ['id' => 'item_4', 'quote' => '10-216par', 'qty' => '216', 'unit' => 'par', 'query' => $query, 'size' => '10'],
        ]);

        $this->assertCount(4, $resolved);
        // pozycja z nazwą wyrobu zostaje, jak ją zacytował model
        $this->assertSame('RĘKAWICZKI DIAGNOSTYCZNE BEZPUDROWE,(wyposażenie apteczek) -10 OPAKOWAŃ PO 50 PAR = 500par', $resolved[0]['quote']);
        $expectedQuote = '2. Rękawice ochronne tkaninowe pięciopalcowe, powlekane nitrylem żółtym, zakończone ściągaczem-symbol RNITz  - 432 pary'
            .' Rozmiar: 8-108par,9-108par,10-216par.';
        foreach ([1, 2, 3] as $i) {
            $this->assertSame($expectedQuote, $resolved[$i]['quote']);
            // szukamy wierszem z maila — z symbolem i etykietą, nie frazą modelu
            $this->assertStringContainsString('symbol RNITz', $resolved[$i]['search_query']);
        }
        // ilość i rozmiar zostają z fragmentu, nie „432 pary” całego wiersza
        $this->assertSame([['108', '8'], ['108', '9'], ['216', '10']], array_map(
            static fn (array $item): array => [$item['qty'], $item['size']],
            array_slice($resolved, 1),
        ));
    }

    /** Zapytanie #50 z 23.09.2026: model wpisał każdej pozycji rozmiaru sumę z wiersza (432 pary). */
    public function test_size_item_takes_its_quantity_from_the_size_breakdown(): void
    {
        $quote = 'Rękawice ochronne tkaninowe pięciopalcowe, powlekane nitrylem żółtym, zakończone ściągaczem-symbol RNITz  - 432 pary Rozmiar: 8-108par,9-108par,10-216par.';
        $item = static fn (string $id, string $size): array => [
            'id' => $id, 'quote' => $quote, 'qty' => '432', 'unit' => 'pary', 'query' => 'rękawice RNITz', 'size' => $size,
        ];
        $resolved = $this->service()->resolveLineItems('x', [$item('item_1', '8'), $item('item_2', '9'), $item('item_3', '10')]);

        $this->assertSame([['108', 'par'], ['108', 'par'], ['216', 'par']], array_map(
            static fn (array $i): array => [$i['qty'], $i['unit']],
            $resolved,
        ));

        // #52: model złożył cytat z wiersza i samej pary tego rozmiaru
        $composed = $this->service()->resolveLineItems('x', [
            ['id' => 'item_1', 'quote' => 'Rękawice ochronne tkaninowe pięciopalcowe, powlekane nitrylem żółtym, zakończone ściągaczem-symbol RNITz - 432 pary Rozmiar: 10-216par', 'qty' => '432', 'unit' => 'pary', 'query' => 'rękawice RNITz', 'size' => '10'],
        ]);
        $this->assertSame(['216', 'par'], [$composed[0]['qty'], $composed[0]['unit']]);

        // litery też są rozmiarem; pozycja bez rozmiaru zostaje przy sumie z wiersza
        $letters = $this->service()->resolveLineItems('x', [
            ['id' => 'item_1', 'quote' => 'Kurtka robocza 30 szt: S - 10 szt, M - 20 szt', 'qty' => '30', 'unit' => 'szt', 'query' => 'Kurtka robocza', 'size' => 'M'],
            ['id' => 'item_2', 'quote' => 'Kurtka robocza 30 szt: S - 10 szt, M - 20 szt', 'qty' => '30', 'unit' => 'szt', 'query' => 'Kurtka robocza', 'size' => null],
        ]);
        $this->assertSame('20', $letters[0]['qty']);
        $this->assertSame('30', $letters[1]['qty']);

        // jedna para to nie rozbicie — ilość czyta dotychczasowa reguła
        $single = $this->service()->resolveLineItems('x', [
            ['id' => 'item_1', 'quote' => 'Rękawice nitrylowe rozm. 9 - 50 par', 'qty' => '50', 'unit' => 'par', 'query' => 'Rękawice nitrylowe', 'size' => '9'],
        ]);
        $this->assertSame('50', $single[0]['qty']);
    }

    /** Fragment bez nazwy wyrobu, ale oddzielony od wiersza wyrobu innym wierszem — nie doklejamy. */
    public function test_size_fragment_after_another_line_keeps_its_own_quote(): void
    {
        $body = "1. Rękawice nitrylowe RNITZ 100 par\nProszę o szybką odpowiedź.\nRozmiar 9 - 50 par";
        $resolved = $this->service()->resolveLineItems($body, [
            ['id' => 'item_1', 'quote' => 'Rękawice nitrylowe RNITZ 100 par', 'qty' => '100', 'unit' => 'par', 'query' => 'Rękawice nitrylowe RNITZ', 'size' => null],
            ['id' => 'item_2', 'quote' => 'Rozmiar 9 - 50 par', 'qty' => '50', 'unit' => 'par', 'query' => 'rękawice', 'size' => '9'],
        ]);

        $this->assertSame('Rozmiar 9 - 50 par', $resolved[1]['quote']);

        // fragment stojący w mailu dwa razy — nie wiadomo, pod którym wierszem
        $twice = "1. Rękawice białe dziane - 108 par\n2. Rękawice nitrylowe RNITZ\nRozmiar 8 - 108 par";
        $resolved = $this->service()->resolveLineItems($twice, [
            ['id' => 'item_1', 'quote' => 'Rękawice białe dziane - 108 par', 'qty' => '108', 'unit' => 'par', 'query' => 'Rękawice białe dziane', 'size' => null],
            ['id' => 'item_2', 'quote' => '108 par', 'qty' => '108', 'unit' => 'par', 'query' => 'Rękawice nitrylowe RNITZ', 'size' => '8'],
            ['id' => 'item_3', 'quote' => '108 par', 'qty' => '108', 'unit' => 'par', 'query' => 'Rękawice nitrylowe RNITZ', 'size' => '8'],
        ]);
        $this->assertSame('108 par', $resolved[1]['quote']);
    }

    public function test_cederroth_mail_gives_both_positions_and_nothing_from_the_footer(): void
    {
        $svc = $this->service();
        $expected = function (array $items): void {
            $this->assertCount(2, $items);
            // pozycja 1 zaczynala sie w wierszu zapowiedzi i przepadala
            $this->assertStringContainsString('7251-7200', $items[0]['quote']);
            $this->assertSame(['10', 'szt.'], [$items[0]['qty'], $items[0]['unit']]);
            // pozycja 2 zlamana na myslniku: numer punktu „2” byl iloscia, a „5szt.” osobna pozycja
            $this->assertStringContainsString('725200', $items[1]['quote']);
            $this->assertStringContainsString('5szt./kompletów.', $items[1]['quote']);
            $this->assertSame(['5', 'szt.'], [$items[1]['qty'], $items[1]['unit']]);
        };

        // po odcieciu stopki
        $expected($svc->parseLineItemsFromBody(InquiryMailText::forAnalysis(InquiryMailTextTest::cederrothMail())));
        // i bez odciecia: telefon, konto i data ze stopki nie sa pozycjami same z siebie
        $expected($svc->parseLineItemsFromBody(InquiryMailTextTest::cederrothMail()));
    }

    public function test_wrapped_row_is_joined_only_in_certain_layouts(): void
    {
        $svc = $this->service();

        // krotki wiersz nie jest zlamany: nizej moze stac rozpiska, ktorej nie sklejamy
        $short = $svc->parseLineItemsFromBody("1. Buty robocze S3\nrozmiar 44 - 5 par\n2. Kask ochronny");
        $this->assertSame(['1. Buty robocze S3', '2. Kask ochronny'], array_column($short, 'quote'));
        $this->assertSame([null, null], array_column($short, 'qty'));

        // zapowiedz bez prosby to nie lista wyrobow
        $this->assertSame([], $svc->parseLineItemsFromBody('Termin: 1. kwartał 2027'));
    }

    public function test_rows_without_goods_signs_do_not_outvote_the_model(): void
    {
        $fromAi = [[
            'id' => 'item_1',
            'quote' => 'Proszę o 10 par butów S3.',
            'qty' => '10',
            'unit' => 'par',
            'query' => 'buty S3',
            'size' => null,
        ]];

        // dwa wiersze z parsera bez nazwy wyrobu, ilosci z jednostka i rozmiaru
        $resolved = $this->service()->resolveLineItems("Proszę o 10 par butów S3.\n12, 34\n56 - 78", $fromAi);

        $this->assertCount(1, $resolved);
        $this->assertSame('Proszę o 10 par butów S3.', $resolved[0]['quote']);
    }

    public function test_question_lines_do_not_break_the_numbering(): void
    {
        $svc = $this->service();

        // pominiete pytanie nadal liczy sie do ciagu — inaczej numery kolejnych
        // wierszy wracaly do oferty jako ilosci
        $mixed = $svc->parseLineItemsFromBody(
            '1) Czy posiadacie rekawice nitrylowe?
2. Buty robocze S3 rozmiar 44
3. Kask ochronny bialy'
        );
        $this->assertSame([null, null], array_column($mixed, 'qty'));
        $this->assertSame(['Buty robocze S3', 'Kask ochronny bialy'], array_column($mixed, 'query'));

        // W liscie „1.” pytania nie odsiewamy: „1. Czy moga Panstwo wycenic rekawice
        // 100 par?” to pozycja zamowienia, a o kwalifikacji decyduje pierwsze slowo,
        // wiec odsianie gubiloby prawdziwe pozycje. Przy jawnym znaczniku („1)”, „poz.”)
        // ryzyko jest odwrotne — tam wiersz-pytanie dotad w ogole nie byl pozycja.
        $dots = $svc->parseLineItemsFromBody(
            '1. Czy moga Panstwo wycenic rekawice nitrylowe 100 par?
2. Buty robocze S3'
        );
        $this->assertCount(2, $dots);
        $this->assertSame('100', $dots[0]['qty']);

        // ale pytanie o konkretny wyrob jest pozycja
        $products = $svc->parseLineItemsFromBody(
            '1) Rekawice nitrylowe rozmiar XL?
2) Buty robocze S3 rozmiar 44?'
        );
        $this->assertCount(2, $products);
        $this->assertSame(['XL', '44'], array_column($products, 'size'));
    }

    public function test_carton_counts_as_packaging_only_when_it_is_not_the_product(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            '1. Rekawice lateksowe w kartonie 100 szt.
2. Rekawice winylowe karton 100 szt.
3. Karton zbiorczy na odpady 20 szt.
4. Kartonik ochronny 10 szt.
5. Noz do kartonow 10 szt.
6. Wozek na kartony 2 szt.'
        );

        // zawartosc kartonu to nie zamawiana ilosc
        $this->assertNull($items[0]['qty']);
        $this->assertNull($items[1]['qty']);
        // ale karton bywa wyrobem i wtedy liczba obok niego jest iloscia
        $this->assertSame('20', $items[2]['qty']);
        $this->assertSame('10', $items[3]['qty']);
        // po przyimku karton opisuje wyrob („noz do kartonow”), a nie opakowanie
        $this->assertSame('10', $items[4]['qty']);
        $this->assertSame('2', $items[5]['qty']);
    }

    public function test_question_about_a_product_in_a_dotted_list_stays_an_item(): void
    {
        // „1. Czy moga Panstwo wycenic rekawice 100 par?” to pozycja, a nie pytanie o oferte
        $items = $this->service()->parseLineItemsFromBody(
            '1. Czy moga Panstwo wycenic rekawice nitrylowe 100 par?
2. Buty robocze S3'
        );

        $this->assertCount(2, $items);
        $this->assertSame(['100', 'par'], [$items[0]['qty'], $items[0]['unit']]);
    }

    public function test_numbered_list_of_information_requests_is_not_an_order(): void
    {
        $svc = $this->service();

        // Mail Vallen: pozycja stoi w rozbitej tabeli (parser jej nie czyta, czyta model),
        // a ponumerowana lista pod „Prosze rowniez o podanie:” to pytania o warunki.
        // Wczytane jako pozycje wchodzily do listu zamiast wyrobu z zapytania.
        $vallen = $svc->parseLineItemsFromBody(
            'Prosze o przeslanie oferty cenowej na ponizsze pozycje:

BUTY UVEX BUSINESS CASUAL 8543.8 S1 SRC ROZMIAR 44

Prosze rowniez o podanie:

1. Numeru katalogowego producenta MPN (Manufacturer Part Number)
2. Terminu realizacji
3. Warunkow oraz kosztow dostawy
4. Formy oraz warunkow platnosci (30, 60-cio dniowy, odroczony termin platnosci jest warunkiem preferowanym).
5. Dodatkowych oplat oraz informacji niezbednych do realizacji zamowienia.'
        );
        $this->assertSame([], $vallen);

        // Numeracja pozycji przed lista zadan zostaje numeracja: numery punktow licza
        // sie do ciagu, wiec „1.” z pierwszego wiersza nie wraca do oferty jako ilosc.
        $withItems = $svc->parseLineItemsFromBody(
            '1. Buty robocze S3 rozmiar 44
2. Kask ochronny bialy

Prosimy o podanie nastepujacych informacji:

3. Terminu realizacji
4. Warunkow platnosci'
        );
        $this->assertSame(['Buty robocze S3', 'Kask ochronny bialy'], array_column($withItems, 'query'));
        $this->assertSame([null, null], array_column($withItems, 'qty'));

        // Nagłowek zapowiadajacy wyroby albo ich ceny lista zadan nie jest
        $goods = $svc->parseLineItemsFromBody(
            'Prosimy o podanie cen dla:

1. Rekawice nitrylowe
2. Buty robocze S3'
        );
        $this->assertSame(['Rekawice nitrylowe', 'Buty robocze S3'], array_column($goods, 'query'));

        // a pod lista zadan wiersz ze znamionami wyrobu (ilosc, rozmiar) zostaje pozycja
        $mixed = $svc->parseLineItemsFromBody(
            'Prosze o podanie:

1. Terminu realizacji
2. Rekawice nitrylowe 100 par
3. Buty robocze rozmiar 44'
        );
        $this->assertSame(['Rekawice nitrylowe', 'Buty robocze'], array_column($mixed, 'query'));
        $this->assertSame(['100', null], array_column($mixed, 'qty'));
        $this->assertSame([null, '44'], array_column($mixed, 'size'));
    }

    public function test_information_request_list_ends_where_the_order_starts(): void
    {
        $svc = $this->service();

        // numeracja od nowa to juz inna lista — pod nia moga stac wyroby
        $again = $svc->parseLineItemsFromBody(
            'Prosze o podanie:

1. Terminu realizacji
2. Warunkow platnosci

1. Rekawice nitrylowe
2. Buty robocze S3'
        );
        $this->assertSame(['Rekawice nitrylowe', 'Buty robocze S3'], array_column($again, 'query'));

        // „…dla:”, „…na:” zapowiadaja liste rzeczy, o ktore klient pyta — nie liste
        // informacji do podania
        $forGoods = $svc->parseLineItemsFromBody(
            'Prosze o podanie dostepnosci dla:

1. Rekawice nitrylowe
2. Buty robocze S3'
        );
        $this->assertSame(['Rekawice nitrylowe', 'Buty robocze S3'], array_column($forGoods, 'query'));

        // nagłowek bywa punktem listy: jego numer liczy sie do numeracji, wiec numer
        // wiersza z wyrobem nie wraca do oferty jako ilosc
        $numberedHeader = $svc->parseLineItemsFromBody(
            '1. Prosze o podanie:
2. Terminu realizacji
3. Buty robocze S3 rozmiar 44'
        );
        $this->assertSame(['Buty robocze S3'], array_column($numberedHeader, 'query'));
        $this->assertSame([null], array_column($numberedHeader, 'qty'));
    }

    public function test_model_quantity_is_checked_against_the_quote(): void
    {
        // mail pisany myslnikami: naszego parsera nie ma, pozycje daje model — reguly
        // czytania ilosci maja obowiazywac tak samo
        $items = $this->service()->resolveLineItems('x', [
            ['id' => 'item_1', 'quote' => '- Rekawice nitrylowe, a 100 szt., zamawiamy 4 opakowania', 'qty' => '100', 'unit' => 'szt.', 'query' => 'Rekawice nitrylowe'],
            ['id' => 'item_2', 'quote' => '- Maski FFP2 w kartonie 20 szt.', 'qty' => '20', 'unit' => 'szt.', 'query' => 'Maski FFP2'],
            ['id' => 'item_3', 'quote' => '- Rekawice, cena 24,00 zl/szt.', 'qty' => '24', 'unit' => 'szt.', 'query' => 'Rekawice'],
            ['id' => 'item_4', 'quote' => '- Kaski ochronne, cztery sztuki', 'qty' => '4', 'unit' => 'szt.', 'query' => 'Kaski ochronne'],
            ['id' => 'item_5', 'quote' => '- Buty robocze S3, 10 par', 'qty' => '10', 'unit' => 'par', 'query' => 'Buty robocze S3'],
        ]);

        // wielkosc opakowania to czesc wyrobu, zamowieniem sa 4 opakowania
        $this->assertSame(['4', 'opakowania'], [$items[0]['qty'], $items[0]['unit']]);
        $this->assertStringContainsString('a 100 szt', $items[0]['search_query']);
        // zawartosc kartonu i liczba przy cenie nie sa iloscia — zostaje pusto z flaga
        $this->assertNull($items[1]['qty']);
        $this->assertSame('model_unverified', $items[1]['qty_source']);
        $this->assertNull($items[2]['qty']);
        // ilosci zapisanej slownie nie podwazamy, a zgodnej z cytatem nie ruszamy
        $this->assertSame('4', $items[3]['qty']);
        $this->assertSame('10', $items[4]['qty']);
        $this->assertArrayNotHasKey('qty_source', $items[4]);
    }

    /** Zapytanie #47 z 23.09.2026: „10 12 par” we frazie model brał za warunek opakowania. */
    public function test_search_query_drops_the_item_quantity_and_size(): void
    {
        $items = $this->service()->resolveLineItems('x', [
            ['id' => 'item_1', 'quote' => 'Zestaw plastrów plastikowych CEDERROTH 6036 – 10 kompletów', 'qty' => '10', 'unit' => 'kompletów', 'query' => 'CEDERROTH 6036 plasterki plastikowe', 'size' => null],
            ['id' => 'item_2', 'quote' => 'Rękawice ATG 42-874 r.9 - 40 par', 'qty' => '40', 'unit' => 'par', 'query' => 'ATG 42-874 rękawice', 'size' => '9'],
            ['id' => 'item_3', 'quote' => 'Rękawice MAxicut 44-3745 10 12 par', 'qty' => '12', 'unit' => 'par', 'query' => 'MAxicut 44-3745 rękawice', 'size' => '10'],
            ['id' => 'item_4', 'quote' => 'Filtry 3M 6035 12 szt.', 'qty' => '12', 'unit' => 'szt.', 'query' => 'filtry', 'size' => null],
            // zapytanie #3: ta sama ilość dwa razy i rozmiar podany wzrostem
            ['id' => 'item_5', 'quote' => 'Kalosze białe 3 pary rozmiar 43, 3 pary rozmiar 46', 'qty' => '3', 'unit' => 'pary', 'query' => 'kalosze', 'size' => '43'],
            ['id' => 'item_6', 'quote' => 'Fartuch ochronny 3 sztuki wzrost 176-182', 'qty' => '3', 'unit' => 'sztuki', 'query' => 'fartuch', 'size' => '176-182'],
            // zapytanie #18: cytat wybrany przed wycięciem ilości zostaje cytatem — z kodem „1010”,
            // którego krótsza od pełnego cytatu fraza modelu nie ma
            ['id' => 'item_7', 'quote' => 'ARMEN 9007 1010 S1 38 - 4 pary', 'qty' => '4', 'unit' => 'pary', 'query' => 'ARTRA ARMEN 9007 S1 buty', 'size' => '38'],
        ]);

        $this->assertSame([
            'Zestaw plastrów plastikowych CEDERROTH 6036',
            'Rękawice ATG 42-874',
            'Rękawice MAxicut 44-3745',
            // kod wyrobu nie jest ilością ani rozmiarem — zostaje
            'Filtry 3M 6035',
            'Kalosze białe',
            'Fartuch ochronny',
            'ARMEN 9007 1010 S1',
        ], array_column($items, 'search_query'));
        // ilość i rozmiar dalej siedzą w pozycji, a cytat zostaje słowo w słowo
        $this->assertSame([['10', null], ['40', '9'], ['12', '10'], ['12', null], ['3', '43'], ['3', '176-182'], ['4', '38']], array_map(
            static fn (array $item): array => [$item['qty'], $item['size']],
            $items,
        ));
        $this->assertSame('Rękawice MAxicut 44-3745 10 12 par', $items[2]['quote']);
    }

    /** Liczba różna od ilości i rozmiaru pozycji nie znika z frazy. */
    public function test_search_query_keeps_numbers_that_are_not_the_item_quantity(): void
    {
        $items = $this->service()->resolveLineItems('x', [
            // zawartość opakowania to część wyrobu, nie zamawiana ilość
            ['id' => 'item_1', 'quote' => 'Rękawice nitrylowe op. 100 szt.', 'qty' => '100', 'unit' => 'szt.', 'query' => 'rękawice', 'size' => null],
            // rozmiar w środku frazy, nie na jej końcu, zostaje
            ['id' => 'item_2', 'quote' => 'Kask 10 lat gwarancji', 'qty' => null, 'unit' => null, 'query' => 'kask', 'size' => '10'],
        ]);

        $this->assertSame('Rękawice nitrylowe op. 100 szt', $items[0]['search_query']);
        $this->assertSame('Kask 10 lat gwarancji', $items[1]['search_query']);
    }

    public function test_unit_never_ends_inside_a_word(): void
    {
        // „op.” dopasowywalo sie do „opisy”, a „para” do „parametry” — z frazy zostawal
        // ogryzek („isy techniczne”), a do oferty wchodzila ilosc, ktorej nikt nie podal
        $items = $this->service()->parseLineItemsFromBody(
            '1. Rekawice 2 opisy techniczne w komplecie
2. Odziez 3 parametry ochrony
3. Rekawice 100 par'
        );

        $this->assertNull($items[0]['qty']);
        $this->assertSame('Rekawice 2 opisy techniczne w komplecie', $items[0]['query']);
        $this->assertNull($items[1]['qty']);
        $this->assertSame(['100', 'par'], [$items[2]['qty'], $items[2]['unit']]);
    }

    public function test_questions_about_price_or_term_stay_questions_even_with_a_quantity(): void
    {
        $svc = $this->service();

        // „Ile kosztuje 100 par?” pyta o warunki, a nie zamawia
        $asking = $svc->parseLineItemsFromBody(
            '1) Ile kosztuje 100 par rekawic?
2) Jaka jest cena za 50 szt.?
3) Jaki jest termin dostawy 50 szt. kaskow?
4) Rekawice nitrylowe 100 par'
        );
        $this->assertSame(['Rekawice nitrylowe'], array_column($asking, 'query'));

        // pytanie o wyrob z iloscia to zamowienie: pyta o towar, nie o warunki handlowe
        $aboutGoods = $svc->parseLineItemsFromBody(
            '1) Jakie rekawice nitrylowe 100 par macie w ofercie?
2) Czy posiadacie rekawice?'
        );
        $this->assertCount(1, $aboutGoods);
        $this->assertSame('100', $aboutGoods[0]['qty']);

        // ale zamowienie zapisane jako pytanie zostaje pozycja
        $ordering = $svc->parseLineItemsFromBody(
            '1) Jak najszybciej potrzebujemy 100 par rekawic?
2) Czy dostarczycie 500 szt. rekawic do piatku?'
        );
        $this->assertSame(['100', '500'], array_column($ordering, 'qty'));
    }

    public function test_cartons_ordered_after_a_dash_are_the_quantity(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            '1. Worki na odpady 120 l, 20 szt. w kartonie - 30 kartonow
2. Rekawice lateksowe w kartonie 100 szt.
3. Papier toaletowy, 36 rolek w kartonie - 20 kartonow'
        );

        // po mysliniku ilosc liczy sie tylko przy jednostce opakowaniowej
        $this->assertSame(['30', 'kartonow'], [$items[0]['qty'], $items[0]['unit']]);
        $pieces = $this->service()->parseLineItemsFromBody(
            '1. Papier toaletowy w kartonie - 100 szt.
2. Rekawice, 20 szt. w opakowaniu - 10 opakowan'
        );
        // „w kartonie - 100 szt.” to nadal zawartosc opakowania
        $this->assertNull($pieces[0]['qty']);
        $this->assertSame(['10', 'opakowan'], [$pieces[1]['qty'], $pieces[1]['unit']]);
        $this->assertSame(['20', 'kartonow'], [$items[2]['qty'], $items[2]['unit']]);
        $this->assertNull($items[1]['qty']);
    }

    public function test_question_words_cover_short_forms_but_not_ordinary_words(): void
    {
        $svc = $this->service();

        // „Jak” i „Jakim” wypadly ze slownika i pytania szly do katalogu jako nazwy wyrobow
        $questions = $svc->parseLineItemsFromBody(
            '1) Jak dlugo trwa dostawa?
2) Jakim transportem dostarczacie?
3) Rekawice nitrylowe 100 par'
        );
        $this->assertSame(['Rekawice nitrylowe'], array_column($questions, 'query'));

        // wiersz z iloscia i jednostka jest zamowieniem, choćby zaczynal sie od „Jak”
        $order = $svc->parseLineItemsFromBody(
            '1) Jak najszybciej potrzebujemy 100 par rekawic nitrylowych?
2) Jak dlugo trwa dostawa?'
        );
        $this->assertCount(1, $order);
        $this->assertSame(['100', 'par'], [$order[0]['qty'], $order[0]['unit']]);

        // przymiotnik „kartonowe” nie jest jednostka
        $adj = $svc->parseLineItemsFromBody(
            '1. 2 kartonowe pudla archiwizacyjne
2. Pudla 5 kartonowych wkladek, 20 szt.'
        );
        $this->assertNull($adj[0]['qty']);
        $this->assertSame(['20', 'szt.'], [$adj[1]['qty'], $adj[1]['unit']]);

        // pytanie bez liczby zostaje pytaniem
        $this->assertSame([], $svc->parseLineItemsFromBody(
            '1) Jak najlepiej zapakowac?
2) Jak dlugo czekamy?'
        ));

        // „Co najmniej 100 par rekawic?” to zamowienie, a nie pytanie o oferte
        $order = $svc->parseLineItemsFromBody(
            '1) Co najmniej 100 par rekawic nitrylowych?
2) Buty robocze S3'
        );
        $this->assertCount(2, $order);
        $this->assertSame('100', $order[0]['qty']);
    }

    public function test_longer_unit_names_are_not_cut_in_half(): void
    {
        // „zest.” wygrywalo z „zestawy” i z wiersza zostawalo „awy pierwszej pomocy”
        $items = $this->service()->parseLineItemsFromBody(
            '1. 3 zestawy pierwszej pomocy
2. 2 komplety odziezy roboczej'
        );

        $this->assertSame(['zestawy', 'komplety'], array_column($items, 'unit'));
        $this->assertSame(['pierwszej pomocy', 'odziezy roboczej'], array_column($items, 'query'));
    }

    public function test_norm_code_before_the_quantity_is_not_a_package_size(): void
    {
        // „4121X 100 par” to kod normy przed iloscia, a nie zawartosc opakowania
        $items = $this->service()->parseLineItemsFromBody(
            '1. Rekawice EN 388 4121X 100 par
2. Karton zbiorczy 20 szt.
3. Rekawice lateksowe a 100 szt.'
        );

        $this->assertSame('100', $items[0]['qty']);
        $this->assertSame('20', $items[1]['qty']);
        $this->assertNull($items[2]['qty']);
    }

    public function test_mixed_markers_and_numbers_are_one_numbering(): void
    {
        $svc = $this->service();

        // numer ze znacznika musi liczyc sie do ciagu — inaczej numeracja zaczyna sie
        // od dwojki, nie jest rozpoznana i numery wierszy wchodza do oferty jako ilosci
        $mixed = $svc->parseLineItemsFromBody(
            '1) Rekawice nitrylowe
2. Buty robocze
3. Kask ochronny'
        );
        $this->assertSame([null, null, null], array_column($mixed, 'qty'));

        // ponumerowane pytania nie sa pozycjami zamowienia
        $questions = $svc->parseLineItemsFromBody(
            '1) Czy posiadacie rekawice nitrylowe w rozmiarze XL?
2) Jaki jest termin dostawy?'
        );
        $this->assertSame([], $questions);
    }

    public function test_position_markers_become_items_without_an_invented_quantity(): void
    {
        $svc = $this->service();

        // „1)” i „(poz9)” nie tworzyly dotad zadnej pozycji
        $brackets = $svc->parseLineItemsFromBody('1) Rekawice nitrylowe
2) Buty robocze S3
3) Kask ochronny');
        $this->assertCount(3, $brackets);
        $this->assertSame([null, null, null], array_column($brackets, 'qty'));
        $this->assertSame('Rekawice nitrylowe', $brackets[0]['query']);

        // numer ze znacznika nigdy nie jest iloscia — inaczej „(poz9)” dalby 9 sztuk
        $poz = $svc->parseLineItemsFromBody('(poz9). Lopata do sniegu
(poz10)Drabina KRAUSE 815446');
        $this->assertCount(2, $poz);
        $this->assertSame([null, null], array_column($poz, 'qty'));
        $this->assertSame('Drabina KRAUSE 815446', $poz[1]['query']);

        // ilosc podana dalej w wierszu zostaje odczytana
        $withQty = $svc->parseLineItemsFromBody('poz. 12 Rekawice nitrylowe 20 szt.
poz. 13 Buty robocze');
        $this->assertSame(['20', 'szt.'], [$withQty[0]['qty'], $withQty[0]['unit']]);
        $this->assertNull($withQty[1]['qty']);
    }

    public function test_quantity_inside_a_numbered_row_is_found_but_never_invented(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            '1. 20 szt. Rekawice nitrylowe rozmiar L'
            .'
2. Rekawice nitrylowe, op. 100 szt.'
            .'
3. Buty robocze, cena 24,00 zl za 1 szt.'
            .'
4. Kask ochronny 10 szt./op.'
            .'
5. Rekawice lateksowe a 100 szt.'
            .'
6. Chusteczki x 100 szt.'
            .'
7. Maski, cena 39,00 zl, 10 szt.'
        );

        // ilosc stala dalej w wierszu, za numerem pozycji
        $this->assertSame(['20', 'szt.', 'row'], [$items[0]['qty'], $items[0]['unit'], $items[0]['qty_source']]);
        $this->assertSame('L', $items[0]['size']);
        // zapis ilosci znika z frazy katalogowej, zeby nie szedl do wyszukiwarki
        $this->assertSame('Rekawice nitrylowe', $items[0]['query']);
        // zawartosc opakowania to nie zamawiana ilosc — takze w zapisie „a 100 szt.”
        $this->assertNull($items[1]['qty']);
        $this->assertNull($items[3]['qty']);
        $this->assertNull($items[4]['qty']);
        $this->assertNull($items[5]['qty']);
        // przelicznik ceny jednostkowej („cena 24,00 zl za 1 szt.”) nie jest iloscia
        $this->assertNull($items[2]['qty']);
        // ale ilosc podana po cenie juz tak
        $this->assertSame(['10', 'szt.'], [$items[6]['qty'], $items[6]['unit']]);
    }

    public function test_lost_quantity_is_flagged_for_the_salesperson(): void
    {
        $svc = $this->service();
        $inquiry = new ClientInquiry([
            'tone' => 'formal',
            'source_body' => 'x',
            'analysis' => [
                'line_items' => [[
                    'id' => 'item_1',
                    'quote' => '1. Rekawice nitrylowe',
                    'qty' => null,
                    'qty_source' => 'enumeration',
                    'unit' => null,
                    'query' => 'Rekawice nitrylowe',
                ]],
                'matches' => [['query' => 'Rekawice nitrylowe', 'products' => $this->candidates([92])]],
                'cards' => [],
            ],
        ]);

        // w mailu stala liczba, ale byla numerem pozycji — brak ilosci ma byc widoczny
        $this->assertContains('qty_unknown', $svc->itemsView($inquiry)[0]['flags']);
    }

    public function test_list_numbers_with_a_gap_are_still_numbering(): void
    {
        $svc = $this->service();

        // przepisana lista z luka: „4” to numer pozycji, nie ilosc
        $gap = $svc->parseLineItemsFromBody(
            '1. Rekawice nitrylowe'
            .'
2. Buty robocze S3'
            .'
4. Kask ochronny'
        );
        $this->assertSame([null, null, null], array_column($gap, 'qty'));
        $this->assertSame(['enumeration', 'enumeration', 'enumeration'], array_column($gap, 'qty_source'));

        // jednostka przy liczbie przewaza: to ilosci, nawet gdy wygladaja jak numeracja
        $units = $svc->parseLineItemsFromBody(
            '1 szt. Rekawice nitrylowe'
            .'
2 szt. Buty robocze S3'
            .'
4 szt. Kask ochronny'
        );
        $this->assertSame(['1', '2', '4'], array_column($units, 'qty'));

        // wyciag z SIWZ („poz. 1, 2, 9”) tez jest numeracja: gola liczba w rosnacym
        // ciagu od jedynki to numer wiersza, a wpisanie jej do oferty byloby iloscia,
        // ktorej klient nie podal. Ilosc rozpoznajemy po jednostce, nie po wielkosci luki.
        $far = $svc->parseLineItemsFromBody(
            '1. Rekawice nitrylowe
2. Buty robocze S3
9. Kask ochronny'
        );
        $this->assertSame([null, null, null], array_column($far, 'qty'));

        // ciag, ktory nie zaczyna sie od jedynki, to ilosci
        $counts = $svc->parseLineItemsFromBody(
            '3 Rekawice nitrylowe
5 Buty robocze S3'
        );
        $this->assertSame(['3', '5'], array_column($counts, 'qty'));
    }

    public function test_size_is_read_from_shortened_spellings_and_leaves_the_query(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            '2 pary Buty robocze rozm.44'
            .'
3 pary Buty robocze roz. 43'
            .'
4 szt. Material rozmiarowy uniwersalny'
            .'
5 szt. Rekawice nitrylowe roz 4512 katalogowy'
        );

        // „rozm.44” bez spacji trafialo i do frazy katalogowej, i nigdzie jako rozmiar
        $this->assertSame('44', $items[0]['size']);
        $this->assertSame('Buty robocze', $items[0]['query']);
        $this->assertSame('43', $items[1]['size']);
        $this->assertSame('Buty robocze', $items[1]['query']);
        // „rozmiarowy” to nie „rozmiar”, a „roz 4512” bez kropki to numer katalogowy
        $this->assertNull($items[2]['size']);
        $this->assertNull($items[3]['size']);
        $this->assertStringContainsString('4512', $items[3]['query']);
    }

    public function test_margin_reads_the_same_input_as_the_validator(): void
    {
        $svc = $this->service();

        // twarda spacja z Worda przechodzila walidacje, a cene liczyla marza domyslna
        $this->assertSame(30.0, $svc->marginPercent(['price' => ['custom' => "30\u{00A0}%"]]));
        $this->assertSame(12.5, $svc->marginPercent(['price' => ['custom' => '12,5']]));
        $this->assertSame(18.0, $svc->marginPercent(['price' => ['custom' => 'osiemnascie']]));

        // granica jedna dla walidacji i dla liczenia: twarde 99 przycinalo dozwolona marze
        config()->set('pricing.offer_margin_max', 150);
        $this->assertSame(120.0, $svc->marginPercent(['price' => ['custom' => '120']]));
        $this->assertSame(150.0, $svc->marginPercent(['price' => ['custom' => '400']]));
    }

    public function test_confidence_thresholds_high_medium_none(): void
    {
        $svc = $this->service();

        $this->assertSame('high', $svc->confidenceFor($this->candidates([90])));
        $this->assertSame('high', $svc->confidenceFor($this->candidates([90, 79])));
        $this->assertSame('high', $svc->confidenceFor($this->candidates([80])));
        // drugi kandydat bliżej niż 11 pkt — niejednoznaczne
        $this->assertSame('medium', $svc->confidenceFor($this->candidates([90, 85])));
        $this->assertSame('medium', $svc->confidenceFor($this->candidates([79])));
        $this->assertSame('medium', $svc->confidenceFor($this->candidates([65])));
        $this->assertSame('none', $svc->confidenceFor($this->candidates([64])));
        $this->assertSame('none', $svc->confidenceFor([]));
    }

    public function test_sku_quoted_by_client_below_the_threshold_is_not_a_certain_match(): void
    {
        $svc = $this->service();
        // model ocenil ten wiersz ponizej progu: zgodny bywa sam ciag znakow, nie wyrob
        $low = $this->candidates([30]);
        $item = ['id' => 'item_1', 'quote' => '20 szt. Okulary sku1 bezbarwne', 'query' => 'okulary'];

        $this->assertSame('none', $svc->confidenceFor($low, $item));
        // do listu idzie „sprawdzimy i wrocimy”, a wiersz zostaje na liscie do wyboru
        $this->assertSame('check', $svc->defaultOptionFor($item, $low));
    }

    public function test_sku_quoted_by_client_is_high_confidence_and_wins_tie(): void
    {
        $svc = $this->service();
        $item = ['id' => 'item_1', 'quote' => '20 szt. Okulary 3M SecureFit sku2 bezbarwne', 'query' => 'okulary'];
        $tie = $this->candidates([94, 94, 94]);

        // bez kodu w cytacie: remis 94/94 = niejednoznaczne
        $this->assertSame('medium', $svc->confidenceFor($tie, ['quote' => 'okulary ochronne']));
        // kod klienta stoi dosłownie w cytacie: pewne, nawet przy remisie
        $this->assertSame('high', $svc->confidenceFor([$tie[1], $tie[0], $tie[2]], $item));
        // fragment kodu wewnątrz innego tokenu nie liczy się
        $this->assertSame('medium', $svc->confidenceFor($tie, ['quote' => 'model xsku1y']));

        $inquiry = new ClientInquiry([
            'tone' => 'formal',
            'source_body' => 'x',
            'analysis' => [
                'line_items' => [$item],
                'matches' => [['query' => 'okulary', 'products' => $tie]],
                'cards' => [],
            ],
        ]);
        $items = $svc->itemsView($inquiry);

        $this->assertSame('high', $items[0]['confidence']);
        $this->assertSame([], $items[0]['flags']);
        $this->assertSame('SKU2', $items[0]['candidates'][0]['sku']);
        $this->assertSame('p:2', $svc->defaultAnswers($inquiry, 'none', 18.0)['product:item_1']['option_id']);
    }

    public function test_default_option_is_best_candidate_or_check(): void
    {
        $svc = $this->service();
        $item = ['id' => 'item_1', 'quote' => 'x', 'query' => 'x'];

        $this->assertSame('p:1', $svc->defaultOptionFor($item, $this->candidates([90, 85])));
        $this->assertSame('p:1', $svc->defaultOptionFor($item, $this->candidates([66])));
        $this->assertSame('check', $svc->defaultOptionFor($item, $this->candidates([46])));
        $this->assertSame('check', $svc->defaultOptionFor($item, []));
    }

    public function test_default_answers_cover_items_substitutes_and_price(): void
    {
        $svc = $this->service();
        $items = $svc->parseLineItemsFromBody(
            "30szt Rękawice chemoodporne rozmiar 10\n4szt Kalosze chemoodporne rozmiar 43"
        );
        [$gloves, $boots, $alt] = $this->safeProducts([
            ['id' => 11, 'sku' => 'G10', 'name' => 'Rękawice', 'purchase_price' => '5', 'catalog_price_net' => '10', 'ai_match_percent' => 90],
            ['id' => 22, 'sku' => 'K43', 'name' => 'Kalosze', 'purchase_price' => '5', 'catalog_price_net' => '10', 'ai_match_percent' => 46],
            ['id' => 33, 'sku' => 'G11', 'name' => 'Rękawice alt', 'purchase_price' => '5', 'catalog_price_net' => '10'],
        ]);
        $inquiry = new ClientInquiry([
            'tone' => 'formal',
            'source_body' => 'x',
            'analysis' => [
                'line_items' => $items,
                'matches' => [
                    ['query' => $items[0]['query'], 'products' => [$gloves]],
                    ['query' => $items[1]['query'], 'products' => [$boots]],
                ],
                'substitutes' => [11 => [$alt]],
                'cards' => [],
            ],
        ]);

        $answers = $svc->defaultAnswers($inquiry, 'catalog_margin', 18.0);

        $this->assertSame('p:11', $answers['product:item_1']['option_id']);
        $this->assertSame('no', $answers['substitutes:item_1']['option_id']);
        $this->assertSame('check', $answers['product:item_2']['option_id']);
        $this->assertArrayNotHasKey('substitutes:item_2', $answers);
        $this->assertSame(['option_id' => 'catalog_margin', 'custom' => '18'], $answers['price']);

        $inquiry->answers = $answers;
        $view = $svc->itemsView($inquiry);

        $this->assertSame('high', $view[0]['confidence']);
        $this->assertSame('p:11', $view[0]['chosen']);
        $this->assertSame('substitutes:item_1', $view[0]['substitute_key']);
        $this->assertSame(33, $view[0]['substitutes'][0]['id']);
        $this->assertNull($view[0]['substitutes'][0]['score']);
        $this->assertNull($view[0]['substitutes'][0]['reason']);
        $this->assertSame([], $view[0]['flags']);
        $this->assertSame('30', $view[0]['qty']);
        $this->assertSame('szt', $view[0]['unit']);
        $this->assertSame('none', $view[1]['confidence']);
        $this->assertSame('check', $view[1]['chosen']);
        $this->assertNull($view[1]['substitute_key']);
        $this->assertSame(['low_score'], $view[1]['flags']);
        $this->assertSame(46, $view[1]['candidates'][0]['score']);
        $this->assertSame(1, $svc->attentionCount($inquiry));
    }

    public function test_items_view_splits_legacy_qty_and_flags_ambiguous(): void
    {
        $svc = $this->service();
        $inquiry = new ClientInquiry([
            'tone' => 'formal',
            'source_body' => 'x',
            'analysis' => [
                'line_items' => [[
                    'id' => 'item_1',
                    'quote' => '30szt Rękawice chemoodporne rozmiar 10',
                    'qty' => '30 szt.',
                    'size' => '10',
                    'query' => 'Rękawice chemoodporne',
                ]],
                'matches' => [['query' => 'Rękawice chemoodporne', 'products' => $this->candidates([88, 90])]],
                'cards' => [],
            ],
            'answers' => ['product:item_1' => ['option_id' => 'category']],
        ]);

        $view = $svc->itemsView($inquiry);

        $this->assertSame('30', $view[0]['qty']);
        $this->assertSame('szt.', $view[0]['unit']);
        // kandydaci malejąco po score, stare „category” liczy się jak „check”
        $this->assertSame([90, 88], array_column($view[0]['candidates'], 'score'));
        $this->assertSame('Powód 2', $view[0]['candidates'][0]['reason']);
        $this->assertSame('medium', $view[0]['confidence']);
        $this->assertSame('check', $view[0]['chosen']);
        $this->assertSame(['ambiguous'], $view[0]['flags']);
    }

    public function test_items_view_makes_pseudo_item_without_quote_for_descriptive_mail(): void
    {
        $svc = $this->service();
        $inquiry = new ClientInquiry([
            'tone' => 'formal',
            'source_body' => 'x',
            'analysis' => [
                'product_queries' => ['rękawice nitrylowe'],
                'matches' => [['query' => 'rękawice nitrylowe', 'products' => $this->candidates([91])]],
                'cards' => [[
                    'id' => 'sizes',
                    'title' => 'Rozmiar',
                    'prompt' => 'Brak rozmiaru',
                    'options' => [['id' => 'ask', 'label' => 'Dopytaj'], ['id' => 'skip', 'label' => 'Nie']],
                    'allow_custom' => false,
                    'kind' => 'global',
                ]],
            ],
            'answers' => [],
        ]);

        $view = $svc->itemsView($inquiry);

        $this->assertCount(1, $view);
        $this->assertSame('item_1', $view[0]['id']);
        $this->assertNull($view[0]['quote']);
        $this->assertNull($view[0]['qty']);
        $this->assertNull($view[0]['unit']);
        $this->assertSame('product:item_1', $view[0]['answer_key']);
        $this->assertSame('p:1', $view[0]['chosen']);
        $this->assertSame([], $view[0]['cards']);
        $this->assertSame('sizes', $svc->present($inquiry)['global_cards'][0]['id']);
    }
}
