<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Presta\PrestaProductBinder;
use App\Support\ProductModelFuzzy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PrestaProductBinderTest extends TestCase
{
    private PrestaProductBinder $binder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->binder = new PrestaProductBinder(new ProductModelFuzzy);
    }

    #[Test]
    public function exact_sku_and_brand_is_auto(): void
    {
        $ranked = $this->binder->rank($this->product([
            'sku' => '34700018',
            'name' => 'TEMP-ICE 700',
            'manufacturer' => 'MAPA',
        ]), [
            $this->row(10, '34700018', 'TEMP-ICE 700', 'MAPA'),
            $this->row(11, '60592', 'Unilite Thermo Plus', 'uvex'),
        ]);

        $this->assertNotEmpty($ranked);
        $this->assertSame(10, $ranked[0]['presta_id']);
        $this->assertSame('auto', $ranked[0]['action']);
        $this->assertSame('reference', $ranked[0]['method']);
    }

    #[Test]
    public function typo_model_is_auto_same_brand(): void
    {
        $ranked = $this->binder->rank($this->product([
            'sku' => 'X-1',
            'name' => 'TEPM-ICE 700',
            'manufacturer' => 'MAPA',
        ]), [
            $this->row(10, '34700018', 'TEMP-ICE 700', 'MAPA'),
            $this->row(11, '60592', 'Rękawice zimowe Unilite Thermo Plus', 'uvex'),
        ]);

        $this->assertSame(10, $ranked[0]['presta_id']);
        $this->assertSame('auto', $ranked[0]['action']);
        $this->assertSame('fuzzy_model', $ranked[0]['method']);
    }

    #[Test]
    public function other_brand_name_is_not_auto(): void
    {
        $ranked = $this->binder->rank($this->product([
            'sku' => 'ABC',
            'name' => 'Rękawice zimowe',
            'manufacturer' => 'MAPA',
        ]), [
            $this->row(11, '60592', 'Rękawice zimowe Unilite Thermo Plus', 'uvex'),
        ]);

        $this->assertTrue($ranked === [] || $ranked[0]['action'] === 'review');
        $this->assertTrue($ranked === [] || $ranked[0]['presta_id'] !== 11 || $ranked[0]['action'] === 'review');
        foreach ($ranked as $hit) {
            $this->assertNotSame('auto', $hit['action']);
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function product(array $attrs): Product
    {
        $p = new Product;
        $p->forceFill($attrs);

        return $p;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $reference, string $name, string $manufacturer): array
    {
        return [
            'id_product' => $id,
            'reference' => $reference,
            'ean13' => '',
            'name' => $name,
            'link_rewrite' => 'karta',
            'description_short' => 'Opis krótki rękawic zimowych do pracy.',
            'description' => '<p>Pełny opis rękawic zimowych zgodnych z EN 388.</p>',
            'manufacturer' => $manufacturer,
            'features' => 'EN 388; EN 511',
            'url' => 'https://supon.rzeszow.pl/'.$id.'-karta.html',
        ];
    }
}
