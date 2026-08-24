<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ProductSearchIdentity;
use ReflectionClass;
use Tests\TestCase;

final class EnrichmentQueryLadderTest extends TestCase
{
    public function test_shortest_query_is_sku_with_manufacturer(): void
    {
        $identity = new ProductSearchIdentity();
        $queries = $identity->primaryQueries(new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-914',
            'name' => 'Kurtka ostrzegawcza',
        ]));

        $this->assertSame('URG-914 Urgent', $queries[0] ?? null);
        $this->assertContains('Kurtka ostrzegawcza URG-914 Urgent', $queries);
    }

    public function test_internal_sku_core_is_used_as_query(): void
    {
        $identity = new ProductSearchIdentity();
        $product = new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-C-SPODNIE',
            'name' => 'URG-C (spodnie)',
        ]);

        $this->assertSame('URG-C', $identity->internalSkuCore($product));
        $this->assertContains('URG-C Urgent', $identity->primaryQueries($product));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://optimumbhp.pl/spodnie-robocze-do-pasa-krotkie-urg-c-urgent-szorty-robocze-p138548 '
            .'Spodnie robocze URG-C Urgent',
            $product
        ));
    }

    public function test_internal_sku_is_not_used_as_query(): void
    {
        $identity = new ProductSearchIdentity();
        $queries = $identity->primaryQueries(new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-HSV-WOR-BLUZA',
            'name' => 'Bluza ostrzegawcza HSV',
        ]));

        foreach ($queries as $query) {
            $this->assertStringNotContainsString('URG-HSV-WOR-BLUZA', $query);
        }
        $this->assertSame('Bluza ostrzegawcza HSV Urgent', $queries[0] ?? null);
    }

    public function test_open_search_starts_from_short_queries(): void
    {
        $product = new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-914',
            'name' => 'Kurtka ostrzegawcza',
        ]);
        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);

        $build = $ref->getMethod('buildQueries');
        $build->setAccessible(true);
        $open = $ref->getMethod('openSearchQueries');
        $open->setAccessible(true);

        /** @var list<string> $ladder */
        $ladder = $open->invoke($service, $product, $build->invoke($service, $product, 'manufacturer'));

        $this->assertSame('URG-914 Urgent', $ladder[0] ?? null);
        $this->assertLessThanOrEqual(3, count($ladder));
        $this->assertContains('Kurtka ostrzegawcza URG-914 Urgent', $ladder);
    }

    public function test_hi_vis_jacket_is_not_waterproof_clothing(): void
    {
        $identity = new ProductSearchIdentity();
        $phrase = $identity->productNameWithManufacturer(new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-914',
            'name' => 'Kurtka ostrzegawcza',
        ]));

        $this->assertStringNotContainsString('wodoochronne', $phrase);
        $this->assertStringContainsString('ostrzegawcza', $phrase);
    }
}
