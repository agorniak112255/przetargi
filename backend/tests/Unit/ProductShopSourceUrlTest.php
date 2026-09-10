<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
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
    }
}
