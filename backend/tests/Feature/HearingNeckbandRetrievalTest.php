<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nakarkowe to osobne mocowanie (K9, 25.09.2026). Zapytanie o nauszniki nakarkowe szukało po „nagłown/pałąk”,
 * więc karta „Nauszniki … nakarkowe” bez słowa „pałąk” nie przychodziła z wyszukiwania po mocowaniu.
 */
final class HearingNeckbandRetrievalTest extends TestCase
{
    use RefreshDatabase;

    public function test_neckband_query_finds_neckband_card_without_headband_word(): void
    {
        $base = ['manufacturer' => '3M', 'catalog_price_net' => 10, 'purchase_price' => 5, 'stock' => 1, 'enrichment_status' => Product::ENRICHMENT_DONE];
        Product::query()->create($base + ['sku' => 'H520B', 'name' => 'Nauszniki p/hałasowe nakarkowe PELTOR Optime II H520B']);
        Product::query()->create($base + ['sku' => 'H520A', 'name' => 'Nauszniki p/hałasowe nagłowne PELTOR Optime II H520A']);

        $service = app(ProductAiSearchService::class);
        $skus = (new \ReflectionMethod($service, 'retrieveByHearingMount'))
            ->invoke($service, 'Nauszniki przeciwhałasowe nakarkowe SNR 30 dB', 40)
            ->pluck('sku')
            ->all();

        $this->assertSame(['H520B'], $skus);
    }
}
