<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductSizeVariant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductSizeVariantTest extends TestCase
{
    #[Test]
    public function groups_ansell_names_that_differ_only_by_size(): void
    {
        $svc = new ProductSizeVariant;

        $a = $svc->groupKey('Ansell', 'AlphaTec 37695VP Size 7.0', '37695VP070');
        $b = $svc->groupKey('Ansell', 'AlphaTec 37695VP Size 10.0', '37695VP100');

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertSame('37695VP', $svc->skuCore('37695VP100', 'AlphaTec 37695VP Size 10.0'));
        $this->assertSame('AlphaTec 37695VP', $svc->stripSizeFromName('AlphaTec 37695VP Size 10.0'));
    }

    #[Test]
    public function does_not_group_different_models(): void
    {
        $svc = new ProductSizeVariant;

        $a = $svc->groupKey('Ansell', 'AlphaTec 37695VP Size 10.0', '37695VP100');
        $b = $svc->groupKey('Ansell', 'AlphaTec 37900VP Size 10.0', '37900VP100');

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function same_price_bucket_only_when_catalog_and_purchase_match(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertSame($svc->priceBucket(2.85, 2.85), $svc->priceBucket('2.85', 2.85));
        $this->assertNotSame($svc->priceBucket(2.85, 2.85), $svc->priceBucket(3.96, 3.96));
    }

    #[Test]
    public function ignores_sku_without_size_in_name_or_known_suffix(): void
    {
        $svc = new ProductSizeVariant;

        $this->assertNull($svc->groupKey('X', 'Rękawice test', 'MAXIFLEX34874'));
    }
}
