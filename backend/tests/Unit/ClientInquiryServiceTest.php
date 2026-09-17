<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ClientInquiry;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ClientInquiryService;
use App\Services\NbpExchangeRateService;
use App\Services\ProductInquirySearch;
use Mockery;
use Tests\TestCase;

final class ClientInquiryServiceTest extends TestCase
{
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

    public function test_size_is_read_from_shortened_spellings_and_leaves_the_query(): void
    {
        $items = $this->service()->parseLineItemsFromBody(
            '2 pary Buty robocze rozm.44'
            .'
3 pary Buty robocze roz. 43'
            .'
4 szt. Material rozmiarowy uniwersalny'
            .'
5 szt. oferta z 2024 r. 10 szt. rekawic'
        );

        // „rozm.44” bez spacji trafialo i do frazy katalogowej, i nigdzie jako rozmiar
        $this->assertSame('44', $items[0]['size']);
        $this->assertSame('Buty robocze', $items[0]['query']);
        $this->assertSame('43', $items[1]['size']);
        $this->assertSame('Buty robocze', $items[1]['query']);
        // „rozmiarowy” to nie „rozmiar”, a skrot „r.” to rok, nie rozmiar
        $this->assertNull($items[2]['size']);
        $this->assertNull($items[3]['size']);
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
