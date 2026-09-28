<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductMatchService;
use App\Support\ProductModelFuzzy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Karta scalona z kilku kolorów („ARMEN 9007 S1”) niesie kod koloru tylko w wierszu wariantu („ARMEN-9007-1010-42”).
 * Pod „ARMEN 9007 1010 S1” to żądany wariant do wyboru w ofercie, nie inny wariant — a przynależność karty do modelu
 * dalej rozstrzygają wyłącznie nazwa i SKU (etap A łączenia wariantów kolorystycznych, 28.09.2026).
 */
final class ProductModelFuzzyVariantRowsTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'buty firmy ARTRA model ARMEN 9007 1010 S1';

    public function test_card_without_variant_rows_still_lacks_the_code(): void
    {
        $card = $this->card('ARMEN 9007 S1');

        $this->assertSame(['1010'], $this->fuzzy()->missingVariantCodes(self::QUERY, $card));
        $this->assertFalse($this->fuzzy()->isRequestedVariant(self::QUERY, $card));
        $this->assertSame([], $this->fuzzy()->otherVariantCodes(self::QUERY, $card));
    }

    public function test_code_in_an_active_variant_row_makes_the_card_the_requested_variant(): void
    {
        $card = $this->card('ARMEN 9007 S1');
        $this->variant($card, 'ARMEN-9007-1010-42', 'czarny 42');
        $this->variant($card, 'ARMEN-9007-6660-42', 'zielony 42');

        $this->assertSame([], $this->fuzzy()->missingVariantCodes(self::QUERY, $card));
        $this->assertFalse($this->fuzzy()->isOtherVariant(self::QUERY, $card));
        $this->assertTrue($this->fuzzy()->isRequestedVariant(self::QUERY, $card));
    }

    public function test_code_in_a_variant_label_counts_too(): void
    {
        $card = $this->card('ARMEN 9007 S1');
        $this->variant($card, 'A-42', 'kolor 1010, rozmiar 42');

        $this->assertSame([], $this->fuzzy()->missingVariantCodes(self::QUERY, $card));
    }

    public function test_code_inside_a_longer_number_of_a_variant_is_no_evidence(): void
    {
        $card = $this->card('ARMEN 9007 S1');
        // numer globalny (jak 3M 7000101012) ma w środku cyfry 1010 — to nie kod koloru
        $this->variant($card, '7000101012', 'czarny 42');

        $this->assertSame(['1010'], $this->fuzzy()->missingVariantCodes(self::QUERY, $card));
        $this->assertFalse($this->fuzzy()->isRequestedVariant(self::QUERY, $card));
    }

    public function test_removed_variant_row_is_no_evidence(): void
    {
        $card = $this->card('ARMEN 9007 S1');
        $this->variant($card, 'ARMEN-9007-1010-42', 'czarny 42', removed: true);

        $this->assertSame(['1010'], $this->fuzzy()->missingVariantCodes(self::QUERY, $card));
        $this->assertFalse($this->fuzzy()->isRequestedVariant(self::QUERY, $card));
    }

    public function test_other_variant_codes_list_the_codes_of_variant_rows(): void
    {
        $card = $this->card('ARMEN 9007 S1');
        $this->variant($card, 'ARMEN-9007-6660-42', 'zielony 42');
        $this->variant($card, 'ARMEN-9007-9360-42', 'granatowy 42');

        $this->assertSame(['1010'], $this->fuzzy()->missingVariantCodes(self::QUERY, $card));
        // numer modelu 9007 z igły to nie wariant
        $this->assertSame(['6660', '9360'], $this->fuzzy()->otherVariantCodes(self::QUERY, $card));
    }

    public function test_variant_row_does_not_make_another_product_a_model_card(): void
    {
        $card = $this->card('REIS BRS S1');
        $this->variant($card, 'REIS-1010-42', 'czarny 42');

        $this->assertSame([], $this->fuzzy()->missingVariantCodes(self::QUERY, $card));
        $this->assertFalse($this->fuzzy()->isRequestedVariant(self::QUERY, $card), 'przynależność do modelu tylko z nazwy i SKU');
        $this->assertFalse($this->fuzzy()->isVariantModelCard(self::QUERY, $card));
    }

    public function test_exact_variant_code_from_the_query_is_recognised_only_as_a_whole_word(): void
    {
        // karta kolorów 3M po scaleniu: kod koloru tylko w wierszu wariantu
        $colours = $this->card('Hełm ochronny 3M™, wskaźnik Uvicator, regulacja śrubowa, wentylowany, G3000NUV');
        $this->variant($colours, 'G3000NUV-RD', 'czerwony');
        $this->variant($colours, 'G3000NUV-BB', 'niebieski');
        $reflective = $this->card('Hełm ochronny 3M™, wskaźnik Uvicator, regulacja śrubowa, wentylowany, odblaskowy, G3000NUV-R');
        $this->variant($reflective, 'G3000NUV-R-RD', 'czerwony');
        $query = 'Hełm ochronny 3M G3000NUV-RD, kolor czerwony, wskaźnik UV';

        $this->assertTrue($this->fuzzy()->variantCodeWrittenInQuery($query, $colours));
        $this->assertFalse($this->fuzzy()->variantCodeWrittenInQuery($query, $reflective));
        $this->assertFalse($this->fuzzy()->variantCodeWrittenInQuery('Hełm ochronny 3M G3000NUV, kolor czerwony', $colours));
        $this->assertFalse($this->fuzzy()->variantCodeWrittenInQuery($query, new Product(['sku' => 'X', 'name' => 'X'])));
    }

    public function test_tender_heuristic_prefers_the_card_with_the_exact_variant_code(): void
    {
        $colours = $this->card('Hełm ochronny 3M™, wskaźnik Uvicator, regulacja śrubowa, wentylowany, G3000NUV');
        $this->variant($colours, 'G3000NUV-RD', 'czerwony');
        $reflective = $this->card('Hełm ochronny 3M™, wskaźnik Uvicator, regulacja śrubowa, wentylowany, odblaskowy, G3000NUV-R');
        $this->variant($reflective, 'G3000NUV-R-RD', 'czerwony');
        $colours->forceFill(['purchase_price' => 90, 'manufacturer' => '3M'])->save();
        $reflective->forceFill(['purchase_price' => 60, 'manufacturer' => '3M'])->save();

        $rows = app(ProductMatchService::class)->rankProducts('Hełm ochronny 3M G3000NUV-RD, kolor czerwony', collect([$reflective, $colours]));

        $this->assertSame($colours->id, (int) $rows[0]['product']->id);
    }

    public function test_unsaved_card_is_read_without_variants(): void
    {
        $card = new Product(['sku' => 'ARMEN 9007 S1', 'name' => 'ARMEN 9007 S1']);

        $this->assertSame(['1010'], $this->fuzzy()->missingVariantCodes(self::QUERY, $card));
    }

    private function fuzzy(): ProductModelFuzzy
    {
        return $this->app->make(ProductModelFuzzy::class);
    }

    private function card(string $name): Product
    {
        return Product::query()->create(['sku' => $name, 'name' => $name, 'manufacturer' => 'ARTRA', 'currency' => 'PLN']);
    }

    private function variant(Product $card, string $sku, string $label, bool $removed = false): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:1', 'remote_id' => $sku,
            'sku' => $sku, 'label' => $label, 'purchase_price' => 10, 'currency' => 'PLN',
            'removed_at' => $removed ? now() : null,
        ]);
    }
}
