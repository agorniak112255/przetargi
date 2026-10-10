<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RegisterManufacturerCatalogJob;
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

    public function test_registering_brand_keeps_manual_assignment(): void
    {
        // strona przypisana ręcznie (Strony wyszukiwarka / formularz cennika z pliku)
        ManufacturerSite::query()->create(['brand_key' => 'nowamarkabhp', 'manufacturer' => 'NowaMarkaBhp SA', 'host' => 'nowamarkabhp.pl', 'source' => 'manual']);
        $product = Product::query()->create([
            'sku' => 'NM-1', 'name' => 'Rękawice', 'manufacturer' => 'NowaMarkaBhp',
            'catalog_price_net' => 1, 'purchase_price' => 1, 'stock' => 0,
        ]);

        // import marki rejestruje jej domeny — znane z manufacturer_sites zapisuje jako „discovered” (bez wykrywania
        // i bez indeksowania), synchronicznie
        (new RegisterManufacturerCatalogJob('NowaMarkaBhp', $product->id))->handle(app(ManufacturerCatalogRegistrar::class));

        $row = ManufacturerSite::query()->where('host', 'nowamarkabhp.pl')->sole();
        $this->assertSame('manual', $row->source);
        $this->assertSame('NowaMarkaBhp SA', $row->manufacturer);
        $this->assertContains('nowamarkabhp.pl', app(ManufacturerDomainResolver::class)->assignedDomainsFor($product));
    }

    public function test_discovered_does_not_override_config_and_manual_overrides_discovered(): void
    {
        ManufacturerSite::query()->create(['brand_key' => 'marka', 'manufacturer' => 'Marka', 'host' => 'marka.pl', 'source' => 'config']);
        ManufacturerSite::remember('marka', 'Marka Wykryta', ['marka.pl'], 'discovered');
        $this->assertSame(['config', 'Marka'], array_values(ManufacturerSite::query()->where('host', 'marka.pl')->sole()->only(['source', 'manufacturer'])));

        // równa i wyższa ranga zapisuje jak dotąd
        ManufacturerSite::remember('marka', 'Marka Config', ['marka.pl'], 'config');
        $this->assertSame('Marka Config', ManufacturerSite::query()->where('host', 'marka.pl')->value('manufacturer'));
        ManufacturerSite::remember('marka', 'Marka', ['marka.pl'], 'manual');
        $this->assertSame('manual', ManufacturerSite::query()->where('host', 'marka.pl')->value('source'));
        ManufacturerSite::remember('marka', 'Marka', ['marka.pl'], 'config');
        $this->assertSame('manual', ManufacturerSite::query()->where('host', 'marka.pl')->value('source'));
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
