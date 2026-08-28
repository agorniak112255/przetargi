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

    public function test_resolves_msa_pl_catalog_domains(): void
    {
        $resolver = app(ManufacturerDomainResolver::class);
        $product = new Product([
            'manufacturer' => 'MSA',
            'sku' => '10160056',
            'name' => 'ALTAIR 2XT CO-H2/H2S',
        ]);

        $domains = $resolver->domainsFor($product);
        $this->assertContains('pl.msasafety.com', $domains);
        $this->assertContains('msasafety.com', $domains);
        $this->assertTrue($resolver->isManufacturerUrl(
            'https://pl.msasafety.com/p/altair-2xt?locale=pl',
            $product,
            $domains
        ));
        $this->assertFalse($resolver->isManufacturerUrl(
            'https://strefa998.pl/msa?id=7',
            $product,
            $domains
        ));
        $this->assertFalse($resolver->isManufacturerUrl(
            'https://bhp.pl/marki/msa',
            $product,
            $domains
        ));
        $this->assertFalse($resolver->isManufacturerUrl(
            'https://sklep.arpapol.pl/sklep,11,msa-auer.html',
            $product,
            $domains
        ));
    }

    public function test_resolves_ejendals_jalas_domains(): void
    {
        $resolver = app(ManufacturerDomainResolver::class);
        $product = new Product([
            'manufacturer' => 'Ejendals',
            'sku' => '1868',
            'name' => 'JALAS 1868 KING',
        ]);

        $domains = $resolver->domainsFor($product);
        $this->assertContains('ejendals.com', $domains);
        $this->assertTrue($resolver->isManufacturerUrl(
            'https://www.ejendals.com/pl/products/jalas-1868-king',
            $product,
            $domains
        ));
        $this->assertContains(
            'jalas.com',
            $resolver->domainsFor(new Product([
                'manufacturer' => 'Jalas',
                'sku' => '1868',
                'name' => '1868 KING',
            ]))
        );
    }

    public function test_resolves_msa_safety_and_auer_aliases(): void
    {
        $resolver = app(ManufacturerDomainResolver::class);

        $this->assertContains(
            'pl.msasafety.com',
            $resolver->domainsFor(new Product(['manufacturer' => 'MSA Safety', 'sku' => 'X', 'name' => 'X']))
        );
        $this->assertContains(
            'pl.msasafety.com',
            $resolver->domainsFor(new Product(['manufacturer' => 'MSA Auer', 'sku' => 'X', 'name' => 'X']))
        );
    }

    public function test_retailer_domains_include_listed_bhp_shops(): void
    {
        $retailers = config('enrichment.retailer_domains');
        $this->assertIsArray($retailers);

        foreach ([
            'tmbhp.pl',
            'glovex.com.pl',
            'bhpsupply.pl',
            'marketbhp.pl',
            'bhponline-24.pl',
            'balticbhp.pl',
            'esklep.krisbhp.pl',
            'aitbhp.pl',
            'behapownia.pl',
            'kams.com.pl',
            'bhp-gabi.pl',
            'bhp-sklep.com.pl',
            'specto.com.pl',
            'optimumbhp.pl',
            'bpbhp.pl',
            'kingbhp.pl',
            'filimar.pl',
            'elmar-bhp.pl',
            'sklep.prohaccp.pl',
            'natare.pl',
            'sklep.arsel-bhp.pl',
            'roboczebhp.pl',
        ] as $host) {
            $this->assertContains($host, $retailers);
        }
    }

    public function test_resolves_ardon_official_site(): void
    {
        $resolver = app(ManufacturerDomainResolver::class);
        $product = new Product([
            'manufacturer' => 'ARDON SAFETY S.R.O.',
            'sku' => 'M80',
            'name' => 'Buty robocze Ardon',
        ]);

        $domains = $resolver->domainsFor($product);
        $this->assertContains('ardon.pl', $domains);
        $this->assertTrue($resolver->isManufacturerUrl(
            'https://www.ardon.pl/buty-robocze',
            $product,
            $domains
        ));
        $this->assertFalse($resolver->isManufacturerUrl(
            'https://behapownia.pl/ardon',
            $product,
            $domains
        ));
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
