<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\CatalogManufacturerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CatalogManufacturerContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CatalogManufacturerContext::forgetCache();
    }

    public function test_match_manufacturer_uses_catalog_name(): void
    {
        Product::query()->create([
            'sku' => 'U-1',
            'name' => 'Test',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
        ]);

        $ctx = new CatalogManufacturerContext;

        $this->assertSame('Ansell', $ctx->matchManufacturer('ANSELL'));
        $this->assertTrue($ctx->hasProductsForManufacturer('Ansell'));
        $this->assertNull($ctx->matchManufacturer('CERVA'));
        $this->assertFalse($ctx->hasProductsForManufacturer('CERVA'));
    }

    /**
     * Luźny etap przyjmuje tylko dłuższy zapis marki, który zaczyna się od nazwy producenta jako całych słów
     * („Mapa Professional” → MAPA, „DELTA” → Delta Plus). Zwykłe słowo, które zawiera krótką nazwę producenta,
     * albo słowo ze środka dłuższej nazwy producenta, marką nie jest (przegląd 25.09.2026: „PROSTY” → PROS).
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function looseSpellings(): iterable
    {
        yield 'słowo zawiera krótkiego producenta' => ['PROSTY', null];
        yield 'polskie słowo z producentem na początku' => ['MATERIAŁ', null];
        yield 'nazwa asortymentu z producentem na początku' => ['OKULARY', null];
        yield 'przymiotnik z producentem na początku' => ['KOMFORTOWE', null];
        yield 'słowo ze środka nazwy producenta' => ['SZELKI', null];
        yield 'drugie słowo nazwy producenta' => ['PLUS', null];
        yield 'nazwa producenta dokładnie' => ['pros', 'PROS'];
        yield 'dłuższy producent dokładnie' => ['PROS-EXTREME', 'PROS EXTREME'];
        yield 'marka z dopiskiem linii' => ['Mapa Professional', 'MAPA'];
        yield 'marka z dopiskiem wersalikami' => ['MAPA PROFESSIONAL', 'MAPA'];
        yield 'producent z dopiskiem kraju' => ['3M Polska', '3M'];
        yield 'zapis z myślnikiem i dopiskiem' => ['Delta-Plus Group', 'Delta Plus'];
        yield 'marka z myślnikiem' => ['MSA-Safety', 'MSA'];
        yield 'pierwsze słowo dłuższej nazwy producenta' => ['DELTA', 'Delta Plus'];
        yield 'pierwsze słowo nazwy z kropką' => ['BOXMET', 'BOXMET LTD.'];
        yield 'klucz z konfiguracji bez separatorów' => ['msasafety', 'MSA'];
        // compact() wycina litery spoza polskiego alfabetu: „Bollé” → „boll” trafiało w „bolle” tylko jako podciąg
        yield 'marka z akcentem, katalog bez' => ['Bollé', 'Bolle'];
        yield 'marka dopiero w drugim słowie zapisu' => ['OKULARY BOLLÉ', null];
        yield 'sam wyraz wersalikami z akcentem' => ['BOLLÉ', 'Bolle'];
        yield 'katalog z umlautem, zapytanie bez' => ['Sundstrom', 'Sundström'];
        yield 'pusta nazwa producenta niczego nie łapie' => ['kask', null];
    }

    #[DataProvider('looseSpellings')]
    public function test_loose_match_needs_manufacturer_as_leading_whole_words(string $guess, ?string $expected): void
    {
        foreach (['PROS', 'PROS EXTREME', 'MAT', 'Okula', 'KOMFORT', 'TOP SAFETY Szelki bezpieczeństwa', 'MAPA', '3M', 'Delta Plus', 'MSA', 'BOXMET LTD.', 'Bolle', 'Sundström', '.....'] as $i => $manufacturer) {
            Product::query()->create([
                'sku' => 'M-'.$i,
                'name' => 'Karta '.$i,
                'manufacturer' => $manufacturer,
                'catalog_price_net' => 1,
                'purchase_price' => 1,
                'stock' => 1,
            ]);
        }

        $this->assertSame($expected, (new CatalogManufacturerContext)->matchManufacturer($guess));
    }
}
