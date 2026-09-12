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

    public function test_strips_shopify_size_price_dump_and_keeps_bhp_facts(): void
    {
        $raw = "ARMEN 9003 6660 S1 ESD\n"
            ."EU 35 - 309 złEU 36 - 309 złEU 37 - 309 złEU 38 - 309 złEU 39 - 309 zł"
            ."EU 40 - 309 złEU 41 - 309 złEU 42 - 309 zł Wariant\n"
            ."Konstrukcja obuwia ARELAX zapewnia przestrzeń dla palców. "
            ."Podeszwa LYFTOR PU.2D z podnoskiem LIBERYUM.\n"
            ."Rozmiar EU Długość stopy --- --- **35** 21,8 **36** 22,4\n"
            ."### Jak dobrać rozmiar?\n"
            ."Jeśli obuwie nie będzie Państwu odpowiadać, mogą je Państwo zwrócić w ciągu 30 dni od otrzymania.\n"
            ."Natychmiast do wysyłki • Darmowa dostawa";

        $plain = ProductDescriptionText::plain($raw);

        $this->assertStringContainsString('ARELAX', $plain);
        $this->assertStringContainsString('LIBERYUM', $plain);
        $this->assertStringNotContainsString('309 zł', $plain);
        $this->assertStringNotContainsString('Wariant', $plain);
        $this->assertStringNotContainsString('Jak dobrać rozmiar', $plain);
        $this->assertStringNotContainsString('30 dni', $plain);
        $this->assertStringNotContainsString('Natychmiast do wysyłki', $plain);
    }

    public function test_strips_size_prices_without_eu_prefix_and_lowercase_wariant(): void
    {
        $plain = ProductDescriptionText::plain(
            '35 - 309 zł36 - 309 zł37 - 309 zł38 - 309 zł wariant '
            .'Półbuty S1 z podnoskiem LIBERYUM. 50 - 129 eur51 - 129 €'
        );

        $this->assertStringContainsString('LIBERYUM', $plain);
        $this->assertStringNotContainsString('309 zł', $plain);
        $this->assertStringNotContainsString('wariant', mb_strtolower($plain));
        $this->assertStringNotContainsString('129 eur', mb_strtolower($plain));
        $this->assertStringNotContainsString('129 €', $plain);
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

    public function test_cuts_glued_spec_table_dump_and_keeps_prose(): void
    {
        $plain = ProductDescriptionText::plain(
            "Adhesion Strength (Imperial)22 oz/in\n"
            ."Adhesion Strength (metric)24 N/100mm\n"
            ."Overall Width (Imperial)4 in, 12 in, 24 in, 36 in\n"
            ."Total Tape Thickness without Liner (Metric)424.2 mm\n\n"
            .'Suitable for both indoor and outdoor use, our thick backing and specially formulated '
            ."rubber adhesive provide a strong bond and clean removal to safeguard various surfaces."
        );

        $this->assertStringNotContainsString('Adhesion Strength', $plain);
        $this->assertStringNotContainsString('oz/in', $plain);
        $this->assertStringNotContainsString('424.2 mm', $plain);
        $this->assertStringStartsWith('Suitable for both indoor', $plain);
    }

    public function test_cuts_flattened_comparison_table_of_other_products(): void
    {
        $plain = ProductDescriptionText::plain(
            "Taśma ochronna do wymagających zastosowań.\n\n"
            .'--- --- --- --- Overall Width (Metric) 609.6 mm 76.2 mm, 50.8 mm, 25.4 mm '
            ."Product Color Tan Transparent\n"
        );

        $this->assertStringContainsString('Taśma ochronna', $plain);
        $this->assertStringNotContainsString('609.6 mm', $plain);
        $this->assertStringNotContainsString('Transparent', $plain);
    }

    public function test_cuts_size_table_with_unit_in_the_label(): void
    {
        $plain = ProductDescriptionText::plain(
            "Rękawice antyprzecięciowe do prac montażowych w suchym środowisku.\n"
            ."Obwód dłoni (mm)152 178 203 229 254 279 304\n"
            ."Długość dłoni (mm)160 171 182 192 204 215 226\n"
        );

        $this->assertStringContainsString('Rękawice antyprzecięciowe', $plain);
        $this->assertStringNotContainsString('Obwód dłoni', $plain);
        $this->assertStringNotContainsString('229', $plain);
    }

    public function test_keeps_standard_designations(): void
    {
        $plain = ProductDescriptionText::plain(
            "Odzież ostrzegawcza dla służb drogowych.\nEN ISO 13688\nEN ISO 20471\nISO 13997\n"
        );

        $this->assertStringContainsString('EN ISO 13688', $plain);
        $this->assertStringContainsString('EN ISO 20471', $plain);
        $this->assertStringContainsString('ISO 13997', $plain);
    }

    public function test_keeps_feature_bullets_that_are_not_table_rows(): void
    {
        $plain = ProductDescriptionText::plain(
            "Extremely cut-resistant gloves with grippy micro-cup nitrile coating\n"
            ."Ideal for high-risk jobs and handling sharp materials in dry conditions\n"
            ."Touchscreen compatibility for convenience\n"
            ."Kieszeń na telefon\n"
            ."Ochrona podbródka zwiększa komfort\n"
        );

        $this->assertStringContainsString('Extremely cut-resistant gloves', $plain);
        $this->assertStringContainsString('Touchscreen compatibility', $plain);
        $this->assertStringContainsString('Kieszeń na telefon', $plain);
        $this->assertStringContainsString('Ochrona podbródka', $plain);
    }

    public function test_keeps_sentence_that_merely_carries_a_unit(): void
    {
        $plain = ProductDescriptionText::plain(
            "Pielęgnacja można prać w pralce przemysłowej do 40 °C\n"
            ."Care washable in industrial machine up to 40 °C\n"
        );

        $this->assertStringContainsString('pralce przemysłowej do 40 °C', $plain);
        $this->assertStringContainsString('industrial machine up to 40 °C', $plain);
    }

    public function test_keeps_product_name_whose_model_code_looks_like_a_unit(): void
    {
        $plain = ProductDescriptionText::plain(
            "ARTRA Trzewiki bezpieczne ARUBA 941 6060 S3\nKangurka morska 3011\nTitan 850\n"
        );

        $this->assertStringContainsString('ARUBA 941 6060 S3', $plain);
        $this->assertStringContainsString('Kangurka morska 3011', $plain);
        $this->assertStringContainsString('Titan 850', $plain);
    }
}
