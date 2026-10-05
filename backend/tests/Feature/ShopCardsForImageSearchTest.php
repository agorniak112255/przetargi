<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Enrichment\HybridWebSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Karta 8772 (HyFlex 11-840, 05.10.2026): zdjęcie z ansell.com zasłania zapora, a „HyFlex 11-840 Ansell” zwraca
 * w połowie ansell.com. Zapytanie o sklepy wyklucza domenę producenta, a z wyników odpadają producent i aukcje.
 */
final class ShopCardsForImageSearchTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'https://www.glovex.com.pl/Rekawice-powlekane-nitrylem-Ansell-HyFlex-11-840';

    public function test_query_excludes_manufacturer_site_and_drops_manufacturer_and_marketplace_hits(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'model' => 'qwen38-27b-fast',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'search_engine' => 'searxng',
            'searxng_url' => 'http://127.0.0.1:8088',
            'web_search_enabled' => false,
        ]);
        $product = Product::query()->create([
            'sku' => '11840120',
            'name' => 'HyFlex 11840',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 10,
            'purchase_price' => 10,
            'stock' => 1,
        ]);
        $queries = [];
        Http::fake(function (Request $request) use (&$queries) {
            if (! str_contains($request->url(), '127.0.0.1:8088/search')) {
                return Http::response('unused', 404);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
            $queries[] = (string) ($params['q'] ?? $request->data()['q'] ?? '');

            return Http::response(['results' => [
                ['url' => 'https://www.ansell.com/pl/pl/products/hyflex-11-840', 'title' => 'Ansell HyFlex 11-840', 'content' => 'HyFlex 11-840'],
                ['url' => 'https://allegro.pl/produkt/rekawice-ansell-hyflex-11-840-rozmiar-7', 'title' => 'Rękawice Ansell HyFlex 11-840', 'content' => 'HyFlex 11-840'],
                ['url' => self::SHOP, 'title' => 'Rękawice powlekane nitrylem Ansell HyFlex 11-840', 'content' => 'Ansell HyFlex 11-840'],
            ]], 200);
        });

        $cards = app(HybridWebSearchService::class)->shopCardsForImage($product, ['www.ansell.com']);

        $this->assertSame([self::SHOP], array_column($cards, 'url'));
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('-site:ansell.com', $queries[0]);
        $this->assertStringContainsString('11-840', $queries[0]);
    }
}
