<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\PpeAssortment;
use App\Support\ProductSearchBlob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductSearchBlobTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): ProductSearchBlob
    {
        return $this->app->make(ProductSearchBlob::class);
    }

    public function test_grammage_variants_collapse_to_one_token(): void
    {
        $builder = $this->builder();

        foreach (['250 g/m²', '250gr', '250 gsm', 'gramatura 250'] as $variant) {
            $this->assertStringContainsString(
                '250gsm',
                $builder->canonicalFeatures('spodnie robocze '.$variant),
                "Wariant zapisu „{$variant}” nie dał kanonicznego tokenu."
            );
        }
    }

    public function test_footwear_class_becomes_fulltext_token(): void
    {
        $this->assertStringContainsString(
            'klasas2',
            $this->builder()->canonicalFeatures('półbuty S2 na zam.')
        );
        $this->assertStringContainsString(
            'klasao2',
            $this->builder()->canonicalFeatures('sztyblety O2')
        );
    }

    public function test_hyphenated_filter_class_lands_in_canonical_blob(): void
    {
        $this->assertStringContainsString(
            'a2b2e2k2hgconop3',
            $this->builder()->canonicalFeatures('Filtr 203 UP3 A2-B2-E2-K2-Hg-CO-NO-P3')
        );
    }

    public function test_norm_variants_collapse_to_one_token(): void
    {
        $builder = $this->builder();

        foreach (['EN ISO 20471', 'EN20471', 'en 20471:2013'] as $variant) {
            $this->assertStringContainsString(
                'en20471',
                $builder->canonicalFeatures('kamizelka '.$variant)
            );
        }
    }

    public function test_blob_is_written_on_save_with_family_and_canonical_tokens(): void
    {
        $product = Product::query()->create([
            'sku' => 'CXS-STRETCH',
            'name' => 'CXS STRETCH',
            'manufacturer' => 'CANIS SAFETY',
            'category' => 'Odzież robocza',
            'norms' => 'EN 13688',
            'description' => 'Spodnie robocze męskie przy gramaturze 250 g/m².',
            'catalog_price_net' => 95,
            'purchase_price' => 60,
            'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->assertSame(PpeAssortment::FAMILY_APPAREL, $product->ppe_family);
        $this->assertStringContainsString('250gsm', (string) $product->search_blob);
        $this->assertStringContainsString('en13688', (string) $product->search_blob);
        // Diakrytyki znikają, żeby „męskie” i „meskie” dały ten sam token.
        $this->assertStringContainsString('meskie', (string) $product->search_blob);
        $this->assertNotSame('', (string) $product->search_blob_hash);
    }

    public function test_blob_is_refreshed_when_description_changes(): void
    {
        $product = Product::query()->create([
            'sku' => 'PANT-1',
            'name' => 'Spodnie robocze',
            'manufacturer' => 'X',
            'category' => 'Odzież robocza',
            'description' => 'Spodnie bez podanej gramatury.',
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $before = (string) $product->search_blob_hash;

        $product->update(['description' => 'Spodnie o gramaturze 280 gr.']);

        $this->assertNotSame($before, (string) $product->fresh()?->search_blob_hash);
        $this->assertStringContainsString('280gsm', (string) $product->fresh()?->search_blob);
    }

    public function test_price_change_alone_does_not_rebuild_blob(): void
    {
        $product = Product::query()->create([
            'sku' => 'PANT-2',
            'name' => 'Spodnie robocze',
            'manufacturer' => 'X',
            'category' => 'Odzież robocza',
            'description' => 'Spodnie o gramaturze 300 gr.',
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        $before = (string) $product->search_blob_hash;

        $product->update(['catalog_price_net' => 99]);

        $this->assertSame($before, (string) $product->fresh()?->search_blob_hash);
    }
}
