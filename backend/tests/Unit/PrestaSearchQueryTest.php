<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Presta\PrestaSearchQuery;
use App\Support\ProductSizeVariant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PrestaSearchQueryTest extends TestCase
{
    private PrestaSearchQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new PrestaSearchQuery(new ProductSizeVariant);
    }

    #[Test]
    public function brand_and_name_require_both(): void
    {
        $product = $this->product([
            'sku' => 'INNY-SKU',
            'name' => 'TEMP-ICE 700',
            'manufacturer' => 'AJ Group',
        ]);

        $this->assertTrue($this->query->rowMatchesBrandAndName($product, [
            'name' => 'Rękawice TEMP-ICE 700 zimowe',
            'manufacturer' => 'AJ Group',
            'reference' => '34700018',
        ]));
        $this->assertFalse($this->query->rowMatchesBrandAndName($product, [
            'name' => 'Rękawice lateksowe niebieskie',
            'manufacturer' => 'AJ Group',
            'reference' => '1',
        ]));
        $this->assertFalse($this->query->rowMatchesBrandAndName($product, [
            'name' => 'TEMP-ICE 700',
            'manufacturer' => 'MAPA',
            'reference' => '34700018',
        ]));
    }

    #[Test]
    public function code_match_wins_over_name(): void
    {
        $product = $this->product([
            'sku' => '34700018',
            'name' => 'TEMP-ICE 700',
            'manufacturer' => 'MAPA',
        ]);

        $this->assertTrue($this->query->rowMatchesCode($product, [
            'reference' => '34700018',
            'ean13' => '',
            'name' => 'Inna nazwa',
            'manufacturer' => 'MAPA',
        ]));
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
}
