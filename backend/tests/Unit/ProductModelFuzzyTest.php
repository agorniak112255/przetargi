<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\ProductModelFuzzy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductModelFuzzyTest extends TestCase
{
    private ProductModelFuzzy $fuzzy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fuzzy = new ProductModelFuzzy;
    }

    #[Test]
    public function tepm_ice_matches_temp_ice_and_not_other_gloves(): void
    {
        $req = 'Rękawice MAPA TEPM-ICE 700 · EN 388 EN 511 EN ISO 21420';

        $this->assertTrue($this->fuzzy->hasNamedModel($req));
        $this->assertContains('tepmice', $this->fuzzy->needles($req));
        $this->assertContains('tepmice700', $this->fuzzy->needles($req));
        $this->assertSame(['mapa'], $this->fuzzy->manufacturerHints($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            '34700018',
            'TEMP-ICE 700',
            'MAPA',
        )));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'TEMP-ICE-700-5',
            'TEMP-CE 700',
            'MAPA',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '60592',
            'Rękawice zimowe Unilite Thermo Plus',
            'uvex',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '34720039',
            'TEMP DEX 720',
            'MAPA',
        )));
    }

    #[Test]
    public function fastening_words_are_not_a_model_for_rain_jacket(): void
    {
        $req = 'Ubranie ochronne dla elektryków (bluza + spodnie) długa bluza zapinana na zatrzaski EN 1149-5 IEC 61482';

        $this->assertNotContains('zapinanana', $this->fuzzy->needles($req));
        $this->assertNotContains('dlaelektrykow', $this->fuzzy->needles($req));
        $this->assertSame(0, $this->fuzzy->score($req, $this->product(
            '103',
            '103 - Kurtka wodoochronna zapinana na zamek + stójka',
            'PROS / AJ Group',
        )));
    }

    #[Test]
    public function catalog_brand_msa_is_detected_not_common_nouns(): void
    {
        $req = 'Ochronniki słuchu na hełm MSA - niski poziom tłumienia';

        $this->assertContains('msa', $this->fuzzy->catalogBrands($req));
        $this->assertNotContains('ochronniki', $this->fuzzy->catalogBrands($req));
        $this->assertTrue($this->fuzzy->matchesCatalogBrand($this->product('X', 'Nauszniki', 'MSA'), ['msa']));
        $this->assertFalse($this->fuzzy->matchesCatalogBrand($this->product('PW75', 'PW75', 'Portwest'), ['msa']));
    }

    #[Test]
    public function short_alnum_code_p3e_is_a_named_model(): void
    {
        $req = 'Adapter P3E do hełmu 3M';

        $this->assertTrue($this->fuzzy->hasNamedModel($req));
        $this->assertContains('p3e', $this->fuzzy->needles($req));
        $this->assertContains('p3e', $this->fuzzy->shortCodes($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'P3E',
            '3M Adapter P3E do mocowania osłony twarzy',
            '3M',
        )));
        $this->assertSame(0, $this->fuzzy->score($req, $this->product(
            'FH-934',
            'Adapter kaptura ochronnego 3M systemu z wymuszonym przepływem',
            '3M',
        )));
    }

    #[Test]
    public function polar_fabric_is_not_sku_pola(): void
    {
        $req = 'KURTKA DAMSKA - POLAR granatowy rozm. S - XXXXL';
        $this->assertSame(0, $this->fuzzy->score($req, $this->product(
            'POLA',
            'POLA - EN 420 KAT. II, EN 388 - 3131',
            'X',
        )));
    }

    #[Test]
    public function perspecta_and_numeric_suffix_form_model_needle(): void
    {
        $req = 'OKULARY OCHRONNE MSA PERSPECTA 010';

        $this->assertTrue($this->fuzzy->hasNamedModel($req));
        $this->assertContains('perspecta010', $this->fuzzy->needles($req));
        $this->assertContains('perspecta010', $this->fuzzy->catalogModelNeedles($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            '10061279',
            'MSA PERSPECTA 010 - Okulary ochronne',
            'MSA',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '10045516',
            'Okulary PERSPECTA 9000 (12szt), bezbarwne',
            'MSA',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '10081939',
            'Sztywne etui na okulary Perspecta (6szt)',
            'MSA',
        )));
        $this->assertNotContains('perspecta', $this->fuzzy->needles($req));
    }

    #[Test]
    public function peltor_x2_is_a_named_model_and_not_x1(): void
    {
        $req = 'Nauszniki przeciwhałasowe 3M Peltor X2 wersja nagłowna';

        $this->assertTrue($this->fuzzy->hasNamedModel($req));
        $this->assertContains('peltorx2', $this->fuzzy->needles($req));
        $this->assertContains('peltorx2', $this->fuzzy->catalogModelNeedles($req));
        $this->assertTrue($this->fuzzy->usesModelAnchoredCatalogSearch($req));
        $this->assertGreaterThanOrEqual(80, $this->fuzzy->score($req, $this->product(
            'X2A-EU',
            '3M Nauszniki przeciwhałasowe PELTOR X2 - wersja nagłowna (SNR 31 dB)',
            '3M',
        )));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            'X1A-EU',
            '3M Nauszniki przeciwhałasowe PELTOR X1 - wersja nagłowna (SNR 27 dB)',
            '3M',
        )));
        $this->assertNotContains('klasas3', $this->fuzzy->needles(
            'Trzewiki robocze w klasie ochrony S3 SRC z podnoskiem'
        ));
    }

    #[Test]
    public function disposable_cap_pack_is_not_a_named_model(): void
    {
        $req = 'CZEPEK JEDNORAZOWY -1 OP.-100 szt.';

        $this->assertFalse($this->fuzzy->usesModelAnchoredCatalogSearch($req));
        $this->assertSame([], $this->fuzzy->catalogModelNeedles($req));
        $this->assertNotContains('czepek', $this->fuzzy->needles($req));
        $this->assertNotContains('jednorazowy', $this->fuzzy->needles($req));
        $this->assertNotContains('op100', $this->fuzzy->needles($req));
    }

    #[Test]
    public function shoe_size_range_is_not_a_catalog_model(): void
    {
        $req = 'BUTY gumowe DAMSKIE antyelektrostatyczne rozm. 35-41 TRONCHETTO OB. SRA prod.CERVA · EN ISO 20347';

        $this->assertNotContains('rozm3541', $this->fuzzy->catalogModelNeedles($req));
        $this->assertContains('tronchetto', $this->fuzzy->catalogModelNeedles($req));
        $this->assertContains('cerva', $this->fuzzy->catalogBrands($req));
        $this->assertLessThan(80, $this->fuzzy->score($req, $this->product(
            '28-0001.00/858.0_3400',
            'Getry żaroodp. metalizowane 858.0 wys. 34 cm, taśma spręż., rozm. 41-42',
            'ALWIT POLAND',
        )));
    }

    private function product(string $sku, string $name, string $manufacturer): Product
    {
        $p = new Product;
        $p->forceFill([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
        ]);

        return $p;
    }
}
