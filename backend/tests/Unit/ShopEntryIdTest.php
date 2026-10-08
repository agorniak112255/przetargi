<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductCodeMatch;
use App\Support\ShopEntryId;
use Tests\TestCase;

/**
 * Numer wpisu sklepu w adresie to nie kod wyrobu (etap 3, §1.3) — przypadki z audytów 08.10.2026: AJ GROUP (pros.pl),
 * SECURA (securabc.com), CEDERROTH (robartbhp), AJ GROUP 1044 (bhp-gabi).
 */
final class ShopEntryIdTest extends TestCase
{
    public function test_prestashop_entry_number_of_page_is_stripped_and_returned(): void
    {
        // karta 193 (model 102) opisana z płaszcza 1102: „102-” to wpis PrestaShop
        $coat = 'https://pros.pl/pl/odziez-wodoochronna-ostrzegawcza/102-plaszcz-model-1102.html';

        $this->assertSame('https://pros.pl/pl/odziez-wodoochronna-ostrzegawcza/plaszcz-model-1102.html', ShopEntryId::strip($coat));
        $this->assertSame(102, ShopEntryId::entryId($coat));
        $this->assertFalse(ProductCodeMatch::textCarries(ShopEntryId::strip($coat), '102'));
        $this->assertTrue(ProductCodeMatch::textCarries(ShopEntryId::strip($coat), '1102'));

        $kangaroo = 'https://pros.pl/pl/odziez-wodoochronna-standard/61-kurtka-kangurka-model-102.html';
        $this->assertTrue(ProductCodeMatch::textCarries(ShopEntryId::strip($kangaroo), '102'));
        $this->assertSame(61, ShopEntryId::entryId($kangaroo));
    }

    public function test_prestashop_combination_number_is_stripped_too(): void
    {
        // securabc.com: wpis 20, kombinacja 41 — numer półmaski „3000” zostaje
        $mask = 'https://www.securabc.com/pl/polmaska-wielokrotnego-uzytku-secura/20-41-secura-3000.html';

        $this->assertSame('https://www.securabc.com/pl/polmaska-wielokrotnego-uzytku-secura/secura-3000.html', ShopEntryId::strip($mask));
        $this->assertSame(20, ShopEntryId::entryId($mask));
    }

    public function test_product_card_pdf_takes_entry_number_from_parameter(): void
    {
        // karta 218 (104/1): obok PDF wpisu 240 dołączony PDF 241 (104/1 OC)
        $pdf = 'https://pros.pl/modules/x13producttopdf/pdf.php?id_product=241&id_product_attribute=3759';

        $this->assertSame(241, ShopEntryId::entryId($pdf));
        $this->assertSame('https://pros.pl/modules/x13producttopdf/pdf.php', ShopEntryId::strip($pdf));
        $this->assertSame(103, ShopEntryId::entryId('https://pros.pl/pdf.php?id_product=103'));
        $this->assertSame(7, ShopEntryId::entryId('https://sklep.example/index.php?controller=product&product_id=7'));
        $this->assertSame('https://sklep.example/index.php?controller=product&lang=pl', ShopEntryId::strip('https://sklep.example/index.php?controller=product&id=5&lang=pl'));
    }

    public function test_prestashop_image_folder_is_dropped_but_file_name_stays(): void
    {
        $image = 'https://pros.pl/3879-thickbox_default/plaszcz-model-1102.jpg';

        $this->assertSame('https://pros.pl/plaszcz-model-1102.jpg', ShopEntryId::strip($image));
        $this->assertNull(ShopEntryId::entryId($image));
        // numer na początku nazwy pliku bywa kodem wyrobu (cederroth.com) — zostaje
        $cederroth = 'https://www.cederroth.com/wp-content/uploads/2021/05/6943-sensitive-plasters-plasterrefill.jpg';
        $this->assertSame($cederroth, ShopEntryId::strip($cederroth));
        $this->assertNull(ShopEntryId::entryId($cederroth));
        $this->assertNull(ShopEntryId::entryId('https://www.cederroth.com/wp-content/uploads/51011003-v03.pdf'));
    }

    public function test_oscommerce_entry_number_is_stripped(): void
    {
        // karta 26596 (kompres 1893) ze stroną dozownika 51011006: „-p-1893” to wpis sklepu
        $url = 'https://robartbhp.pl/dozownik-cederroth-wound-care-dispenser-51011006-p-1893.html';

        $this->assertSame('https://robartbhp.pl/dozownik-cederroth-wound-care-dispenser-51011006.html', ShopEntryId::strip($url));
        $this->assertFalse(ProductCodeMatch::textCarries(ShopEntryId::strip($url), '1893'));
    }

    public function test_iai_and_idosell_entry_numbers_are_stripped(): void
    {
        // karta 287 (1044): bhp-gabi.pl „p4240,”
        $gabi = 'https://www.bhp-gabi.pl/p4240,kurtka-sztormowa-kangurka-wodochronna-1044-pros-aj-group.html';
        $this->assertSame('https://www.bhp-gabi.pl/kurtka-sztormowa-kangurka-wodochronna-1044-pros-aj-group.html', ShopEntryId::strip($gabi));
        $this->assertTrue(ProductCodeMatch::textCarries(ShopEntryId::strip($gabi), '1044'));
        $this->assertFalse(ProductCodeMatch::textCarries(ShopEntryId::strip($gabi), '4240'));

        $this->assertSame('https://sklep.example/kurtka-1044.html', ShopEntryId::strip('https://sklep.example/product-pol-12345-kurtka-1044.html'));
    }

    public function test_prestashop_rule_needs_html_page_and_short_combination_number(): void
    {
        // segment bez .html to nie strona PrestaShop: liczba na początku bywa kodem wyrobu (3M 4255, AJ 1011 R)
        $wooCommerce = 'https://sklep.example.pl/produkt/4255-polmaska-3m/';
        $this->assertSame($wooCommerce, ShopEntryId::strip($wooCommerce));
        $this->assertNull(ShopEntryId::entryId($wooCommerce));
        $this->assertTrue(ProductCodeMatch::textCarries(ShopEntryId::strip($wooCommerce), '4255'));
        $this->assertSame('https://ex.com/1011-r', ShopEntryId::strip('https://ex.com/1011-r'));
        $this->assertSame('https://ex.com/9172-265-okulary-uvex-super-g', ShopEntryId::strip('https://ex.com/9172-265-okulary-uvex-super-g'));

        // druga liczba od 3 cyfr zostaje — kod wyrobu (produkcja 08.10.2026: securabc filtr 200010, model 1031)
        $filter = 'https://www.securabc.com/gb/filters/34-200010-p3-a-particulate-filter-with-carbon-layer.html';
        $this->assertSame('https://www.securabc.com/gb/filters/200010-p3-a-particulate-filter-with-carbon-layer.html', ShopEntryId::strip($filter));
        $this->assertSame(34, ShopEntryId::entryId($filter));
        $this->assertTrue(ProductCodeMatch::textCarries(ShopEntryId::strip('https://pros.pl/251-1031-oc-kurtka.html'), '1031'));
        $this->assertSame(251, ShopEntryId::entryId('https://pros.pl/251-1031-oc-kurtka.html'));
        // kombinacja z 1–2 cyfr odpada razem z numerem wpisu
        $this->assertSame('https://xbhp.pl/szelki/punktowe-szelki-portwest-comfort-plus.html', ShopEntryId::strip('https://xbhp.pl/szelki/1227-3-punktowe-szelki-portwest-comfort-plus.html'));
        // .htm też jest stroną; numer w katalogu (nie w nazwie strony) zostaje
        $this->assertSame('https://sklep.example/kurtka-1044.htm', ShopEntryId::strip('https://sklep.example/77-kurtka-1044.htm'));
        $this->assertSame('https://sklep.example/1044-pros/kurtka.html', ShopEntryId::strip('https://sklep.example/1044-pros/kurtka.html'));
    }

    public function test_addresses_without_entry_numbers_stay_unchanged(): void
    {
        foreach ([
            'https://www.coba.com/pl/produkt/orthomat-standard',
            'https://www.cederroth.com/pl/products/uchwyt-nascienny-cederroth/',
            'https://icd.pl/polbuty-elektroizolacyjne-30-kv-antyamper.html',
            'https://www.mapa-pro.pl/pl/rekawice/ultranitril-492',
        ] as $url) {
            $this->assertSame($url, ShopEntryId::strip($url));
            $this->assertNull(ShopEntryId::entryId($url));
        }
        $this->assertSame('', ShopEntryId::strip(''));
        // długi kod liczbowy (8 cyfr) na początku nazwy strony to nie numer wpisu
        $this->assertSame('https://sklep.example/51011026-cederroth-station.html', ShopEntryId::strip('https://sklep.example/51011026-cederroth-station.html'));
    }
}
