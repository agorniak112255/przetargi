<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ManufacturerSite;
use App\Models\Product;
use App\Services\Enrichment\ManufacturerCatalogRegistrar;
use App\Services\Enrichment\ManufacturerDomainResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ManufacturerCatalogRegistrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_remembered_host_is_used_in_search_domains(): void
    {
        $product = new Product([
            'manufacturer' => 'NowaMarkaBhp',
            'sku' => 'NM-1',
            'name' => 'Rękawice',
        ]);
        $registrar = app(ManufacturerCatalogRegistrar::class);
        $registrar->remember('NowaMarkaBhp', ['nowamarkabhp.pl'], 'discovered');

        $this->assertContains('nowamarkabhp.pl', $registrar->hostsFor($product));
        $this->assertContains(
            'nowamarkabhp.pl',
            app(ManufacturerDomainResolver::class)->domainsFor($product)
        );
        $this->assertDatabaseHas('manufacturer_sites', [
            'brand_key' => 'nowamarkabhp',
            'host' => 'nowamarkabhp.pl',
        ]);
    }

    public function test_known_config_brand_is_recorded_without_discovery(): void
    {
        $product = Product::query()->create([
            'sku' => 'MEDIBUT-X',
            'name' => 'Półbuty',
            'manufacturer' => 'MEDIBUT',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 0,
        ]);

        $hosts = app(ManufacturerCatalogRegistrar::class)->register('MEDIBUT', $product, false);

        $this->assertContains('medibut.pl', $hosts);
        $this->assertSame('config', ManufacturerSite::query()->where('host', 'medibut.pl')->value('source'));
    }
}
