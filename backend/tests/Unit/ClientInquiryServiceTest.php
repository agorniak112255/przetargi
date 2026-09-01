<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ClientInquiryService;
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
        );
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
                'catalog_price_net' => '100.00',
                'currency' => 'PLN',
            ]],
        );

        $this->assertNotNull($block);
        $this->assertStringContainsString('+ 18%', (string) $block);
        $this->assertStringContainsString('oferta 118.00 PLN', (string) $block);
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
        $this->assertSame('30 szt.', $items[0]['qty']);
        $this->assertSame('10', $items[0]['size']);
        $this->assertSame('9', $items[1]['size']);
        $this->assertSame('43', $items[2]['size']);
        $this->assertStringContainsString('Kombinezon chemoodporny', $items[4]['quote']);
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

        $this->assertSame('product:item_1', $cards[0]['id']);
        $this->assertSame('substitutes:item_1', $cards[1]['id']);
        $this->assertSame('product:item_2', $cards[2]['id']);
        $this->assertSame('substitutes:item_2', $cards[3]['id']);
        $this->assertSame('30szt Rękawice chemoodporne rozmiar 10', $cards[0]['quote']);
        $this->assertSame('30szt Rękawice chemoodporne rozmiar 9', $cards[2]['quote']);
        $this->assertSame('30 szt.', $cards[0]['qty']);
        $this->assertSame('item', $cards[0]['kind']);
        $this->assertSame('Tylko wskazany towar', $cards[1]['options'][0]['label']);
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
}
