<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductSearchIdentity;
use Tests\TestCase;

final class ProductSearchIdentityTest extends TestCase
{
    public function test_strips_brand_prefix_and_builds_google_like_queries(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1007',
            'name' => '1007',
            'manufacturer' => 'PROS',
        ]);

        $tokens = $id->matchTokens($product);
        $this->assertContains('pros-1007', $tokens);
        $this->assertContains('1007', $tokens);

        $queries = $id->searchQueries($product, 'industry');
        $joined = implode(' | ', $queries);
        $this->assertStringContainsString('PROS-1007', $joined);
        $this->assertStringContainsString('PROS 1007', $joined);
        // sama marka PROS nie dokłada fałszywego hintu kategorii
        $this->assertStringNotContainsString('ubranie wodoochronne', $joined);
    }

    public function test_waterproof_name_still_gets_clothing_hint(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1001',
            'name' => 'Ubranie wodoochronne 1001',
            'manufacturer' => 'PROS',
        ]);

        $joined = implode(' | ', $id->searchQueries($product, 'industry'));
        $this->assertStringContainsString('ubranie wodoochronne', $joined);
    }

    public function test_matches_pros_model_slash_variant(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1001',
            'name' => '1001',
            'manufacturer' => 'PROS',
        ]);

        $hay = 'https://icd.pl/produkt/ubranie-wodoochronne-pros-model-101-001 '
            .'Ubranie wodoochronne PROS model 101/001 - czarny Plavitex';

        $this->assertTrue($id->hayMentionsProduct($hay, $product));
        $this->assertTrue($id->coreInUrlOrTitle(
            'https://icd.pl/produkt/ubranie-wodoochronne-pros-model-101-001',
            'Ubranie wodoochronne PROS model 101/001',
            $product
        ));
    }

    public function test_rejects_short_numeric_without_brand(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1001',
            'name' => '1001',
            'manufacturer' => 'PROS',
        ]);

        $this->assertFalse($id->hayMentionsProduct(
            'https://example.com/product/1001 Random gadget 1001',
            $product
        ));
    }

    public function test_uvex_numeric_sku_still_matches(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '60549',
            'name' => 'C300 Dry',
            'manufacturer' => 'uvex',
        ]);

        $this->assertTrue($id->hayMentionsProduct(
            'https://bhp-sklep.com.pl/produkt/rekawice-uvex-c300-dry-60549 uvex C300 Dry 60549',
            $product
        ));
        $queries = $id->searchQueries($product, 'manufacturer');
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('60549', implode(' | ', $queries));
    }
}
