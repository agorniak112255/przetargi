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

    public function test_keeps_specification_and_cuts_pictogram_legend(): void
    {
        $plain = ProductDescriptionText::plain(
            "Rękawice Camapren 720 z polichloroprenu.\n\nSpecyfikacja:\n- ukryte w opisie\n\n"
            ."Piktogramy\nP / HRO — nie dotyczy tego modelu"
        );

        $this->assertStringContainsString('Specyfikacja', $plain);
        $this->assertStringContainsString('ukryte w opisie', $plain);
        $this->assertStringNotContainsString('Piktogramy', $plain);
        $this->assertStringNotContainsString('HRO', $plain);
    }

    public function test_keeps_argon_card_and_strips_shop_chrome(): void
    {
        $plain = ProductDescriptionText::plain(
            "ARTRABiałe półbuty robocze S2. zoom_out_map chevron_left −20% "
            ."Czas wysyłki od 5 do 8 dni roboczych. Indywidualna wycena dla firm.\n\n"
            ."PÓŁBUTY ROBOCZE ARGON 8229 1010 S2 ARTRA to obuwie bezpieczne klasy S2.\n\n"
            ."Specyfikacja:\n"
            ."- stalowy podnosek LIBERYUM\n"
            ."- BRAK WKŁADKI ANTYPRZEBICIOWEJ\n\n"
            ."Cechy produktu:\n- Cholewka PURYA SKINYUM\n\n"
            ."Piktogramy\nP HRO — legenda wszystkich klas\n"
            ."BUTY ROBOCZE PÓŁBUTY ARICA 6207 1010 S2 227,19 zł"
        );

        $this->assertStringContainsString('ARTRA Białe', $plain);
        $this->assertStringContainsString('ARGON 8229', $plain);
        $this->assertStringContainsString('LIBERYUM', $plain);
        $this->assertStringContainsString('BRAK WKŁADKI ANTYPRZEBICIOWEJ', $plain);
        $this->assertStringContainsString('SKINYUM', $plain);
        $this->assertStringNotContainsString('zoom_out_map', $plain);
        $this->assertStringNotContainsString('Piktogramy', $plain);
        $this->assertStringNotContainsString('ARICA', $plain);
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
