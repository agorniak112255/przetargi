<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InquiryLinks;
use App\Support\InquiryQueryText;
use PHPUnit\Framework\TestCase;

/**
 * Adresy z prawdziwych zapytań: #69 (Protekt ROLEX 5), #62 (MAVIBO, inna kombinacja),
 * #64 (Outlook: „Nazwa<https://…>”), #65 (Allegro).
 */
final class InquiryLinksTest extends TestCase
{
    public function test_links_are_read_from_plain_text_and_from_outlook_brackets(): void
    {
        $text = 'proszę mi wycenić urządzenia samohamowne jak w złączniku lub w linku '
            .'https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356.'."\n"
            .'Rękawice RS SPLIT KEV - Supon Rzeszów<https://www.supon.rzeszow.pl/rekawice-spawalnicze/3880-rekawice.html#/71-rozmiar_rekawic-10>  w r. 1';

        $this->assertSame([
            // kropka kończy zdanie, nie adres
            'https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356',
            'https://www.supon.rzeszow.pl/rekawice-spawalnicze/3880-rekawice.html#/71-rozmiar_rekawic-10',
        ], InquiryLinks::extract($text));
    }

    public function test_key_ignores_scheme_www_fragment_tracking_trailing_slash_and_case(): void
    {
        $key = InquiryLinks::key('https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356');

        foreach ([
            'http://www.protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356/',
            'https://PROTEKT.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356#opis',
            'https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356?utm_source=newsletter&gclid=x',
        ] as $same) {
            $this->assertSame($key, InquiryLinks::key($same), $same);
        }

        // inny numer wyrobu to inna karta — ROLEX 1 ma ten sam opis w adresie
        $this->assertNotSame($key, InquiryLinks::key('https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p1700~c5356'));
        // parametr, który wybiera stronę, zostaje
        $this->assertNotSame(
            InquiryLinks::key('https://sklep.pl/index.php?id_product=12'),
            InquiryLinks::key('https://sklep.pl/index.php?id_product=13'),
        );
        $this->assertSame(
            InquiryLinks::key('https://sklep.pl/index.php?id_product=12&controller=product'),
            InquiryLinks::key('https://sklep.pl/index.php?controller=product&id_product=12'),
        );
        $this->assertNull(InquiryLinks::key('https://localhost/produkt'));
    }

    public function test_shop_product_key_ignores_the_combination_and_the_category(): void
    {
        $sent = InquiryLinks::shopProductKey('https://mavibo.pl/bluzy/138-2702-geffer-620-61920.html#/3-rozmiar-l/34-kolor-26');

        $this->assertSame(['host' => 'mavibo.pl', 'id' => '138', 'name' => 'geffer-620-61920'], $sent);
        $this->assertSame($sent, InquiryLinks::shopProductKey('https://mavibo.pl/bluzy/138-2740-geffer-620-61920.html'));
        $this->assertSame($sent, InquiryLinks::shopProductKey('https://www.mavibo.pl/138-geffer-620-61920.html'));
        // inny wyrób o tej samej nazwie w adresie albo inny sklep — nie ten sam
        $this->assertNotSame($sent, InquiryLinks::shopProductKey('https://mavibo.pl/bluzy/139-2702-geffer-620-61920.html'));
        $this->assertNotSame($sent, InquiryLinks::shopProductKey('https://sklep.pl/bluzy/138-2702-geffer-620-61920.html'));
        // adres bez numeru wyrobu przed nazwą nie ma tej budowy
        $this->assertNull(InquiryLinks::shopProductKey('https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356'));
    }

    public function test_variant_from_the_anchor_is_found_only_as_a_whole_value(): void
    {
        $options = InquiryLinks::fragmentOptions('https://mavibo.pl/bluzy/138-2702-geffer-620-61920.html#/3-rozmiar-l/34-kolor-26');

        $this->assertSame([['group' => 'rozmiar', 'value' => 'l'], ['group' => 'kolor', 'value' => '26']], $options);
        $this->assertSame('kolor 26', InquiryLinks::variantNamedIn('GEFFER 620 61920, kolor 26', $options));
        // „26/70” to inny kolor, a „L” w nazwie nie stoi przy słowie „rozmiar”
        $this->assertNull(InquiryLinks::variantNamedIn('GEFFER 620 61920, kolor 26/70', $options));
        $this->assertNull(InquiryLinks::variantNamedIn('GEFFER 620 61920 L, kolor 20', $options));
        $this->assertSame(
            'kolor 20/70',
            InquiryLinks::variantNamedIn('GEFFER 620 61920, kolor 20/70', InquiryLinks::fragmentOptions('https://mavibo.pl/138-geffer.html#/34-kolor-20-70')),
        );
        $this->assertSame([], InquiryLinks::fragmentOptions('https://protekt.pl/urzadzenie~p8511~c5356#opis'));
    }

    public function test_slug_words_drop_ids_and_keep_codes(): void
    {
        $this->assertSame(
            'urzadzenie samohamowne do pracy w pionie',
            InquiryLinks::slugWords('https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356'),
        );
        $this->assertSame(
            'geffer 620-61920',
            InquiryLinks::slugWords('https://mavibo.pl/bluzy/138-2702-geffer-620-61920.html#/3-rozmiar-l/34-kolor-26'),
        );
        // numer oferty Allegro nie jest kodem wyrobu
        $this->assertSame(
            'spodnie do pasa z polipropylenu sfi r 2xl',
            InquiryLinks::slugWords('https://allegro.pl/oferta/spodnie-do-pasa-z-polipropylenu-sfi-r-2xl-15914130007'),
        );
        $this->assertSame('alphatec 23-202', InquiryLinks::slugWords('https://www.ansell.com/pl/pl/products/alphatec-23-202'));
        // same cyfry i strona główna niczego nie nazywają
        $this->assertSame('', InquiryLinks::slugWords('https://sklep.pl/produkt/123456'));
        $this->assertSame('', InquiryLinks::slugWords('https://www.supon.rzeszow.pl/'));
    }

    public function test_catalog_phrase_never_carries_the_address(): void
    {
        $this->assertSame(
            'proszę mi wycenić urządzenia samohamowne jak w złączniku lub w linku',
            InquiryQueryText::forCatalog('proszę mi wycenić urządzenia samohamowne jak w złączniku lub w linku https://protekt.pl/urzadzenie-samohamowne-do-pracy-w-pionie~p8511~c5356'),
        );
        $this->assertSame(
            'Rękawice spawalnicze RS SPLIT KEV - Supon Rzeszów w r. 11',
            InquiryQueryText::forCatalog('Rękawice spawalnicze RS SPLIT KEV - Supon Rzeszów<https://www.supon.rzeszow.pl/3880-rekawice.html#/71-rozmiar_rekawic-10>  w r. 11'),
        );
    }
}
