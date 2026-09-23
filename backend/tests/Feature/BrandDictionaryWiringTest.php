<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BrandDictionaryEntry;
use App\Models\Product;
use App\Services\ProductAiSearchService;
use App\Support\CatalogManufacturerContext;
use App\Support\ProductModelFuzzy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Słownik producentów i marek wpięty w rozpoznawanie marki (ProductModelFuzzy::catalogBrands)
 * i w dopasowanie producenta (CatalogManufacturerContext::matchManufacturer).
 *
 * Najważniejszy niezmiennik: pusty słownik nie zmienia niczego. Zbiór marek z konfiguracji zasila
 * dziesięć miejsc wyszukiwarki i dopasowania przetargowego — słownik ma go wyłącznie uzupełniać.
 */
final class BrandDictionaryWiringTest extends TestCase
{
    use RefreshDatabase;

    private function fuzzy(): ProductModelFuzzy
    {
        return $this->app->make(ProductModelFuzzy::class);
    }

    private function context(): CatalogManufacturerContext
    {
        CatalogManufacturerContext::forgetCache();

        return $this->app->make(CatalogManufacturerContext::class);
    }

    private function catalog(): void
    {
        foreach ([['7000038211', '3M™ PELTOR™ Optime™ III Nauszniki przeciwhałasowe, Hi-Viz, nagłowne, H540A', '3M'],
            ['AEB020-0AY-900', 'Ochronniki słuchu na pałąku Sonis®2', 'JSP']] as [$sku, $name, $manufacturer]) {
            Product::query()->create([
                'sku' => $sku,
                'name' => $name,
                'manufacturer' => $manufacturer,
                'catalog_price_net' => 50,
                'purchase_price' => 30,
                'stock' => 3,
            ]);
        }
    }

    public function test_empty_dictionary_changes_nothing(): void
    {
        $this->catalog();

        // stan sprzed słownika — łącznie z tym, co jest błędem, a naprawi dopiero wpis w słowniku
        $this->assertSame(['3m'], $this->fuzzy()->catalogBrands('Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna'));
        $this->assertSame([], $this->fuzzy()->catalogBrands('Nauszniki Peltor Optime III'));
        $this->assertSame(['kask'], $this->fuzzy()->catalogBrands('Kask ochronny'));
        $this->assertNull($this->context()->matchManufacturer('peltor'));
        $this->assertSame('3M', $this->context()->matchManufacturer('3M'));
    }

    public function test_brand_from_the_dictionary_is_recognised_and_brings_its_producer(): void
    {
        $this->catalog();
        BrandDictionaryEntry::query()->create(['term' => 'Peltor', 'kind' => BrandDictionaryEntry::KIND_BRAND, 'manufacturer' => '3M']);

        // producent po marce — karty nazywają producenta, nie podmarkę
        $this->assertSame(['peltor', '3m'], $this->fuzzy()->catalogBrands('Nauszniki Peltor Optime III'));
        $this->assertSame('3M', $this->context()->matchManufacturer('peltor'));
        $this->assertSame('3M', $this->context()->matchManufacturer('PELTOR'));

        // „3M Optime III H540A” nie ma w nazwie słowa „Peltor”, a to jest Peltor — bramka marki ma ją przepuścić
        $optime = Product::query()->where('sku', '7000038211')->firstOrFail();
        $jsp = Product::query()->where('sku', 'AEB020-0AY-900')->firstOrFail();
        $brands = $this->fuzzy()->catalogBrands('Nauszniki Peltor Optime III');
        $this->assertTrue($this->fuzzy()->matchesCatalogBrand($optime, $brands));
        $this->assertFalse($this->fuzzy()->matchesCatalogBrand($jsp, $brands));
    }

    /**
     * Pułapka z kolejności wdrożenia: marka rozpoznana w zapytaniu, której dopasowanie producenta
     * nie umie przetłumaczyć, jest oznaczana jako „spoza katalogu” — a to zeruje producenta i model
     * w intencji i wyświetla handlowcowi „Marki PELTOR nie ma w katalogu”. Gorzej niż bez słownika.
     */
    public function test_dictionary_brand_is_not_reported_as_absent_from_the_catalog(): void
    {
        $this->catalog();
        BrandDictionaryEntry::query()->create(['term' => 'Peltor', 'kind' => BrandDictionaryEntry::KIND_BRAND, 'manufacturer' => '3M']);
        CatalogManufacturerContext::forgetCache();

        $service = $this->app->make(ProductAiSearchService::class);
        $localIntent = new ReflectionMethod(ProductAiSearchService::class, 'localIntent');
        $enrich = new ReflectionMethod(ProductAiSearchService::class, 'enrichIntentManufacturers');

        $query = 'Nauszniki Peltor Optime III';
        $intent = $enrich->invoke($service, $localIntent->invoke($service, $query), $query);

        $this->assertFalse($intent['manufacturer_absent_in_catalog']);
        $this->assertSame('3M', $intent['manufacturer']);
    }

    public function test_exclusion_and_switched_off_producer_are_not_brands_in_a_query(): void
    {
        $this->catalog();

        // „kask” wpada dziś do zbioru marek z kluczy konfiguracji domen
        BrandDictionaryEntry::query()->create(['term' => 'kask', 'kind' => BrandDictionaryEntry::KIND_EXCLUSION]);
        $this->assertSame([], $this->fuzzy()->catalogBrands('Kask ochronny'));

        // producent z wyłączonym rozpoznawaniem przestaje być wyłuskiwany, choć zostaje producentem w katalogu
        BrandDictionaryEntry::query()->create(['term' => '3M', 'kind' => BrandDictionaryEntry::KIND_PRODUCER, 'detect_in_query' => false]);
        $this->assertSame([], $this->fuzzy()->catalogBrands('Nauszniki 3M Peltor X2'));
        $this->assertSame('3M', $this->context()->matchManufacturer('3M'));
    }

    public function test_producer_from_a_fresh_price_list_is_known_at_once(): void
    {
        $this->catalog();
        $context = $this->context();
        $this->assertNull($context->matchManufacturer('Ardon'));

        // lista producentów trzymała się godzinę — producent z nowego cennika był przez ten czas „spoza katalogu”
        Product::query()->create([
            'sku' => 'ARD-1',
            'name' => 'Trzewiki ARDON',
            'manufacturer' => 'ARDON',
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 3,
        ]);

        $this->assertSame('ARDON', $this->app->make(CatalogManufacturerContext::class)->matchManufacturer('Ardon'));
    }

    public function test_edit_in_the_panel_takes_effect_without_restart(): void
    {
        $this->catalog();
        $fuzzy = $this->fuzzy();
        $this->assertSame([], $fuzzy->catalogBrands('Nauszniki Peltor Optime III'));

        $entry = BrandDictionaryEntry::query()->create(['term' => 'Peltor', 'kind' => BrandDictionaryEntry::KIND_BRAND, 'manufacturer' => '3M']);
        $this->assertSame(['peltor', '3m'], $fuzzy->catalogBrands('Nauszniki Peltor Optime III'));

        $entry->delete();
        $this->assertSame([], $fuzzy->catalogBrands('Nauszniki Peltor Optime III'));
    }
}
