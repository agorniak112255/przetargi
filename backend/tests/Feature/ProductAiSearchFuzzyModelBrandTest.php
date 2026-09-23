<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductAiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Lista „model z marką” (retrieveByFuzzyModel) brała markę z manufacturerHints, czyli z dowolnego słowa od
 * trzech liter spoza listy pomijanych. Dla „Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna” dawało to
 * [przeciwhalasowe, wersja, naglowna]: „3M” ginęło na progu długości, a „Peltor” jako część igły modelu.
 * 23.09.2026 w puli tego zapytania nie było ani jednej karty 3M, choć karty Peltor X2A przechodziły wszystkie
 * bramki zgodności — nikt ich nie pobierał.
 *
 * Test sprawdza samą tę listę, nie całą pulę kandydatów: przy kilku kartach w bazie inne listy znajdą wszystko
 * i nie odróżnią stanu przed poprawką od stanu po.
 */
final class ProductAiSearchFuzzyModelBrandTest extends TestCase
{
    use RefreshDatabase;

    /** @return Collection<int, Product> */
    private function fuzzy(string $query): Collection
    {
        $method = new ReflectionMethod(ProductAiSearchService::class, 'retrieveByFuzzyModel');

        return $method->invoke($this->app->make(ProductAiSearchService::class), $query, 40);
    }

    public function test_brand_from_the_catalog_set_finds_the_named_model_instead_of_random_words(): void
    {
        $x2a = $this->make('7100141454', 'Nauszniki Ochronne 3M™ PELTOR™ X2A', '3M');
        // inny producent, ta sama rodzina — dziś wygrywał, bo zapytanie szło po przypadkowych słowach
        $jsp = $this->make('AEB020-0AY-900', 'Ochronniki słuchu na pałąku Sonis®2 — 31dB SNR', 'JSP');

        $skus = $this->fuzzy('Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna')->pluck('sku')->all();

        $this->assertContains($x2a->sku, $skus, 'karta Peltor X2A nie weszła do listy modelu z marką');
        $this->assertNotContains($jsp->sku, $skus, 'lista modelu z marką wciągnęła kartę innego producenta');
    }

    public function test_line_name_outside_the_model_needle_still_anchors_the_brand_scan(): void
    {
        // „solus” nie siedzi w igle modelu („s1201sgaf”), a to ono trzymało zapytanie przy właściwej karcie.
        // Kotwica wyłącznie na słowach z igły zgubiła tę kartę w symulacji na produkcji — tego ma pilnować test.
        $solus = $this->make('7100244066', '3M™ Solus™ S1201SGAF Okulary ochronne, zielono/czarne oprawki', '3M');
        $this->make('7000103989', '3M™ PELTOR™ Nauszniki przeciwhałasowe, żółte, nagłowne, X2A', '3M');

        $skus = $this->fuzzy('Okulary ochronne 3M SOLUS S1201SGAF - EU')->pluck('sku')->all();

        $this->assertContains($solus->sku, $skus, 'nazwa linii spoza igły modelu przestała kotwiczyć skan marki');
    }

    public function test_brand_outside_the_catalog_set_keeps_the_old_path(): void
    {
        // marki nie ma w zamkniętym zbiorze — zostaje dotychczasowa ścieżka, inaczej przestałaby działać
        $card = $this->make('BN-500', 'Rękawice Brandnowy 500 powlekane', 'Brandnowy');

        $skus = $this->fuzzy('Rękawice Brandnowy 500')->pluck('sku')->all();

        $this->assertContains($card->sku, $skus);
    }

    private function make(string $sku, string $name, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'category' => 'Ochrona',
            'description' => $name,
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
