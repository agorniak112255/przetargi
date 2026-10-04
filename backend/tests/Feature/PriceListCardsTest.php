<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Client;
use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\PriceListCards;
use App\Services\PriceListDeletionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Karty cennika = price_lists.product_ids (ostatni import) i karty ze slotem ceny z pliku tego cennika. Usunięcie
 * cennika Canis zostawiło na produkcji 118 kart bez ceny: miały slot pliku tego cennika, a nie było ich w product_ids.
 */
final class PriceListCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $sku = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
        $this->user = User::factory()->withRole('admin')->create();
    }

    public function test_ids_join_last_import_and_file_slots_of_the_list(): void
    {
        $inImport = $this->card();
        $slotOnly = $this->card();
        $both = $this->card();
        $otherListSlot = $this->card();
        $list = $this->priceList('Canis', [$both->id, $inImport->id, $both->id, 0, -3]);
        $other = $this->priceList('Inny', []);
        $this->fileSlot($slotOnly, $list);
        $this->fileSlot($both, $list);
        $this->fileSlot($otherListSlot, $other);
        // slot konta B2B z tym samym price_list_id (wpis wspólny) nie jest kartą pliku
        $this->b2bSlot($this->card(), $this->account(), $list->id);

        $expected = [$inImport->id, $slotOnly->id, $both->id];
        sort($expected);
        $cards = app(PriceListCards::class);

        $this->assertSame($expected, $cards->ids($list));
        $this->assertSame([$otherListSlot->id], $cards->ids($other));
        $this->assertSame(
            [$list->id => $expected, $other->id => [$otherListSlot->id]],
            $cards->idsByList([$list, $other]),
        );
        $this->assertSame([], $cards->idsByList([]));
    }

    public function test_index_counts_cards_with_file_slot_outside_last_import(): void
    {
        Sanctum::actingAs($this->user);
        $done = $this->card(['enrichment_status' => Product::ENRICHMENT_DONE]);
        $slotOnlyDone = $this->card(['enrichment_status' => Product::ENRICHMENT_DONE]);
        $slotOnlyQueued = $this->card(['enrichment_status' => Product::ENRICHMENT_QUEUED]);
        $list = $this->priceList('Canis', [$done->id]);
        $this->fileSlot($slotOnlyDone, $list);
        $this->fileSlot($slotOnlyQueued, $list);

        $row = collect($this->getJson('/api/price-lists')->assertOk()->json())->firstWhere('id', $list->id);

        $this->assertSame(3, $row['product_count']);
        $this->assertSame(3, $row['enrichment_total']);
        $this->assertSame(2, $row['enrichment_done']);
        $this->assertSame(1, $row['enrichment_queued']);
        $this->assertArrayNotHasKey('product_ids', $row);
    }

    public function test_products_filter_by_price_list_uses_last_import_and_slots(): void
    {
        Sanctum::actingAs($this->user);
        $inImport = $this->card(['manufacturer' => 'Canis']);
        // cennik wielomarkowy: karta innej marki ze slotem tego cennika też jest jego kartą
        $slotOnly = $this->card(['manufacturer' => 'Ansell']);
        $outside = $this->card(['manufacturer' => 'Canis']);
        $list = $this->priceList('Canis', [$inImport->id]);
        $this->fileSlot($slotOnly, $list);

        $ids = collect($this->getJson("/api/products?price_list={$list->id}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$inImport->id, $slotOnly->id], $ids);
        $this->assertNotContains($outside->id, $ids);

        // łączy się z innymi filtrami (AND)
        $this->getJson("/api/products?price_list={$list->id}&manufacturer=Ansell")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $slotOnly->id);

        // nieznany cennik i cennik bez kart — pusta lista, nie błąd
        $this->getJson('/api/products?price_list=999999')->assertOk()->assertJsonCount(0, 'data');
        $empty = $this->priceList('Pusty', []);
        $this->getJson("/api/products?price_list={$empty->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_delete_removes_slot_only_cards_and_keeps_cards_with_other_reasons(): void
    {
        Sanctum::actingAs($this->user);
        $account = $this->account();
        $exclusive = $this->card();
        $slotOnly = $this->card();
        $inOtherList = $this->card();
        $b2bLinked = $this->card();
        $otherSlot = $this->card();
        $tenderMain = $this->card();
        $tenderCompanion = $this->card();

        $list = $this->priceList('Canis', [$exclusive->id, $inOtherList->id, $b2bLinked->id, $otherSlot->id, $tenderMain->id]);
        $this->priceList('Inny', [$inOtherList->id]);
        foreach ([$exclusive, $slotOnly, $inOtherList, $b2bLinked, $otherSlot, $tenderMain, $tenderCompanion] as $card) {
            $this->fileSlot($card, $list);
        }
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => 'r-1', 'product_id' => $b2bLinked->id]);
        $this->b2bSlot($otherSlot, $account, null);
        $this->tenderItem($tenderMain, null);
        $this->tenderItem($this->card(), $tenderCompanion);

        $response = $this->deleteJson("/api/price-lists/{$list->id}")
            ->assertOk()
            ->assertJsonPath('products_deleted', 2)
            ->assertJsonPath('products_kept_shared', 5)
            ->assertJsonPath('products_kept_tenders', 2);
        $this->assertStringContainsString('w tym użytych w przetargach: 2', (string) $response->json('message'));

        $this->assertDatabaseMissing('price_lists', ['id' => $list->id]);
        $this->assertDatabaseMissing('products', ['id' => $exclusive->id]);
        $this->assertDatabaseMissing('products', ['id' => $slotOnly->id]);
        foreach ([$inOtherList, $b2bLinked, $otherSlot, $tenderMain, $tenderCompanion] as $card) {
            $this->assertDatabaseHas('products', ['id' => $card->id]);
            // zostają bez slotu ceny z usuniętego cennika
            $this->assertFalse(ProductSourcePrice::query()
                ->where('product_id', $card->id)
                ->where('source_key', ProductSourcePrice::SOURCE_FILE)
                ->exists());
        }
        $this->assertTrue(ProductSourcePrice::query()->where('product_id', $otherSlot->id)->where('source_key', ProductSourcePrice::b2bKey($account->id))->exists());
        $this->assertDatabaseHas('tender_items', ['main_product_id' => $tenderMain->id]);
        $this->assertDatabaseHas('tender_items', ['companion_product_id' => $tenderCompanion->id]);
    }

    public function test_preview_matches_delete_and_reports_side_effects(): void
    {
        Sanctum::actingAs($this->user);
        $account = $this->account();
        $withImage = $this->card();
        $withErp = $this->card();
        $withSubstitute = $this->card();
        $slotOnly = $this->card();
        $inOtherList = $this->card();
        $b2bLinked = $this->card();
        $otherSlot = $this->card();
        $inTender = $this->card();
        $other = $this->card();

        $list = $this->priceList('Canis', [
            $withImage->id, $withErp->id, $withSubstitute->id, $inOtherList->id, $b2bLinked->id, $otherSlot->id,
            $inTender->id, 987654,
        ]);
        $this->priceList('Inny', [$inOtherList->id]);
        $this->fileSlot($slotOnly, $list);
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => 'r-2', 'product_id' => $b2bLinked->id]);
        // karta w innym cenniku i z powiązaniem B2B liczy się raz — w pierwszym powodzie
        B2bProductLink::query()->create(['b2b_account_id' => $account->id, 'remote_id' => 'r-3', 'product_id' => $inOtherList->id]);
        $this->b2bSlot($otherSlot, $account, null);
        $this->tenderItem($inTender, null);
        ProductImage::query()->create([
            'product_id' => $withImage->id,
            'path' => 'products/'.$withImage->id.'/a.png',
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $item = ErpItem::query()->create(['xl_gid' => 1, 'code' => 'XL-1', 'name' => 'Towar', 'unit' => 'szt', 'archived' => false]);
        ErpItemLink::query()->create(['erp_item_id' => $item->id, 'product_id' => $withErp->id, 'status' => ErpItemLink::STATUS_CONFIRMED, 'method' => ErpItemLink::METHOD_MANUAL]);
        // odrzucone powiązanie nie ostrzega
        $rejectedItem = ErpItem::query()->create(['xl_gid' => 2, 'code' => 'XL-2', 'name' => 'Towar 2', 'unit' => 'szt', 'archived' => false]);
        ErpItemLink::query()->create(['erp_item_id' => $rejectedItem->id, 'product_id' => $withImage->id, 'status' => ErpItemLink::STATUS_REJECTED, 'method' => ErpItemLink::METHOD_MANUAL]);
        ProductSubstitute::query()->create(['main_product_id' => $other->id, 'substitute_product_id' => $withSubstitute->id, 'type' => 'tanszy']);

        $expected = [
            'products_total' => 8,
            'products_to_delete' => 4,
            'kept_other_price_lists' => 1,
            'kept_b2b' => 1,
            'kept_other_slots' => 1,
            'kept_tenders' => 1,
            'not_in_last_import' => 1,
            'to_delete_with_erp_links' => 1,
            'to_delete_with_substitutes' => 1,
            'to_delete_with_images' => 1,
        ];
        $this->assertSame($expected, app(PriceListDeletionService::class)->preview($list));

        $this->getJson("/api/price-lists/{$list->id}?deletion_preview=1")
            ->assertOk()
            ->assertJsonPath('deletion_preview', $expected)
            ->assertJsonPath('id', $list->id);
        $this->assertArrayNotHasKey('deletion_preview', $this->getJson("/api/price-lists/{$list->id}")->assertOk()->json());

        // podgląd niczego nie zmienia, a usunięcie idzie tym samym podziałem
        $this->assertDatabaseHas('product_source_prices', ['product_id' => $slotOnly->id, 'price_list_id' => $list->id]);
        $this->deleteJson("/api/price-lists/{$list->id}")
            ->assertOk()
            ->assertJsonPath('products_deleted', 4)
            ->assertJsonPath('products_kept_shared', 4)
            ->assertJsonPath('products_kept_tenders', 1);
        $this->assertDatabaseMissing('products', ['id' => $slotOnly->id]);
        $this->assertDatabaseHas('erp_item_links', ['erp_item_id' => $item->id, 'product_id' => null]);
        $this->assertDatabaseMissing('product_substitutes', ['substitute_product_id' => $withSubstitute->id]);
    }

    public function test_backfill_bhp_attributes_price_list_option_includes_slot_cards(): void
    {
        $inImport = $this->card();
        $slotOnly = $this->card();
        $this->card();
        $list = $this->priceList('Canis', [$inImport->id]);
        $this->fileSlot($slotOnly, $list);

        $this->artisan('products:backfill-bhp-attributes', ['--price-list' => $list->id, '--report' => true])
            ->expectsOutputToContain('Produkty: 2')
            ->assertSuccessful();
    }

    public function test_enrich_price_list_queues_cards_with_file_slot_outside_last_import(): void
    {
        $inImport = $this->card(['enrichment_status' => Product::ENRICHMENT_FAILED]);
        $slotOnly = $this->card(['enrichment_status' => Product::ENRICHMENT_FAILED]);
        $otherList = $this->card(['enrichment_status' => Product::ENRICHMENT_FAILED]);
        $list = $this->priceList('Canis', [$inImport->id]);
        $this->fileSlot($slotOnly, $list);
        $this->fileSlot($otherList, $this->priceList('Inny', []));

        $result = app(ProductEnrichmentService::class)
            ->enqueuePriceList($list, $this->user, false, false);

        $this->assertSame([$inImport->id, $slotOnly->id], $result['product_ids']);
        $this->assertSame(Product::ENRICHMENT_QUEUED, $slotOnly->fresh()?->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_FAILED, $otherList->fresh()?->enrichment_status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(array $attributes = []): Product
    {
        $this->sku++;

        return Product::query()->create([
            'sku' => 'PLC-'.$this->sku,
            'name' => 'Karta '.$this->sku,
            'manufacturer' => 'Canis',
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
            'stock' => 1,
            ...$attributes,
        ]);
    }

    /**
     * @param  list<int>  $productIds
     */
    private function priceList(string $manufacturer, array $productIds): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer,
            'version' => 'v1',
            'original_filename' => mb_strtolower($manufacturer).'.xlsx',
            'rows_total' => count($productIds),
            'products_created' => count($productIds),
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => $productIds,
        ]);
    }

    private function fileSlot(Product $card, PriceList $list): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $list->id,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);
    }

    private function b2bSlot(Product $card, B2bAccount $account, ?int $priceListId): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::b2bKey($account->id),
            'b2b_account_id' => $account->id,
            'price_list_id' => $priceListId,
            'catalog_price_net' => 20,
            'purchase_price' => 18,
            'currency' => 'PLN',
            'checked_at' => now(),
        ]);
    }

    private function account(): B2bAccount
    {
        return B2bAccount::query()->firstOrCreate(['username' => 'jan'], [
            'password' => 'sekret',
            'sites' => ['b2b.anro.net.pl'],
            'connector' => 'anro',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function tenderItem(Product $main, ?Product $companion): void
    {
        static $line = 0;
        $tender = Tender::query()->create([
            'number' => 'PRZ/'.random_int(1, 999999), 'title' => 'Test', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $this->user->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
        TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => ++$line,
            'requirement' => 'Rękawice',
            'main_product_id' => $main->id,
            'companion_product_id' => $companion?->id,
        ]);
    }
}
