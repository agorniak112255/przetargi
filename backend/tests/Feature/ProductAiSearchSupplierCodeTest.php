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
 * Dawny numer katalogowy producenta bywa tylko w tabelce danych sklepowych. Import cennika 3M
 * przeniósł karty na numery globalne (1575818 → 7000103989), a stary numer został w tabelce.
 * Wyszukiwanie po kodzie modelu czytało wyłącznie SKU i nazwę, więc nie znajdowało po nim nic:
 * kartę wyławiał okrężnie dopiero indeks tekstowy, a ten przy zapytaniu „3M 1575818” rozmywał
 * się na setkach kart tej samej marki i klient dostawał pustkę.
 *
 * Test sprawdza samą listę trafień w kod modelu, nie całą pulę kandydatów: przy dwóch kartach
 * w bazie indeks tekstowy znajduje wszystko i nie odróżniłby stanu przed poprawką od stanu po.
 */
final class ProductAiSearchSupplierCodeTest extends TestCase
{
    use RefreshDatabase;

    /** @return Collection<int, Product> */
    private function byModelCode(string $query): Collection
    {
        $method = new ReflectionMethod(ProductAiSearchService::class, 'retrieveByModelCode');

        return $method->invoke($this->app->make(ProductAiSearchService::class), $query, 40);
    }

    public function test_old_supplier_code_from_the_shop_table_is_a_model_code_hit(): void
    {
        $earmuff = $this->make(
            '7000103989',
            '3M™ PELTOR™ Nauszniki przeciwhałasowe, żółte, nagłowne, X2A',
            "Informacje handlowe\nKod: 1575818\nJednostka sprzedaży: szt.",
        );
        // karta tej samej marki i rodziny, z własnym dawnym numerem
        $other = $this->make(
            '7000103987',
            '3M™ PELTOR™ Nauszniki przeciwhałasowe, zielone, nagłowne, X1A',
            "Informacje handlowe\nKod: 1575813\nJednostka sprzedaży: szt.",
        );

        foreach (['1575818', '3M 1575818', 'Nauszniki 3M Peltor 1575818'] as $query) {
            $skus = $this->byModelCode($query)->pluck('sku')->all();

            $this->assertContains('7000103989', $skus, "zapytanie „{$query}” nie trafiło karty po dawnym numerze");
            $this->assertNotContains('7000103987', $skus, "zapytanie „{$query}” wciągnęło kartę z cudzym numerem");
        }

        // w drugą stronę tak samo — numer ma wskazywać jedną kartę, nie rodzinę
        $skus = $this->byModelCode('3M 1575813')->pluck('sku')->all();
        $this->assertContains('7000103987', $skus);
        $this->assertNotContains('7000103989', $skus);

        $this->assertNotSame($earmuff->id, $other->id);
    }

    public function test_short_code_does_not_reach_into_the_shop_table(): void
    {
        // „S1” stoi w tabelce dostawcy przy każdym bucie — krótkiego kodu tą drogą nie szukamy,
        // bo zwróciłby cały asortyment zamiast wyrobu.
        $this->make(
            'BUT-100',
            'Półbuty robocze ARSO',
            "Informacje handlowe\nKlasa: S1\nJednostka sprzedaży: para",
        );

        $skus = $this->byModelCode('Rękawice chemoodporne S1')->pluck('sku')->all();

        $this->assertNotContains('BUT-100', $skus, 'krótki kod z tabelki dostawcy wciągnął obcy wyrób');
    }

    private function make(string $sku, string $name, string $shopFields): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => '3M',
            'category' => 'Ochrona słuchu',
            'description' => 'Nauszniki przeciwhałasowe nagłowne.',
            'shop_fields_summary' => $shopFields,
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 3,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
