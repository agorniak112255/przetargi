<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\B2b\B2bConnectorRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductShopSourceUrlTest extends TestCase
{
    public function test_hinted_url_matches_with_and_without_trailing_slash(): void
    {
        $product = new Product([
            'shop_source_url' => 'https://Sklep.Example.com/karta/abc/',
        ]);

        $this->assertTrue($product->isHintedShopUrl('https://sklep.example.com/karta/abc'));
        $this->assertFalse($product->isHintedShopUrl('https://sklep.example.com/karta/abc/?x=1'));
        $this->assertFalse($product->isHintedShopUrl('https://inny.example.com/karta/abc'));
        $this->assertSame('https://Sklep.Example.com/karta/abc/', $product->hintedShopUrl());
    }

    public function test_hinted_url_matches_encoded_comma_in_path(): void
    {
        $encoded = 'https://centrumelektronarzedzi.pl/pl/p/Chodnik-elektroizolacyjny-20-KV-wymiary-1%2C1-x-2-m-Secura/48601';
        $decoded = 'https://centrumelektronarzedzi.pl/pl/p/Chodnik-elektroizolacyjny-20-KV-wymiary-1,1-x-2-m-Secura/48601';
        $product = new Product(['shop_source_url' => $encoded]);

        $this->assertTrue($product->isHintedShopUrl($decoded));
        $this->assertTrue($product->isHintedShopUrl($encoded));
    }

    public function test_empty_shop_source_is_not_hinted(): void
    {
        $product = new Product(['shop_source_url' => '']);

        $this->assertNull($product->hintedShopUrl());
        $this->assertFalse($product->isHintedShopUrl('https://sklep.example.com/x'));
        $this->assertNull($product->trustedShopUrl());
        $this->assertFalse($product->isTrustedShopUrl('https://sklep.example.com/x'));
    }

    public function test_link_typed_by_person_on_foreign_host_is_trusted(): void
    {
        $product = new Product(['shop_source_url' => 'https://anro-sklep.pl/znak-koc-gasniczy-150x150mm-ps-f016-p6128']);

        $this->assertSame('https://anro-sklep.pl/znak-koc-gasniczy-150x150mm-ps-f016-p6128', $product->trustedShopUrl());
        $this->assertTrue($product->isTrustedShopUrl('https://anro-sklep.pl/znak-koc-gasniczy-150x150mm-ps-f016-p6128/'));
        $this->assertFalse($product->isTrustedShopUrl('https://anro-sklep.pl/inna-karta'));
    }

    /**
     * Adres karty u dostawcy B2B wpisuje synchronizacja — zostaje linkiem (idzie pierwszy do pobrania), ale nie omija
     * bramek tożsamości. Karta 57476: strona P4S bez sesji oddaje tylko komunikat przeglądarki.
     *
     * @return iterable<string, array{string}>
     */
    public static function connectorUrls(): iterable
    {
        yield 'P4S (SPA, adres z #)' => ['https://b2b.p4s.pl/#/product/95546'];
        yield 'Anro B2B' => ['https://b2b.anro.net.pl/products/14074'];
        yield 'Raw-Pol na subdomenie hosta łącznika' => ['https://web.rawpol.com/?v=123'];
        yield 'Ardon z www' => ['https://www.ardon.pl/p/filtr-3m-5911'];
        yield 'SignProject' => ['https://signproject.pl/pl/products/hb008-pod-napieciem-123'];
    }

    #[DataProvider('connectorUrls')]
    public function test_link_on_b2b_connector_site_is_hinted_but_not_trusted(string $url): void
    {
        $product = new Product(['shop_source_url' => $url]);

        $this->assertSame($url, $product->hintedShopUrl());
        $this->assertTrue($product->isHintedShopUrl($url));
        $this->assertNull($product->trustedShopUrl());
        $this->assertFalse($product->isTrustedShopUrl($url));
    }

    public function test_host_merely_ending_with_connector_name_is_not_connector_site(): void
    {
        // „notardon.pl” to nie ardon.pl ani jego subdomena
        $this->assertFalse(B2bConnectorRegistry::isConnectorUrl('https://notardon.pl/karta'));
        $this->assertFalse(B2bConnectorRegistry::isConnectorUrl('nie-adres'));
        $this->assertTrue(B2bConnectorRegistry::isConnectorUrl('https://B2B.P4S.PL/#/product/1'));
    }
}
