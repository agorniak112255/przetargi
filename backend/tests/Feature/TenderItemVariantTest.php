<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\TenderPricingService;
use App\Support\OfferPricing;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Wariant karty w pozycji przetargu (etap A łączenia wariantów, 28.09.2026): ręczny wybór tylko wariantu bieżącej
 * karty, cena oferty i marża z ceny wariantu, wybór wariantu wskazanego w wymaganiu przy dopasowaniu, ręczny wybór
 * przetrwa ponowne dopasowanie tej samej karty. Pozycja z kartą bez wariantów liczy się jak dotąd.
 */
final class TenderItemVariantTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIREMENT = 'buty firmy ARTRA model ARMEN 9007 1010 S1';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_variant_of_another_card_or_removed_variant_is_rejected(): void
    {
        $card = $this->card('KASK-1', 40);
        $other = $this->card('KASK-2', 40);
        $foreign = $this->variant($other, 'KASK-2-RD', 'czerwony', 50);
        $removed = $this->variant($card, 'KASK-1-WH', 'biały', 55, removed: true);
        $tender = $this->tender();
        $item = $this->item($tender, $card, 48);

        $this->patchJson($this->url($tender, $item), ['main_variant_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('main_variant_id');
        $this->patchJson($this->url($tender, $item), ['main_variant_id' => $removed->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('main_variant_id');
        $this->patchJson($this->url($tender, $item), ['main_variant_id' => 999999])
            ->assertStatus(422);
        // wariant nowej karty w tym samym żądaniu — sprawdzany względem karty z żądania
        $this->patchJson($this->url($tender, $item), ['main_product_id' => $other->id, 'main_variant_id' => $foreign->id])
            ->assertOk()
            ->assertJsonPath('main_variant_id', $foreign->id);

        $this->patchJson($this->url($tender, $item), ['main_product_id' => null, 'main_variant_id' => $foreign->id])
            ->assertStatus(422);
    }

    public function test_choosing_a_variant_prices_the_offer_and_margin_from_it_and_exposes_the_lists(): void
    {
        $card = $this->card('KASK-1', 40);
        $red = $this->variant($card, 'KASK-1-RD', 'czerwony', 50);
        $this->variant($card, 'KASK-1-YL', 'żółty', 60);
        $this->variant($card, 'KASK-1-WH', 'biały', 55, removed: true);
        $tender = $this->tender();
        $item = $this->item($tender, $card, 48);

        $expectedOffer = OfferPricing::fromPurchase(50, 20);
        $this->patchJson($this->url($tender, $item), ['main_variant_id' => $red->id])
            ->assertOk()
            ->assertJsonPath('main_variant_id', $red->id)
            ->assertJsonPath('main_variant_label', 'czerwony')
            ->assertJsonPath('main_variant_sku', 'KASK-1-RD')
            ->assertJsonPath('main_variant_source', 'manual')
            ->assertJsonPath('main_variant.id', $red->id)
            ->assertJsonPath('main_variant.purchase_price_pln', 50)
            ->assertJsonCount(2, 'main_product.active_variants')
            ->assertJsonPath('main_product.active_variants.0.sku', 'KASK-1-RD')
            ->assertJsonPath('offer_price', number_format((float) $expectedOffer, 2, '.', ''));

        $fresh = $item->fresh();
        $this->assertEquals($expectedOffer, (float) $fresh->offer_price);
        $this->assertEquals(round(($expectedOffer - 50) / $expectedOffer * 100, 2), (float) $fresh->margin_percent);

        $this->getJson("/api/tenders/{$tender->id}")
            ->assertOk()
            ->assertJsonPath('tender.items.0.main_variant.sku', 'KASK-1-RD')
            ->assertJsonPath('tender.items.0.main_variant_label', 'czerwony')
            ->assertJsonCount(2, 'tender.items.0.main_product.active_variants')
            ->assertJsonPath('tender.items.0.main_product.active_variants.1.purchase_price_pln', 60);
    }

    public function test_show_hides_a_variant_left_on_a_repointed_card(): void
    {
        $card = $this->card('KASK-1', 40);
        $other = $this->card('KASK-2', 30);
        $red = $this->variant($card, 'KASK-1-RD', 'czerwony', 50);
        $tender = $this->tender();
        $item = $this->item($tender, $card, 60);
        $item->update(['main_variant_id' => $red->id, 'main_variant_label' => 'czerwony', 'main_variant_source' => 'manual']);
        // scalanie kart przepina pozycję zapisem bez zdarzeń modelu
        TenderItem::query()->whereKey($item->id)->toBase()->update(['main_product_id' => $other->id]);

        $this->getJson("/api/tenders/{$tender->id}")
            ->assertOk()
            ->assertJsonPath('tender.items.0.main_product.sku', 'KASK-2')
            ->assertJsonPath('tender.items.0.main_variant', null)
            ->assertJsonCount(0, 'tender.items.0.main_product.active_variants');
    }

    public function test_panel_resave_of_the_same_card_keeps_the_variant_price(): void
    {
        $card = $this->card('KASK-1', 40);
        $yellow = $this->variant($card, 'KASK-1-YL', 'żółty', 60);
        $tender = $this->tender();
        $item = $this->item($tender, $card, 48);

        $this->patchJson($this->url($tender, $item), ['main_variant_id' => $yellow->id])->assertOk();
        $this->patchJson($this->url($tender, $item), ['main_product_id' => $card->id, 'quantity' => 3])->assertOk();

        $fresh = $item->fresh();
        $this->assertSame($yellow->id, $fresh->main_variant_id);
        $this->assertEquals(OfferPricing::fromPurchase(60, 20), (float) $fresh->offer_price);
    }

    public function test_clearing_the_variant_returns_to_the_card_price_and_changing_the_card_clears_it(): void
    {
        $card = $this->card('KASK-1', 40);
        $other = $this->card('KASK-2', 30);
        $yellow = $this->variant($card, 'KASK-1-YL', 'żółty', 60);
        $tender = $this->tender();
        $item = $this->item($tender, $card, 48);

        $this->patchJson($this->url($tender, $item), ['main_variant_id' => $yellow->id])->assertOk();
        $this->patchJson($this->url($tender, $item), ['main_variant_id' => null])
            ->assertOk()
            ->assertJsonPath('main_variant_id', null)
            ->assertJsonPath('main_variant', null);
        $fresh = $item->fresh();
        $this->assertNull($fresh->main_variant_label);
        $this->assertEquals(OfferPricing::fromPurchase(40, 20), (float) $fresh->offer_price);

        $this->patchJson($this->url($tender, $item), ['main_variant_id' => $yellow->id])->assertOk();
        $this->patchJson($this->url($tender, $item), ['main_product_id' => $other->id])->assertOk();
        $fresh = $item->fresh();
        $this->assertNull($fresh->main_variant_id);
        $this->assertNull($fresh->main_variant_sku);
        $this->assertNull($fresh->main_variant_source);
        $this->assertEquals(OfferPricing::fromPurchase(30, 20), (float) $fresh->offer_price);
    }

    public function test_explicit_offer_price_wins_over_the_variant_price(): void
    {
        $card = $this->card('KASK-1', 40);
        $yellow = $this->variant($card, 'KASK-1-YL', 'żółty', 60);
        $tender = $this->tender();
        $item = $this->item($tender, $card, 48);

        $this->patchJson($this->url($tender, $item), ['main_variant_id' => $yellow->id, 'offer_price' => 100])->assertOk();

        $fresh = $item->fresh();
        $this->assertEquals(100.0, (float) $fresh->offer_price);
        $this->assertEquals(40.0, (float) $fresh->margin_percent, 'marża od zakupu wariantu 60');
    }

    public function test_target_margin_change_prices_missing_offer_from_the_variant(): void
    {
        $card = $this->card('KASK-1', 40);
        $yellow = $this->variant($card, 'KASK-1-YL', 'żółty', 60);
        $tender = $this->tender();
        $item = $this->item($tender, $card, null);
        $item->update([
            'main_variant_id' => $yellow->id, 'main_variant_label' => 'żółty',
            'main_variant_sku' => 'KASK-1-YL', 'main_variant_source' => 'manual',
        ]);

        app(TenderPricingService::class)->applyTargetMarginChange($tender, 20, 50, SupplierSpecialMask::revealing());

        $this->assertEquals(OfferPricing::fromPurchase(60, 50), (float) $item->fresh()->offer_price);
    }

    public function test_card_without_variants_prices_as_before(): void
    {
        $card = $this->card('KASK-1', 40);
        $tender = $this->tender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Kask', 'quantity' => 1, 'status' => 'brak',
        ]);

        $this->patchJson($this->url($tender, $item), ['main_product_id' => $card->id])
            ->assertOk()
            ->assertJsonPath('main_variant_id', null)
            ->assertJsonPath('main_variant', null)
            ->assertJsonCount(0, 'main_product.active_variants');

        $fresh = $item->fresh();
        $this->assertEquals(OfferPricing::fromPurchase(40, 20), (float) $fresh->offer_price);
        $this->assertEquals(round((48 - 40) / 48 * 100, 2), (float) $fresh->margin_percent);
    }

    public function test_match_picks_the_single_variant_named_in_the_requirement(): void
    {
        $this->aiWithoutMatches();
        $card = $this->shoe('ARMEN 9007 1010 S1', 30.44);
        $black = $this->variant($card, 'ARMEN-9007-1010-42', 'czarny 42', 35);
        $this->variant($card, 'ARMEN-9007-6660-42', 'zielony 42', 32);
        $tender = $this->tender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => self::REQUIREMENT, 'quantity' => 1, 'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => false])->assertOk();

        $fresh = $item->fresh();
        $this->assertSame((int) $card->id, (int) $fresh->main_product_id);
        $this->assertSame($black->id, $fresh->main_variant_id);
        $this->assertSame('auto', $fresh->main_variant_source);
        $this->assertSame('ARMEN-9007-1010-42', $fresh->main_variant_sku);
        $this->assertEquals(OfferPricing::fromPurchase(35, 20), (float) $fresh->offer_price);
    }

    public function test_match_leaves_no_variant_when_the_requirement_names_several_rows(): void
    {
        $this->aiWithoutMatches();
        $card = $this->shoe('ARMEN 9007 1010 S1', 30.44);
        $this->variant($card, 'ARMEN-9007-1010-42', 'czarny 42', 35);
        $this->variant($card, 'ARMEN-9007-1010-43', 'czarny 43', 36);
        $tender = $this->tender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => self::REQUIREMENT, 'quantity' => 1, 'status' => 'brak',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => false])->assertOk();

        $fresh = $item->fresh();
        $this->assertSame((int) $card->id, (int) $fresh->main_product_id);
        $this->assertNull($fresh->main_variant_id, 'rozmiar dobiera handlowiec');
        $this->assertEquals(OfferPricing::fromPurchase(30.44, 20), (float) $fresh->offer_price);
    }

    public function test_manual_variant_survives_rematch_of_the_same_card(): void
    {
        $this->aiWithoutMatches();
        $card = $this->shoe('ARMEN 9007 1010 S1', 30.44);
        $this->variant($card, 'ARMEN-9007-1010-42', 'czarny 42', 35);
        $green = $this->variant($card, 'ARMEN-9007-6660-42', 'zielony 42', 32);
        $tender = $this->tender();
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => self::REQUIREMENT, 'quantity' => 1,
            'main_product_id' => $card->id, 'ai_match_percent' => 90, 'match_source' => 'heuristic', 'status' => 'matched',
            'main_variant_id' => $green->id, 'main_variant_label' => 'zielony 42',
            'main_variant_sku' => 'ARMEN-9007-6660-42', 'main_variant_source' => 'manual',
        ]);

        $this->postJson("/api/tenders/{$tender->id}/match", ['only_empty' => false])->assertOk();

        $fresh = $item->fresh();
        $this->assertSame((int) $card->id, (int) $fresh->main_product_id);
        $this->assertSame($green->id, $fresh->main_variant_id, 'wybór handlowca zostaje');
        $this->assertSame('manual', $fresh->main_variant_source);
        $this->assertEquals(OfferPricing::fromPurchase(32, 20), (float) $fresh->offer_price);
    }

    private function aiWithoutMatches(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(['matches' => []]);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(
            static fn (array $sets): array => array_fill(0, count($sets), ['matches' => []])
        );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    private function url(Tender $tender, TenderItem $item): string
    {
        return "/api/tenders/{$tender->id}/items/{$item->id}";
    }

    private function card(string $sku, float $purchase): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Hełm ochronny '.$sku, 'manufacturer' => 'Test', 'category' => 'Głowa',
            'description' => 'Hełm ochronny', 'catalog_price_net' => $purchase * 1.5, 'purchase_price' => $purchase,
            'currency' => 'PLN', 'stock' => 5, 'enrichment_status' => Product::ENRICHMENT_DONE, 'enriched_at' => now(),
        ]);
    }

    private function shoe(string $sku, float $purchase): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $sku, 'manufacturer' => 'ARTRA', 'category' => 'Obuwie',
            'description' => 'Półbuty bezpieczne '.$sku.' z podnoskiem.', 'norms' => 'EN ISO 20345 S1',
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR, 'catalog_price_net' => $purchase * 1.5,
            'purchase_price' => $purchase, 'currency' => 'PLN', 'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE, 'enriched_at' => now()->subYear(),
        ]);
    }

    private function variant(Product $card, string $sku, string $label, float $price, bool $removed = false): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:1', 'remote_id' => $sku,
            'sku' => $sku, 'label' => $label, 'purchase_price' => $price, 'currency' => 'PLN',
            'sort_order' => ProductVariant::query()->where('product_id', $card->id)->count(),
            'removed_at' => $removed ? now() : null,
        ]);
    }

    private function tender(): Tender
    {
        return Tender::query()->create([
            'number' => 'PRZ/WAR/'.uniqid(),
            'title' => 'Warianty',
            'client_id' => Client::query()->create(['name' => 'Klient'])->id,
            'owner_id' => User::factory()->create()->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'target_margin_percent' => 20,
            'last_activity_at' => now(),
        ]);
    }

    private function item(Tender $tender, Product $card, ?float $offer): TenderItem
    {
        return TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Hełm ochronny', 'quantity' => 1,
            'main_product_id' => $card->id, 'offer_price' => $offer, 'ai_match_percent' => 90,
            'ai_match_reasons' => [['code' => 'test', 'label' => 'test', 'points' => 90]],
            'match_source' => 'manual', 'status' => 'matched',
        ]);
    }
}
