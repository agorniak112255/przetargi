<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ManufacturerCatalogPdf;
use Tests\TestCase;

final class ManufacturerCatalogPdfTest extends TestCase
{
    private function catalogText(): string
    {
        return (string) file_get_contents(
            base_path('tests/Fixtures/enrichment/secura-katalog-fragment.txt')
        );
    }

    public function test_block_belongs_to_the_catalogue_number_it_was_found_at(): void
    {
        $catalog = app(ManufacturerCatalogPdf::class);
        $text = $this->catalogText();

        $filter = $catalog->blockFor($text, 'S56322S2');
        $halfMask = $catalog->blockFor($text, 'S56T0SM0');

        // filtr SECAIR 3000.02 P2 — opis i typowe zastosowanie klasy P2
        $this->assertStringContainsString('SECAIR 3000.02 P2', $filter);
        $this->assertStringContainsString('Typowe zastosowanie filtrów w klasie P2', $filter);
        $this->assertStringContainsString('S56322S2', $filter);

        // półmaska SECURA 3000 w rozmiarze M
        $this->assertStringContainsString('Półmaska SECURA 3000', $halfMask);
        $this->assertStringContainsString('rozmiar M', $halfMask);

        // żaden blok nie może nieść treści drugiego wyrobu
        $this->assertStringNotContainsString('Półmaska SECURA 3000', $filter);
        $this->assertStringNotContainsString('S56T0SM0', $filter);
        $this->assertStringNotContainsString('SECAIR', $halfMask);
        $this->assertStringNotContainsString('S56322S2', $halfMask);
    }

    public function test_block_stops_before_the_next_product_in_the_catalogue(): void
    {
        $text = $this->catalogText();
        $filter = app(ManufacturerCatalogPdf::class)->blockFor($text, 'S56322S2');

        // sąsiednie filtry z tej samej sekcji: P1 nad blokiem, P3 pod nim
        $this->assertStringNotContainsString('SECAIR 3000.01', $filter);
        $this->assertStringNotContainsString('SECAIR 3000.03', $filter);
        $this->assertStringNotContainsString('S56321S2', $filter);
        $this->assertStringNotContainsString('S56323S2', $filter);
    }

    public function test_unknown_code_has_no_block(): void
    {
        $catalog = app(ManufacturerCatalogPdf::class);
        $text = $this->catalogText();

        $this->assertSame('', $catalog->blockFor($text, 'XYZ123'));
        // kod, który jest tylko początkiem cudzego numeru katalogowego, to nie jest ten wyrób
        $this->assertSame('', $catalog->blockFor("S56T0SM0100 Inny wyrób\n", 'S56T0SM0'));
        // za krótki kod trafiłby w przypadkowy token
        $this->assertSame('', $catalog->blockFor($text, 'S56'));
    }

    public function test_catalogue_urls_come_from_config_for_the_brand(): void
    {
        $catalog = app(ManufacturerCatalogPdf::class);
        $url = 'https://www.securabc.com/img/cms/Katalog%202026%20PL_web.pdf';

        $secura = new Product(['manufacturer' => 'SECURA', 'sku' => 'S56322S2', 'name' => 'Filtr']);
        $ansell = new Product(['manufacturer' => 'Ansell', 'sku' => '11-840', 'name' => 'Rękawice']);

        $this->assertSame([$url], $catalog->catalogUrlsFor($secura));
        $this->assertSame([], $catalog->catalogUrlsFor($ansell));
        $this->assertTrue($catalog->isConfiguredCatalogUrl($url));
        $this->assertFalse($catalog->isConfiguredCatalogUrl('https://www.securabc.com/img/cms/inny.pdf'));
    }

    public function test_catalogue_is_matched_by_code_only_never_by_name(): void
    {
        $text = <<<'TXT'
        ▶ RĘKAWICE SPAWALNICZE
        Rękawice spawalnicze chronią dłonie przed odpryskami spawalniczymi.
        Nr kat. Nazwa
        T5311000 Rękawice spawalnicze dwoinowe
        ▶ RĘKAWICE ELEKTROIZOLACYJNE
        Rękawice elektroizolacyjne chronią przed napięciem do 1000 V.
        Nr kat. Nazwa
        T5312000 Rękawice elektroizolacyjne klasy 0
        TXT;

        $catalog = app(ManufacturerCatalogPdf::class);

        // nazwa wyrobu w katalogu nie wystarcza — bez zgodnego kodu nie ma opisu
        $this->assertSame('', $catalog->blockFor($text, 'T9999999'));
        $welding = $catalog->blockFor($text, 'T5311000');
        $this->assertStringContainsString('spawalnicze', $welding);
        $this->assertStringNotContainsString('elektroizolacyjne', $welding);
    }
}
