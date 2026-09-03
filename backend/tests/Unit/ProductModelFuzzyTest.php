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
