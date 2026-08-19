<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ManufacturerDomainResolver;
use Tests\TestCase;

final class ManufacturerDomainResolverTest extends TestCase
{
    public function test_resolves_demar_domains_from_config(): void
    {
        $resolver = app(ManufacturerDomainResolver::class);
        $product = new Product([
            'manufacturer' => 'DEMAR / obuwie',
            'sku' => 'CLIC UP O1 FO SRC',
            'name' => 'CLIC UP O1 FO SRC',
        ]);

        $domains = $resolver->domainsFor($product);

        $this->assertContains('demar.com.pl', $domains);
        $this->assertTrue($resolver->isManufacturerUrl(
            'https://www.demar.com.pl/media/certyfikaty/CERT_CLIC_UP.pdf',
            $product,
            $domains
        ));
        $this->assertFalse($resolver->isManufacturerUrl(
            'https://demar24.pl/produkt/clic-up',
            $product,
            $domains
        ));
    }

    public function test_discovers_brand_host_from_results(): void
    {
        $resolver = app(ManufacturerDomainResolver::class);
        $product = new Product([
            'manufacturer' => 'Uvex',
            'sku' => 'X-1',
            'name' => 'X-1',
        ]);

        $domains = $resolver->discoverFromResults($product, [
            ['url' => 'https://www.uvex-safety.com/pl/product/x-1'],
            ['url' => 'https://icd.pl/produkt/x-1'],
        ]);

        $this->assertTrue(
            $resolver->hostMatchesAny('www.uvex-safety.com', $domains)
            || $resolver->hostMatchesAny('uvex-safety.com', $domains)
        );
        $this->assertFalse($resolver->isManufacturerUrl('https://icd.pl/x-1', $product, $domains));
    }

    public function test_pilne_gloves_use_urgent_manufacturer_domains(): void
    {
        $resolver = app(ManufacturerDomainResolver::class);
        $product = new Product([
            'manufacturer' => 'PILNE',
            'sku' => 'PILNE-1019',
            'name' => '1019 ZIMA Z POLARU',
            'category' => 'REKAWICE',
        ]);

        $domains = $resolver->domainsFor($product);
        $this->assertContains('urgent.com.pl', $domains);
        $this->assertTrue($resolver->isManufacturerUrl(
            'https://urgent.com.pl/wp-content/uploads/1019.jpg',
            $product,
            $domains
        ));
    }
}
