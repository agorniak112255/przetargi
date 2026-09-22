<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductSearchIdentity;
use ReflectionClass;
use Tests\TestCase;

/**
 * Produkcja 22.09.2026 (cennik ARTRA): wzbogacanie brało strony sklepów opisujące ten sam model
 * obuwia w innej klasie — sandał O1 bez podnoska jako karta S1 P, półbut S3L jako karta S1 PL,
 * stronę z normą S2 CI jako kartę O1 FO. Model i kod koloru się zgadzały, więc stare bramki przepuszczały.
 */
final class ProductSearchIdentityFootwearVariantTest extends TestCase
{
    private const NATARE_O1 = 'https://natare.pl/sandaly-robocze-artra/7717-buty-robocze-sandaly-arcasio-732-616560-o1-fo-esd-artra.html';

    private const ARTRA_S3L = 'https://artra.pl/products/3815338-aryel-320-618080-s3l-esd?srsltid=AfmBOop4Da';

    private const EMPIK_O1 = 'https://www.empik.com/buty-robocze-sandaly-armen-900-6060-o1-fo-artra-41-inna-inny,p1567230006,dom-i-ogrod-p';

    public function test_shop_page_of_o1_variant_is_not_the_s1p_card(): void
    {
        $id = new ProductSearchIdentity;
        $card = $this->footwear('ARCASIO 732 616560 S1 P ESD');
        $title = 'Buty robocze sandały ARCASIO 732 616560 O1 FO ESD ARTRA';
        // sklep pokazuje obok także nasz wariant — tego nie wolno uznać za kartę
        $text = "Sandały ARCASIO 732 616560 O1 FO ESD bez podnoska.\nZobacz też: ARCASIO 732 616560 S1 P ESD ARTRA";

        $this->assertTrue($id->pageNamesAnotherFootwearVariant(self::NATARE_O1, $title, $text, $card));
        $this->assertTrue($id->pageNamesAnotherFootwearVariant(self::NATARE_O1, '', '', $card));
        $this->assertFalse($id->pageHasSkuOrNameAndManufacturer(self::NATARE_O1, $title, $text, $card));
        $this->assertFalse($id->isConfirmedProductCard(self::NATARE_O1, $title, $text, $card));
    }

    public function test_artra_page_of_s3l_variant_is_not_the_s1pl_card(): void
    {
        $id = new ProductSearchIdentity;
        $card = $this->footwear('ARYEL 320 Air 618080 S1 PL ESD');

        $this->assertTrue($id->pageNamesAnotherFootwearVariant(self::ARTRA_S3L, '', '', $card));
        $this->assertFalse($id->pageHasSkuOrNameAndManufacturer(
            self::ARTRA_S3L,
            'ARYEL 320 618080 S3L ESD',
            'ARTRA ARYEL 320 Air 618080 S1 PL ESD',
            $card
        ));
    }

    public function test_page_with_foreign_class_only_in_norm_record_is_rejected(): void
    {
        $id = new ProductSearchIdentity;
        $card = $this->footwear('ARMEN 900 6060 O1 FO');
        $text = "Buty robocze sandały ARMEN 900 6060 O1 FO ARTRA\nProducent: ARTRA\nNorma: EN ISO 20345:2011 S2 CI SRC";

        $this->assertTrue($id->pageNamesAnotherFootwearVariant(self::EMPIK_O1, '', $text, $card));
        $this->assertFalse($id->pageHasSkuOrNameAndManufacturer(self::EMPIK_O1, '', $text, $card));
        $this->assertFalse($id->isConfirmedProductCard(self::EMPIK_O1, '', $text, $card));
        // ten sam adres bez sprzecznej normy to zwykła karta naszego wariantu
        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            self::EMPIK_O1,
            '',
            'Norma: EN ISO 20347:2012 O1 FO SRC',
            $card
        ));
    }

    public function test_same_class_base_written_differently_is_accepted(): void
    {
        $id = new ProductSearchIdentity;

        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/p/aryel-320-618080-s1p-esd',
            'ARYEL 320 618080 S1P ESD',
            'Norma: EN ISO 20345:2011 S1P SRC',
            $this->footwear('ARYEL 320 Air 618080 S1 PL ESD')
        ));
        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/p/arisaka-333-631460-s3',
            'Półbuty ARISAKA 333 S3',
            'EN ISO 20345:2011 S3 SRC',
            $this->footwear('ARISAKA 333 631460 S3S')
        ));
        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/p/arcasio-732-616560-s1-p-esd',
            '',
            '',
            $this->footwear('ARCASIO 732 616560 S1 P ESD')
        ));
    }

    public function test_title_listing_several_classes_including_ours_is_accepted(): void
    {
        $id = new ProductSearchIdentity;

        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/p/sandaly-arcasio-732',
            'Sandały ARCASIO 732 — wersje O1 FO / S1 P',
            '',
            $this->footwear('ARCASIO 732 616560 S1 P')
        ));
    }

    public function test_missing_marker_on_page_is_not_evidence_but_extra_marker_is(): void
    {
        $id = new ProductSearchIdentity;
        $ci = $this->footwear('ARMEN 900 6060 O2 CI FO');

        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/p/armen-900-6060-o2-fo',
            'ARMEN 900 6060 O2 FO',
            '',
            $ci
        ));
        $this->assertTrue($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/p/armen-900-6060-o1-fo-esd',
            '',
            '',
            $this->footwear('ARMEN 900 6060 O1 FO')
        ));
    }

    public function test_card_without_footwear_class_is_never_rejected(): void
    {
        $id = new ProductSearchIdentity;
        $glove = new Product([
            'sku' => '11-840',
            'name' => 'Rękawica HyFlex 11-840',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
        ]);
        $gloveS2 = new Product(['sku' => 'R-S2', 'name' => 'Rękawica robocza S2', 'manufacturer' => 'X']);

        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/p/hyflex-11-840-s3',
            'HyFlex 11-840 S3',
            'EN ISO 20345:2011 S3',
            $glove
        ));
        $this->assertFalse($id->pageNamesAnotherFootwearVariant('https://sklep.pl/p/rekawica-s3', 'S3', '', $gloveS2));
    }

    public function test_random_ids_in_query_string_do_not_carry_a_class(): void
    {
        $id = new ProductSearchIdentity;

        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://artra.pl/products/3815675-arox-733-641460-s1-pl-esd?srsltid=AfmBO-sb-o2_s3',
            '',
            '',
            $this->footwear('AROX 733 641460 S1 PL ESD')
        ));
    }

    public function test_family_of_norm_without_class_decides_only_when_unambiguous(): void
    {
        $id = new ProductSearchIdentity;
        $card = $this->footwear('ARCASIO 732 616560 S1 P ESD');
        $url = 'https://sklep.pl/p/arcasio-732-616560';

        $this->assertTrue($id->pageNamesAnotherFootwearVariant($url, '', 'Zgodność z normą EN ISO 20347:2012.', $card));
        $this->assertFalse($id->pageNamesAnotherFootwearVariant($url, '', 'Normy: EN ISO 20345, EN ISO 20347.', $card));
        $this->assertFalse($id->pageNamesAnotherFootwearVariant($url, '', 'Norma EN ISO 20345:2011.', $card));
    }

    public function test_manually_hinted_url_is_not_checked(): void
    {
        $id = new ProductSearchIdentity;
        $card = $this->footwear('ARCASIO 732 616560 S1 P ESD');
        $card->setAttribute('shop_source_url', self::NATARE_O1);

        $this->assertTrue($card->isHintedShopUrl(self::NATARE_O1));
        $this->assertFalse($id->pageNamesAnotherFootwearVariant(self::NATARE_O1, '', '', $card));
    }

    public function test_enrichment_keeps_only_the_card_of_our_class(): void
    {
        $card = $this->footwear('ARCASIO 732 616560 S1 P ESD');
        $right = 'https://artra.pl/products/3815400-arcasio-732-616560-s1-p-esd';

        $keep = (new ReflectionClass(app(ProductEnrichmentService::class)))->getMethod('keepConfirmedCardPages');
        $keep->setAccessible(true);
        $kept = $keep->invoke(app(ProductEnrichmentService::class), $card, [
            [
                'url' => self::NATARE_O1,
                'title' => 'Buty robocze sandały ARCASIO 732 616560 O1 FO ESD ARTRA',
                'text' => 'ARTRA ARCASIO 732 616560 O1 FO ESD. Zobacz też: ARCASIO 732 616560 S1 P ESD',
            ],
            ['url' => $right, 'title' => 'ARCASIO 732 616560 S1 P ESD', 'text' => 'ARTRA ARCASIO 732 616560 S1 P ESD'],
        ]);

        $this->assertSame([$right], array_column($kept, 'url'));
    }

    public function test_esd_on_page_does_not_reject_price_list_name_without_it(): void
    {
        $id = new ProductSearchIdentity;
        $card = new Product([
            'sku' => '6830840',
            'name' => 'Półbuty uvex 1 G2 S1 P SRC',
            'manufacturer' => 'UVEX',
            'category' => 'Obuwie',
        ]);

        // cennik pomija ESD, a nazwa za klasą ma tylko poślizg — to ten sam wyrób
        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://natare.pl/polbuty-uvex-1-g2-s1-p-src-esd-68308.html',
            'Półbuty uvex 1 G2 S1 P SRC ESD 68308',
            '',
            $card
        ));
    }

    public function test_artra_names_listing_supplementary_symbols_still_reject_extra_marker(): void
    {
        $id = new ProductSearchIdentity;

        // ARTRA ma pary „S3L ESD” / „S3L CI ESD” i „O2 FO” / „O2 CI FO”
        $this->assertTrue($id->pageNamesAnotherFootwearVariant(
            'https://artra.pl/products/1-ardeus-350-618080-s3l-ci-esd',
            '',
            '',
            $this->footwear('ARDEUS 350 618080 S3L ESD')
        ));
        $this->assertTrue($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/p/arles-947-6160-o2-ci-fo',
            'ARLES 947 6160 O2 CI FO',
            '',
            $this->footwear('ARLES 947 6160 O2 FO')
        ));
        // strona wypisująca oba warianty, w tym nasz bez dodatku, zostaje
        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/p/arles-947-6160',
            'ARLES 947 6160 O2 FO / O2 CI FO',
            '',
            $this->footwear('ARLES 947 6160 O2 FO')
        ));
    }

    public function test_brand_hi_tec_is_not_the_hi_marker(): void
    {
        $id = new ProductSearchIdentity;
        $card = new Product([
            'sku' => 'KELSO-S3',
            'name' => 'Trzewik Kelso S3 FO SRC',
            'manufacturer' => 'Hi-Tec',
            'category' => 'Obuwie',
        ]);

        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/trzewik-kelso-s3-fo-src-hi-tec',
            'Trzewik Kelso S3 FO SRC | Hi-Tec',
            '',
            $card
        ));
        // prawdziwe HI tuż za klasą nadal odróżnia wariant
        $this->assertTrue($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/trzewik-kelso-s3-hi-fo-src',
            '',
            '',
            $card
        ));
    }

    public function test_class_in_category_path_is_not_the_product_identity(): void
    {
        $id = new ProductSearchIdentity;
        $card = new Product([
            'sku' => 'P-100',
            'name' => 'Półbuty Protekt S1P SRC',
            'manufacturer' => 'Protekt',
            'category' => 'Obuwie',
        ]);

        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/obuwie-s3/polbuty-protekt-p-100',
            'Półbuty Protekt P-100',
            '',
            $card
        ));
        // klasa w slugu wyrobu dalej rozstrzyga
        $this->assertTrue($id->pageNamesAnotherFootwearVariant(
            'https://sklep.pl/obuwie-s1p/polbuty-protekt-p-100-s3',
            '',
            '',
            $card
        ));
    }

    public function test_recertified_2022_class_of_the_same_boot_is_accepted(): void
    {
        $id = new ProductSearchIdentity;
        $card = new Product([
            'sku' => '6502242',
            'name' => 'Trzewik uvex 2 S3 WR SRC',
            'manufacturer' => 'UVEX',
            'category' => 'Obuwie',
        ]);

        $this->assertFalse($id->pageNamesAnotherFootwearVariant(
            'https://uvex-safety.com/pl/uvex-2-trzewik-s7l-fo-sr',
            'uvex 2 trzewik S7L FO SR',
            'Norma: EN ISO 20345:2022 S7L FO SR',
            $card
        ));
        // bez WR na karcie S7 to inny wyrób niż S3
        $plain = new Product([
            'sku' => '6502243',
            'name' => 'Trzewik uvex 2 S3 SRC',
            'manufacturer' => 'UVEX',
            'category' => 'Obuwie',
        ]);
        $this->assertTrue($id->pageNamesAnotherFootwearVariant(
            'https://uvex-safety.com/pl/uvex-2-trzewik-s7l-fo-sr',
            '',
            '',
            $plain
        ));
    }

    /**
     * Zapis klasy karty i symbole za nią czytamy z samej nazwy. Sklejona z SKU „A-123” nazwa „Trzewik X S3”
     * dawała „S3 A” — wymaganie dodatkowe, które kazało odrzucać każdą stronę „S3 ESD”.
     */
    public function test_sku_after_the_name_does_not_add_designation_symbols(): void
    {
        $id = new ProductSearchIdentity;
        $card = new Product(['sku' => 'A-123', 'name' => 'Trzewik X S3', 'manufacturer' => 'X', 'category' => 'Obuwie']);

        $this->assertFalse($id->pageNamesAnotherFootwearVariant('https://sklep.pl/trzewik-x-s3-esd', 'Trzewik X S3 ESD', '', $card));
        $this->assertTrue($id->pageNamesAnotherFootwearVariant('https://sklep.pl/trzewik-x-s1', '', '', $card));
    }

    /** Nazwa bez klasy: klasa z SKU (bez symboli) odrzuca tylko stronę z inną bazą klasy. */
    public function test_class_from_sku_when_the_name_has_none_rejects_only_another_base(): void
    {
        $id = new ProductSearchIdentity;
        $card = new Product(['sku' => 'X S1 P', 'name' => 'Półbuty ochronne X', 'manufacturer' => 'X', 'category' => 'Obuwie']);

        $this->assertTrue($id->pageNamesAnotherFootwearVariant('https://sklep.pl/polbuty-x-s3', '', '', $card));
        $this->assertFalse($id->pageNamesAnotherFootwearVariant('https://sklep.pl/polbuty-x-s1-p-esd', 'Półbuty X S1 P ESD', '', $card));
        $this->assertFalse($id->pageNamesAnotherFootwearVariant('https://sklep.pl/polbuty-x-s1pl', '', '', $card));

        // kod z klasą doklejoną myślnikiem to nie klasa — karty nie sprawdzamy
        $coded = new Product(['sku' => 'X-S1-42', 'name' => 'Półbuty ochronne X', 'manufacturer' => 'X', 'category' => 'Obuwie']);
        $this->assertFalse($id->pageNamesAnotherFootwearVariant('https://sklep.pl/polbuty-x-s3', '', '', $coded));
    }

    private function footwear(string $sku): Product
    {
        return new Product([
            'sku' => $sku,
            'name' => $sku,
            'manufacturer' => 'ARTRA',
            'category' => 'Sklep - kategorie / Obuwie robocze i ochronne / Półbuty ochronne',
        ]);
    }
}
