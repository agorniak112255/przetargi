<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductDocumentFinder;
use ReflectionMethod;
use Tests\TestCase;

final class UvexCdnDocumentGuessTest extends TestCase
{
    public function test_guesses_uvex_datasheet_cdn_urls(): void
    {
        $finder = $this->app->make(ProductDocumentFinder::class);
        $product = new Product([
            'sku' => '60549',
            'name' => 'C300 Dry',
            'manufacturer' => 'uvex',
        ]);

        $method = new ReflectionMethod(ProductDocumentFinder::class, 'guessKnownCdnDocuments');
        $method->setAccessible(true);
        /** @var list<string> $urls */
        $urls = $method->invoke($finder, $product);

        $this->assertContains('https://d3nan4w00fsv2d.cloudfront.net/DATASHEET/60549_PDB_EN.pdf', $urls);
        $this->assertContains('https://d3nan4w00fsv2d.cloudfront.net/DATASHEET/60549_PDB_DE.pdf', $urls);
    }
}
