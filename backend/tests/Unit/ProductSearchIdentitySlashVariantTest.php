<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductSearchIdentity;
use Tests\TestCase;

/**
 * Produkcja 20.09.2026: zwykłe ubranie wodoochronne AJ GROUP 101/001 wzięło opis ze strony wariantu
 * 101/001/A (antystatyczne) i dostało normę EN 1149-5 oraz opis „do stref zagrożenia wybuchem”.
 * Kod bazowy był „znajdowany” wewnątrz kodu z przyrostkiem po ukośniku. W katalogu jest 85 takich par.
 */
final class ProductSearchIdentitySlashVariantTest extends TestCase
{
    private const A_URL = 'https://pros.pl/pl/odziez-wodoochronna-antystatyczna/83-ubranie-antystatyczne-model-101001a.html';

    private const A_TEXT = "Ubranie antystatyczne model 101/001/A złożone z kurtki ¾ oraz spodni ogrodniczek\n"
        ."SKU: 101/001/A-00025-48/XS\n101/001/A\nPROS\nOdzież wodoochronna antystatyczna\n"
        .'Ubranie przeznaczone do pracy w strefach zagrożenia wybuchem, spełnia wymagania normy EN 1149-5.';

    private const BASE_URL = 'https://pros.pl/pl/odziez-wodoochronna-standard/73-ubranie-model-101001.html';

    private const BASE_TEXT = "Ubranie model 101/001 złożone z kurtki ¾ oraz spodni ogrodniczek\n"
        ."SKU: 101/001-00025-48/XS\n101/001\nPROS\nOdzież wodoochronna standard\n"
        .'Ubranie wodoochronne skutecznie chroniące przed wiatrem i deszczem.';

    public function test_card_of_slash_variant_is_not_the_base_product(): void
    {
        $id = new ProductSearchIdentity;
        $base = $this->product('101/001', 'Ubranie wodoochronne [kurtka 3/4 i spodnie ogrodniczki] Standard');

        $this->assertFalse($id->isConfirmedProductCard(self::A_URL, 'Ubranie antystatyczne model 101/001/A', self::A_TEXT, $base));
        $this->assertFalse($id->pageHasSkuOrNameAndManufacturer(self::A_URL, 'Ubranie antystatyczne model 101/001/A', self::A_TEXT, $base));
    }

    public function test_own_cards_of_base_and_variant_still_pass(): void
    {
        $id = new ProductSearchIdentity;
        $base = $this->product('101/001', 'Ubranie wodoochronne [kurtka 3/4 i spodnie ogrodniczki] Standard');
        $variant = $this->product('101/001/A', 'Ubranie wodoochronne antystatyczne [kurtka 3/4 i spodnie ogrodniczki]');

        // własna karta niesie kod także z numerem referencyjnym: „101/001-00025-48/XS”
        $this->assertTrue($id->pageHasSkuOrNameAndManufacturer(self::BASE_URL, 'Ubranie model 101/001', self::BASE_TEXT, $base));
        $this->assertTrue($id->pageHasSkuOrNameAndManufacturer(self::A_URL, 'Ubranie antystatyczne model 101/001/A', self::A_TEXT, $variant));
    }

    public function test_page_listing_variant_next_to_base_code_is_still_the_base_card(): void
    {
        $id = new ProductSearchIdentity;
        $base = $this->product('101/001', 'Ubranie wodoochronne Standard');
        $text = self::BASE_TEXT."\nZobacz też wersję antystatyczną: model 101/001/A.";

        $this->assertTrue($id->pageHasSkuOrNameAndManufacturer(self::BASE_URL, 'Ubranie model 101/001', $text, $base));
    }

    public function test_numeric_and_lettered_base_codes_follow_the_same_rule(): void
    {
        $id = new ProductSearchIdentity;
        $apron = $this->product('333', 'Fartuch Rybacki Extreme');
        $wzUrl = 'https://pros.pl/pl/pros-extreme/247-fartuch-rybacki-model-333wz.html';
        $wzText = "Fartuch rybacki ze wzmocnieniem model 333/WZ\nSKU: 333/WZ-00011-UNI\n333/WZ\nPROS";
        $this->assertFalse($id->pageHasSkuOrNameAndManufacturer($wzUrl, 'Fartuch model 333/WZ', $wzText, $apron));
        $this->assertFalse($id->isConfirmedProductCard($wzUrl, 'Fartuch model 333/WZ', $wzText, $apron));

        $harness = $this->product('BW100', 'Szelki bezpieczeństwa BW100', 'PROTEKT');
        $kitUrl = 'https://protekt.com.pl/produkt/zestaw-bw100-az023';
        $kitText = "Zestaw BW100/AZ023 — szelki z amortyzatorem\nPROTEKT\nKod: BW100/AZ023";
        $this->assertFalse($id->pageHasSkuOrNameAndManufacturer($kitUrl, 'Zestaw BW100/AZ023', $kitText, $harness));
    }

    private function product(string $sku, string $name, string $manufacturer = 'PROS'): Product
    {
        return new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer]);
    }
}
