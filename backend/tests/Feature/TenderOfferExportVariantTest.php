<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\TenderOfferExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Eksport oferty z wariantem karty: kod wariantu zamiast kodu karty, etykieta przy nazwie i zakup z ceny wariantu.
 * Pozycja bez wariantu eksportuje się jak dotąd.
 */
final class TenderOfferExportVariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_rows_use_variant_sku_label_and_purchase(): void
    {
        $withVariants = $this->card('KASK-1', 'Hełm ochronny KASK-1', 40);
        $yellow = ProductVariant::query()->create([
            'product_id' => $withVariants->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:1',
            'remote_id' => 'KASK-1-YL', 'sku' => 'KASK-1-YL', 'label' => 'żółty', 'purchase_price' => 60, 'currency' => 'PLN',
        ]);
        $plain = $this->card('RK-1', 'Rękawice RK-1', 10);
        $tender = Tender::query()->create([
            'number' => 'PRZ/EXPV/1', 'title' => 'Eksport', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => User::factory()->create()->id, 'status' => 'wycena', 'ai_percent' => 0,
            'target_margin_percent' => 20, 'last_activity_at' => now(),
        ]);
        TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Hełm żółty', 'quantity' => 2,
            'main_product_id' => $withVariants->id, 'offer_price' => 72, 'battlecard_substitutes' => [],
            'main_variant_id' => $yellow->id, 'main_variant_label' => 'żółty', 'main_variant_sku' => 'KASK-1-YL',
            'main_variant_source' => 'manual',
        ]);
        TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 2, 'requirement' => 'Rękawice', 'quantity' => 1,
            'main_product_id' => $plain->id, 'offer_price' => 12, 'battlecard_substitutes' => [],
        ]);

        $rows = app(TenderOfferExportService::class)->rows($tender, SupplierSpecialMask::revealing());

        $this->assertSame('KASK-1-YL', $rows[0]['sku']);
        $this->assertStringEndsWith(' — żółty', (string) $rows[0]['product_name']);
        $this->assertSame('Hełm ochronny KASK-1', $rows[0]['catalog_name']);
        $this->assertEquals(60.0, $rows[0]['purchase_price']);
        $this->assertEquals(72.0, $rows[0]['suggested_offer_price']);

        $this->assertSame('RK-1', $rows[1]['sku']);
        $this->assertStringNotContainsString(' — ', (string) $rows[1]['product_name']);
        $this->assertEquals(10.0, $rows[1]['purchase_price']);
    }

    public function test_variant_left_on_a_repointed_card_is_ignored(): void
    {
        $card = $this->card('KASK-1', 'Hełm ochronny KASK-1', 40);
        $other = $this->card('KASK-2', 'Hełm ochronny KASK-2', 30);
        $variant = ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => ProductVariant::KIND_SIZE, 'source' => 'b2b:1',
            'remote_id' => 'KASK-1-YL', 'sku' => 'KASK-1-YL', 'label' => 'żółty', 'purchase_price' => 60, 'currency' => 'PLN',
        ]);
        $tender = Tender::query()->create([
            'number' => 'PRZ/EXPV/2', 'title' => 'Eksport', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => User::factory()->create()->id, 'status' => 'wycena', 'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Hełm', 'quantity' => 1,
            'main_product_id' => $card->id, 'offer_price' => 72, 'battlecard_substitutes' => [],
            'main_variant_id' => $variant->id, 'main_variant_label' => 'żółty', 'main_variant_source' => 'auto',
        ]);
        // scalanie kart przepina pozycję zapisem bez zdarzeń modelu
        TenderItem::query()->whereKey($item->id)->toBase()->update(['main_product_id' => $other->id]);

        $rows = app(TenderOfferExportService::class)->rows($tender->fresh(), SupplierSpecialMask::revealing());

        $this->assertSame('KASK-2', $rows[0]['sku']);
        $this->assertEquals(30.0, $rows[0]['purchase_price']);
    }

    private function card(string $sku, string $name, float $purchase): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'Test', 'category' => 'BHP', 'description' => $name,
            'catalog_price_net' => $purchase * 1.5, 'purchase_price' => $purchase, 'currency' => 'PLN', 'stock' => 5,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
    }
}
