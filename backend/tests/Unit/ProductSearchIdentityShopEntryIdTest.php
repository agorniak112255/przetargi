<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductSearchIdentity;
use Tests\TestCase;

/**
 * Numer wpisu sklepu w adresie to nie kod wyrobu (etap 3 opisów z cenników, §1.3; audyty 08.10.2026). Karta 193
 * (kurtka kangurka AJ GROUP, model 102) dostała opis i zdjęcie płaszcza 1102 z pros.pl „/102-plaszcz-model-1102.html”
 * — „102” to numer wpisu PrestaShop. Karta 26596 (kompres CEDERROTH 1893) dostała stronę dozownika 51011006
 * z robartbhp „…-p-1893.html” — numer wpisu osCommerce.
 */
final class ProductSearchIdentityShopEntryIdTest extends TestCase
{
    private const COAT_URL = 'https://pros.pl/pl/odziez-wodoochronna-ostrzegawcza/102-plaszcz-model-1102.html';

    private const COAT_TITLE = 'Płaszcz model 1102';

    private const COAT_TEXT = "Płaszcz wodoochronny ostrzegawczy model 1102\nSKU: 1102-00025-48/XS\n1102\nPROS\n"
        .'Płaszcz z dwoma rzędami taśmy odblaskowej, zapinany na napy na całej długości. EN ISO 20471, EN 343.';

    private const KANGAROO_URL = 'https://pros.pl/pl/odziez-wodoochronna-standard/61-kurtka-kangurka-model-102.html';

    private const KANGAROO_TITLE = 'Kurtka kangurka model 102';

    private const KANGAROO_TEXT = "Kurtka wodoochronna kangurka model 102\nSKU: 102-00025-48/XS\n102\nPROS\n"
        .'Kurtka wkładana przez głowę, zapinana pod szyją na dwie patki z napami. EN ISO 13688, EN 343.';

    private const DISPENSER_URL = 'https://robartbhp.pl/dozownik-cederroth-wound-care-dispenser-51011006-p-1893.html';

    private const DISPENSER_TITLE = 'Dozownik Cederroth Wound Care Dispenser 51011006';

    private const DISPENSER_TEXT = 'Dozownik Cederroth Wound Care Dispenser 51011006 do plastrów i opatrunków. Producent: Cederroth. '
        .'Kompres metalizowany, plastry, bandaże — wyposażenie stacji pierwszej pomocy.';

    public function test_prestashop_entry_number_does_not_confirm_short_model_code(): void
    {
        $id = new ProductSearchIdentity;
        $kangaroo = $this->kangaroo();

        $this->assertFalse(
            $id->pageHasSkuOrNameAndManufacturer(self::COAT_URL, self::COAT_TITLE, self::COAT_TEXT, $kangaroo),
            '„102-” to numer wpisu pros.pl, strona płaszcza 1102 nie jest kartą modelu 102'
        );
        $this->assertFalse($id->isConfirmedProductCard(self::COAT_URL, self::COAT_TITLE, self::COAT_TEXT, $kangaroo));
        $this->assertFalse($id->urlOrTitleCarriesShopModelNumber(self::COAT_URL, self::COAT_TITLE, $kangaroo));
        $this->assertFalse($id->urlOrTitleCarriesCodeFamily(self::COAT_URL, self::COAT_TITLE, $kangaroo));
        // zbitkę z adresem składają też wywołujący spoza klasy (karty w puli, wyniki wyszukiwarki)
        $this->assertFalse($id->hayHasProductCode(mb_strtolower(self::COAT_URL.' '.self::COAT_TITLE), $kangaroo));
    }

    public function test_own_model_page_with_other_entry_number_still_passes(): void
    {
        $id = new ProductSearchIdentity;
        $kangaroo = $this->kangaroo();

        $this->assertTrue($id->pageHasSkuOrNameAndManufacturer(self::KANGAROO_URL, self::KANGAROO_TITLE, self::KANGAROO_TEXT, $kangaroo));
        $this->assertTrue($id->isConfirmedProductCard(self::KANGAROO_URL, self::KANGAROO_TITLE, self::KANGAROO_TEXT, $kangaroo));
        $this->assertTrue($id->hayHasProductCode(mb_strtolower(self::KANGAROO_URL), $kangaroo));
    }

    public function test_prestashop_image_folder_number_does_not_confirm_image(): void
    {
        $id = new ProductSearchIdentity;
        $kangaroo = $this->kangaroo();

        $this->assertFalse($id->imageUrlMentionsProduct('https://pros.pl/3879-thickbox_default/plaszcz-model-1102.jpg', $kangaroo));
        $this->assertFalse($id->imageUrlMentionsProduct('https://pros.pl/102-thickbox_default/plaszcz-model-1102.jpg', $kangaroo), 'katalog zdjęcia z numerem 102 to też numer wpisu');
    }

    public function test_oscommerce_entry_number_does_not_confirm_cederroth_ref(): void
    {
        $id = new ProductSearchIdentity;
        $compress = new Product([
            'sku' => '1893',
            'name' => 'Kompres metalizowany Cederroth, 20x40 cm',
            'manufacturer' => 'CEDERROTH',
        ]);

        $this->assertFalse($id->pageHasSkuOrNameAndManufacturer(self::DISPENSER_URL, self::DISPENSER_TITLE, self::DISPENSER_TEXT, $compress));
        $this->assertFalse($id->isConfirmedProductCard(self::DISPENSER_URL, self::DISPENSER_TITLE, self::DISPENSER_TEXT, $compress));
        $this->assertFalse($id->hayHasProductCode(mb_strtolower(self::DISPENSER_URL.' '.self::DISPENSER_TITLE), $compress));
        $this->assertFalse($id->hayMentionsProduct(self::DISPENSER_URL.' '.self::DISPENSER_TITLE, $compress));
        // katalog zdjęcia PrestaShop „1893-large_default” to numer zdjęcia sklepu, nie REF 1893
        $this->assertFalse($id->imageUrlMentionsProduct('https://sklep-bhp.example.pl/1893-large_default/dozownik-cederroth-51011006.jpg', $compress));
        $this->assertTrue($id->imageUrlMentionsProduct('https://sklep-bhp.example.pl/2210-large_default/kompres-metalizowany-cederroth-1893.jpg', $compress));
    }

    private function kangaroo(): Product
    {
        return new Product([
            'sku' => '102',
            'name' => 'Kurtka wodoochronna kangurka',
            'manufacturer' => 'AJ GROUP',
        ]);
    }
}
