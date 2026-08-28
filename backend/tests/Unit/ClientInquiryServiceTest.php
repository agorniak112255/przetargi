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
        $this->assertLessThanOrEqual(5, count($cards));
    }
}
