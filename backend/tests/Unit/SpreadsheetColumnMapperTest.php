<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SpreadsheetColumnMapper;
use PHPUnit\Framework\TestCase;

final class SpreadsheetColumnMapperTest extends TestCase
{
    public function test_maps_3m_new_list_and_net_not_date_or_ean(): void
    {
        $header = [
            'Wskaźnik zakupów w ciągu ostatnich 12 miesięcy',
            'Dywizja',
            'Profit Center',
            '3M numer magazynowy',
            'Numer katalogowy produktu',
            'Numer katalogowy Klienta',
            'Nazwa produktu',
            'Kategoria produktu Poziom 1',
            'Kategoria produktu Poziom 2',
            'Kategoria produktu Poziom 3',
            'Waluta',
            'Cena aktualna na dzień',
            'Aktualna cena cennikowa',
            'Aktualna cena po upustach',
            'Data obowiązywania nowej ceny',
            'Nowa cena cennikowa',
            'Nowa cena po upustach',
            'Procentowa zmiana ceny',
            'Cena za',
            'Kod EAN',
        ];
        $map = (new SpreadsheetColumnMapper)->mapLabels($header);

        $this->assertSame(4, $map['sku']);
        $this->assertSame(6, $map['name']);
        $this->assertSame(15, $map['catalog_price']);
        $this->assertSame(16, $map['purchase']);
        $this->assertNull($map['discount']);
        $this->assertSame(19, $map['ean']);
        $this->assertSame(10, $map['currency']);
    }

    public function test_maps_dupont_article_and_unit_price(): void
    {
        $header = [
            '',
            'Category/Type',
            'Reference',
            'Product Image',
            'Model Name and Description',
            'Article Number',
            'Size',
            'Quantity per box',
            'Minimum Order Quantity',
            'Price(€/pc.) for Min. of 5000€',
        ];
        $map = (new SpreadsheetColumnMapper)->mapLabels($header);

        $this->assertSame(5, $map['sku']);
        $this->assertSame(2, $map['model_key']);
        $this->assertSame(4, $map['name']);
        $this->assertSame(9, $map['catalog_price']);
        $this->assertSame(7, $map['pack_qty']);
    }

    public function test_maps_gvs_msrp_and_net_and_kask_dealer(): void
    {
        $gvs = (new SpreadsheetColumnMapper)->mapLabels([
            'Part Num', 'Part Description', 'Case Quantity', 'MSRP 2026', 'Price 26',
        ]);
        $this->assertSame(0, $gvs['sku']);
        $this->assertSame(3, $gvs['catalog_price']);
        $this->assertSame(4, $gvs['purchase']);

        $kask = (new SpreadsheetColumnMapper)->mapLabels([
            'MATERIAL TYPE', 'PRODUCT FAMILY', 'COLLECTION', '', '', '', '', '',
            'ERP CODE', 'MARKETING CODE', 'MODEL', 'DESCRIPTION', 'STANDARDS',
            'MTO', 'LIFECYCLE STATUS', 'MSRP', 'DEALER', 'MASTERBOX', 'TARIF CODE', 'EAN CODE',
        ]);
        $this->assertSame(8, $kask['sku']);
        $this->assertSame(15, $kask['catalog_price']);
        $this->assertSame(16, $kask['purchase']);
        $this->assertSame(19, $kask['ean']);
    }

    public function test_classifies_special_and_skip_sheets(): void
    {
        $m = new SpreadsheetColumnMapper;
        $this->assertSame('special', $m->classifySheet('Special Pricing plPL'));
        $this->assertSame('special', $m->classifySheet('EUA Prices'));
        $this->assertSame('skip', $m->classifySheet('Disclaimer'));
        $this->assertSame('skip', $m->classifySheet('Tarifs'));
        $this->assertSame('skip', $m->classifySheet('PDF-PRODUKTY WYGASZANE'));
        $this->assertSame('catalog', $m->classifySheet('StandardPrice plPL'));
    }

    public function test_maps_2026_manufacturer_headers(): void
    {
        $m = new SpreadsheetColumnMapper;

        $artra = $m->mapLabels(['lp.', 'kat.', 'artykuł', 'zdjęcie', 'typ', 'ochrony', 'podeszwa', 'metal free', 'kolekcja', 'rozm.', 'bez VAT', '* NCD z VAT']);
        $this->assertSame(2, $artra['sku']);
        $this->assertSame(10, $artra['catalog_price']);

        $coba = $m->mapLabels(['Numer produktu', 'Nazwa, kolor, wymiar produktu', 'Waga [kg]', "ExWorks'26", "Transport jedn.'26"]);
        $this->assertSame(0, $coba['sku']);
        $this->assertSame(1, $coba['name']);
        $this->assertSame(3, $coba['catalog_price']);
        $this->assertNull($coba['purchase']);

        $canis = $m->mapLabels(['Strana', 'Pomlčkový kód', 'Název', '', "Men´s working garments", 'UNIT', 'bal./kar.', 'Price CZK', 'Price EUR', 'Price USD', 'Price PLN']);
        $this->assertSame(1, $canis['sku']);
        $this->assertSame(2, $canis['name']);
        $this->assertSame(10, $canis['catalog_price']);

        $ppo = $m->mapLabels(['L.p.', 'Wyszczególnienie', 'Kategoria', 'Model', 'Indeks nadrzędny', 'Cena fabryczna netto I gatunek od 01.06.2026']);
        $this->assertSame(4, $ppo['sku']);
        $this->assertSame(1, $ppo['name']);
        $this->assertSame(5, $ppo['catalog_price']);

        $rostaing = $m->mapLabels(['EAN Code', 'Product Description', 'Range of products', 'Catalogue page', 'Reference', 'Unit or Pair', 'Quantity per bag', 'Quantity per box', 'Base price 2026 in Euro', 'Discounted price']);
        $this->assertSame(4, $rostaing['sku']);
        $this->assertSame(1, $rostaing['name']);
        $this->assertSame(8, $rostaing['catalog_price']);
        $this->assertSame(9, $rostaing['purchase']);

        $irudek = $m->mapLabels(['CAT. PG.', 'CODE', '', '', '', 'DESCRIPTION', '', '', 'NEW CODE', '', '', 'NEW DESCRIPTION', '', '', 'PIECES PER BOX', '', 'STOCK', '', '', 'RETAIL PRICE (€)']);
        $this->assertSame(8, $irudek['sku']);
        $this->assertSame(11, $irudek['name']);
        $this->assertSame(19, $irudek['catalog_price']);

        $scj = $m->mapLabels(['Product name', 'Size', 'Packaging', 'Code', 'Case qty', 'EAN Single', 'EAN carton', 'Dispenser version', '', 'Gross price pack', 'Net price EA']);
        $this->assertSame(3, $scj['sku']);
        $this->assertSame(0, $scj['name']);
        $this->assertSame(10, $scj['catalog_price']);

        $ansellBp = $m->mapLabels(['Commercial naming®®', 'Product Type', 'Colours', 'Short base style', 'Long base style', 'Sizing', 'MTS/MTO', 'Carton Quantity per UoM', 'Price (EUR)']);
        $this->assertSame(4, $ansellBp['sku']);
        $this->assertSame(3, $ansellBp['model_key']);
        $this->assertSame(0, $ansellBp['name']);
        $this->assertSame(8, $ansellBp['catalog_price']);

        $portwest = $m->mapLabels(['Style', 'Page', 'QUOS', 'Carton Quantity', 'Col', 'Price', '10%']);
        $this->assertSame(0, $portwest['sku']);
        $this->assertNull($portwest['name']);
        $this->assertSame(5, $portwest['catalog_price']);

        $this->assertNull($m->mapLabels(['2026 PRICE LIST'])['catalog_price']);
    }
}
