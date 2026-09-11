<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductDescriptionText;
use Tests\TestCase;

final class ProductDescriptionTextTest extends TestCase
{
    public function test_strips_html_and_css_wall(): void
    {
        $raw = '<div class="product-description" style="white-space:nowrap;width:2400px">'
            .'<p>Rękawice nitrylowe do montażu.</p>'
            .'<style>.x{display:block}</style>'
            .'<ul><li>SKU: 1024</li></ul>'
            .'</div>';

        $plain = ProductDescriptionText::plain($raw);

        $this->assertStringContainsString('Rękawice nitrylowe do montażu.', $plain);
        $this->assertStringContainsString('SKU: 1024', $plain);
        $this->assertStringNotContainsString('<div', $plain);
        $this->assertStringNotContainsString('white-space:nowrap', $plain);
    }

    public function test_cuts_appended_spec_dump(): void
    {
        $plain = ProductDescriptionText::plain(
            "Rękawice Camapren 720 z polichloroprenu.\n\nSpecyfikacja:\n- ukryte w opisie"
        );

        $this->assertSame('Rękawice Camapren 720 z polichloroprenu.', $plain);
    }

    public function test_splits_long_blob_into_paragraphs(): void
    {
        $blob = 'Pierwsze zdanie opisuje przeznaczenie rękawic do montażu w suchych warunkach. '
            .'Drugie zdanie mówi o wkładce z poliestru i powłoce nitrylowej na dłoni. '
            .'Trzecie zdanie wyjaśnia, że mankiet ze ściągaczem utrzymuje rękawicę na miejscu. '
            .'Czwarte zdanie podaje zgodność z EN 388 przy codziennej pracy. '
            .'Piąte zdanie podkreśla chwyt i odporność na ścieranie w zakładzie. '
            .'Szóste zdanie wskazuje zastosowanie przy kompletacji i pracach precyzyjnych.';

        $paras = ProductDescriptionText::paragraphs($blob);

        $this->assertGreaterThanOrEqual(2, count($paras));
        $this->assertStringContainsString('przeznaczenie', $paras[0]);
    }

    public function test_drops_spec_sentences_copied_from_description(): void
    {
        $prose = 'Rękawice nitrylowe Ansell do montażu w warunkach suchych. Trwała powłoka zwiększa chwyt.';
        $items = ProductDescriptionText::dropDuplicatedListItems([
            'SKU: 1024',
            'Rękawice nitrylowe Ansell do montażu w warunkach suchych.',
        ], $prose);

        $this->assertSame(['SKU: 1024'], $items);
    }
}
