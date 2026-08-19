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
