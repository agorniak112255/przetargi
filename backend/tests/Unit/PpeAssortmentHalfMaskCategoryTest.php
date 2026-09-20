<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\PpeAssortment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ścieżka kategorii u dostawcy bywa workiem na wszystkie półmaski: SECURA 3000 (półmaska
 * wielorazowa) stoi w „Półmaski filtrujące FFP1”. Goła „półmaska” z nazwy stawała się przez to
 * twardym „ffp” i karta wypadała z puli przy wymaganiu na półmaskę wielokrotnego użytku —
 * zanim model zdążył ją ocenić.
 */
final class PpeAssortmentHalfMaskCategoryTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'Półmaska wielokrotnego użytku do ochrony układu oddechowego, '
        .'korpus z dwoma zaworami wdechowymi z łącznikami bagnetowymi, zgodność z PN-EN 140:2004.';

    public function test_reusable_half_mask_in_ffp_shop_category_is_not_rejected(): void
    {
        $product = $this->makeProduct(
            'Półmaska SECURA 3000 (nagłowie jednoczęściowe)',
            'Sklep - kategorie / Ochrona dróg oddechowych / Półmaski przeciwpyłowe i przeciwwirusowe / Półmaski filtrujące FFP1'
        );

        $this->assertTrue(
            $this->app->make(PpeAssortment::class)->compatibleProduct(self::REQUIREMENT, $product),
            'półmaska wielorazowa wypadła przez kategorię sklepu, zanim model ją zobaczył'
        );
    }

    public function test_card_named_ffp_still_conflicts_with_reusable_requirement(): void
    {
        // Nazwa mówi wprost, czym wyrób jest — tego nie osłabiamy.
        $product = $this->makeProduct(
            'Półmaska filtrująca FFP2 NR D, jednorazowa',
            'Sklep - kategorie / Ochrona dróg oddechowych'
        );

        $this->assertFalse(
            $this->app->make(PpeAssortment::class)->compatibleProduct(self::REQUIREMENT, $product),
            'maska FFP z nazwy nie może uchodzić za półmaskę wielokrotnego użytku'
        );
    }

    public function test_shop_category_still_confirms_reusable_construction(): void
    {
        // Doprecyzowanie w stronę konstrukcji wielorazowej ma dalej działać.
        $product = $this->makeProduct(
            'Półmaska 3M 6200',
            'Sklep - kategorie / Ochrona dróg oddechowych / Półmaski wielokrotnego użytku'
        );

        $this->assertTrue(
            $this->app->make(PpeAssortment::class)->compatibleProduct(self::REQUIREMENT, $product)
        );
    }

    private function makeProduct(string $name, string $category): Product
    {
        return Product::query()->create([
            'sku' => 'SKU-'.substr(md5($name), 0, 8),
            'name' => $name,
            'manufacturer' => 'TEST',
            'category' => $category,
            'description' => 'Karta testowa do bramki zgodności dróg oddechowych.',
            'catalog_price_net' => 100,
            'purchase_price' => 70,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
    }
}
