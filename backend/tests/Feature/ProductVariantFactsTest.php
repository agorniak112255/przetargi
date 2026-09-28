<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Support\ProductVariantFacts;
use App\Support\RequirementCheck\ColorChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Warianty karty dla dopasowania przetargu (etap A łączenia wariantów kolorystycznych, 28.09.2026): kody i barwy
 * z aktywnych wierszy product_variants, wybór jednego wariantu do oferty i zerowanie wariantu przy zmianie karty.
 */
final class ProductVariantFactsTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariantFacts $facts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->facts = app(ProductVariantFacts::class);
    }

    public function test_colours_and_rank_summary_come_only_from_active_rows(): void
    {
        $card = $this->card('Hełm G3000', 'G3000');
        $this->variant($card, 'G3000CUV-RD', 'czerwony');
        $this->variant($card, 'G3000CUV-GB', 'żółty');
        $this->variant($card, 'G3000CUV-BB', 'niebieski', removed: true);

        $this->assertSame(['czerwony', 'żółty'], $this->facts->colours($card));
        $this->assertSame('kolory: czerwony, żółty; kody: G3000CUV-RD, G3000CUV-GB', $this->facts->rankSummary($card));
    }

    public function test_rank_summary_is_null_for_cards_without_several_colours(): void
    {
        $sizes = $this->card('Spodnie', 'SP-1');
        $this->variant($sizes, 'SP-1-44', '44');
        $this->variant($sizes, 'SP-1-46', '46');
        $plain = $this->card('Kask', 'K-1');

        $this->assertNull($this->facts->rankSummary($sizes));
        $this->assertNull($this->facts->rankSummary($plain));
        $this->assertSame([], $this->facts->colours($sizes));
    }

    public function test_unsaved_product_runs_no_query(): void
    {
        DB::enableQueryLog();
        $this->assertSame([], $this->facts->activeRows(new Product(['name' => 'X', 'sku' => 'X'])));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_pick_requested_by_code_then_by_single_colour(): void
    {
        $card = $this->card('ARMEN', 'ARMEN-9007');
        $red = $this->variant($card, 'ARMEN-9007-1010-42', 'czarny 42');
        $this->variant($card, 'ARMEN-9007-6060-42', 'zielony 42');

        $this->assertSame($red->id, $this->facts->pickRequested($card, ['1010'], [], [])?->id);
        $this->assertSame($red->id, $this->facts->pickRequested($card, [], ['armen9007101042'], [])?->id);
        $this->assertSame($red->id, $this->facts->pickRequested($card, [], [], ['czarny'])?->id);
        $this->assertNull($this->facts->pickRequested($card, [], [], ['żółty']));
        // kod wskazuje dwa wiersze (ten sam kolor w dwóch rozmiarach) — rozmiar dobiera handlowiec
        $this->variant($card, 'ARMEN-9007-1010-43', 'czarny 43');
        $this->facts->forget();
        $this->assertNull($this->facts->pickRequested($card, ['1010'], [], []));
        $this->assertNull($this->facts->pickRequested($card, [], [], ['czarny']));
    }

    public function test_pick_requested_code_must_be_a_whole_number_in_the_variant(): void
    {
        $card = $this->card('Hełm', 'H-1');
        $this->variant($card, '7000101012', 'biały');
        $green = $this->variant($card, 'H-1-1010', 'zielony');

        $this->assertSame($green->id, $this->facts->pickRequested($card, ['1010'], [], [])?->id);
    }

    public function test_product_ids_by_variant_sku(): void
    {
        $card = $this->card('ARMEN', 'ARMEN-9007');
        $this->variant($card, 'ARMEN-9007-1010-42', 'czarny 42');
        $gone = $this->card('Inna', 'INNA');
        $this->variant($gone, 'ARMEN-9007-2020-42', 'szary 42', removed: true);

        $this->assertSame([$card->id], $this->facts->productIdsBySku(['armen90071010'], 10));
        $this->assertSame([$card->id], $this->facts->productIdsBySku(['ARMEN-9007-1010'], 10));
        $this->assertSame([], $this->facts->productIdsBySku(['1010'], 10));
    }

    public function test_colour_helpers_of_color_checker(): void
    {
        $this->assertSame(['czerwony'], ColorChecker::colorsIn('czerwony 42'));
        $this->assertSame(['żółty'], ColorChecker::requiredColours('Hełm ochronny w kolorze żółtym'));
        $this->assertSame([], ColorChecker::requiredColours('Hełm ochronny'));
    }

    public function test_changing_the_tender_item_card_clears_the_variant(): void
    {
        $card = $this->card('Hełm G3000', 'G3000');
        $other = $this->card('Kask', 'K-1');
        $variant = $this->variant($card, 'G3000CUV-RD', 'czerwony');
        $item = $this->item($card);

        $item->update([
            'main_variant_id' => $variant->id, 'main_variant_label' => 'czerwony',
            'main_variant_sku' => 'G3000CUV-RD', 'main_variant_source' => 'manual',
        ]);
        $this->assertSame($variant->id, $item->fresh()->offerVariant()?->id);

        $item->update(['offer_price' => 10]);
        $this->assertSame($variant->id, $item->fresh()->main_variant_id);

        $item->update(['main_product_id' => $other->id]);
        $fresh = $item->fresh();
        $this->assertNull($fresh->main_variant_id);
        $this->assertNull($fresh->main_variant_label);
        $this->assertNull($fresh->main_variant_sku);
        $this->assertNull($fresh->main_variant_source);
    }

    public function test_offer_variant_is_ignored_when_the_card_was_repointed_without_model_events(): void
    {
        $card = $this->card('Hełm G3000', 'G3000');
        $other = $this->card('Kask', 'K-1');
        $variant = $this->variant($card, 'G3000CUV-RD', 'czerwony');
        $item = $this->item($card);
        $item->update(['main_variant_id' => $variant->id, 'main_variant_label' => 'czerwony', 'main_variant_source' => 'auto']);

        DB::table('tender_items')->where('id', $item->id)->update(['main_product_id' => $other->id]);

        $this->assertNull($item->fresh()->offerVariant());
    }

    private function card(string $name, string $sku): Product
    {
        return Product::query()->create(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Test', 'currency' => 'PLN']);
    }

    private function variant(Product $card, string $sku, string $label, bool $removed = false): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:1', 'remote_id' => $sku,
            'sku' => $sku, 'label' => $label, 'purchase_price' => 10, 'currency' => 'PLN',
            'removed_at' => $removed ? now() : null,
        ]);
    }

    private function item(Product $card): TenderItem
    {
        $user = User::factory()->create();
        $tender = Tender::query()->create([
            'number' => 'PRZ/1', 'title' => 'Przetarg', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $user->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);

        return TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Hełm', 'main_product_id' => $card->id,
        ]);
    }
}
