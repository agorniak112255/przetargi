<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\CardMatchCandidate;
use App\Models\CardRedirect;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImportExclusion;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Usuwanie z listy kart konta dostawcy (29.09.2026): karta 3M z pozycją P4S traci tylko pozycje P4S, karta tylko
 * z P4S jest usuwana, karta bez pozycji P4S zostaje. Blokady — tylko pozycji P4S.
 */
final class ProductSourceDetachTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private B2bAccount $p4s;

    private B2bAccount $mmm;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole('admin')->create();
        $this->p4s = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'sites' => ['b2b.p4s.pl'], 'connector' => 'p4s']);
        $this->mmm = B2bAccount::query()->create(['username' => 'mmm', 'password' => 'x', 'sites' => ['3mb2b.pl'], 'connector' => '3m']);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        foreach (File::glob(storage_path('app/repair-backups/source-detach-*.json')) as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_shared_card_loses_only_distributor_positions(): void
    {
        $card = $this->card('7100258913', 'Aura półmaska FFP3 9332+');
        $this->link($this->mmm, '7100258913', $card);
        $this->slot($card, $this->mmm, 15.01);
        $this->link($this->p4s, '12593', $card, '9332+');
        $this->slot($card, $this->p4s, 18.40);
        ProductShopCard::query()->create(['product_id' => $card->id, 'b2b_account_id' => $this->p4s->id, 'source_url' => 'https://b2b.p4s.pl/p/12593', 'fields' => [], 'synced_at' => now()]);
        ProductShopCard::query()->create(['product_id' => $card->id, 'b2b_account_id' => $this->mmm->id, 'source_url' => 'https://3mb2b.pl/p/1', 'fields' => [], 'synced_at' => now()]);
        ProductIdentifier::query()->create([
            'product_id' => $card->id, 'source_key' => 'b2b:'.$this->p4s->id, 'position_key' => '12593',
            'type' => ProductIdentifier::TYPE_SOURCE_CODE, 'value' => '9332+',
        ]);
        CardRedirect::query()->create(['source_key' => 'b2b:'.$this->p4s->id, 'position_key' => '12593', 'product_id' => $card->id, 'reason' => 'merge']);
        ProductVariant::query()->create([
            'product_id' => $card->id, 'kind' => 'size', 'b2b_account_id' => $this->p4s->id, 'source' => 'b2b:'.$this->p4s->id,
            'remote_id' => '12593-L', 'label' => 'L', 'purchase_price' => 18.40, 'currency' => 'PLN',
        ]);
        $card->forceFill(['shop_source_url' => 'https://b2b.p4s.pl/p/12593'])->save();
        $list = $this->priceList('P4S B2B', [$card->id]);
        $this->p4s->forceFill(['last_price_list_id' => $list->id])->save();

        $this->postJson('/api/products/delete', ['product_ids' => [$card->id], 'skip_import' => true, 'b2b_account' => $this->p4s->id])
            ->assertOk()
            ->assertJsonPath('deleted', 0)
            ->assertJsonPath('detached', 1)
            ->assertJsonPath('product_ids_detached', [$card->id])
            ->assertJsonPath('refused', [])
            ->assertJsonPath('positions_excluded', 1)
            ->assertJsonPath('message', 'Odpięto B2B P4S od 1 karty — karta zostaje z pozostałymi źródłami.');

        $card->refresh();
        $this->assertNotNull($card);
        $this->assertNull($card->shop_source_url);
        $this->assertSame(['7100258913'], B2bProductLink::query()->where('product_id', $card->id)->pluck('remote_id')->all());
        $this->assertSame(['b2b:'.$this->mmm->id], ProductSourcePrice::query()->where('product_id', $card->id)->pluck('source_key')->all());
        $this->assertSame([$this->mmm->id], ProductShopCard::query()->where('product_id', $card->id)->pluck('b2b_account_id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame(0, ProductIdentifier::query()->where('product_id', $card->id)->count());
        $this->assertSame(0, CardRedirect::query()->count());
        $this->assertSame(0, ProductVariant::query()->count());
        $this->assertSame([], $list->fresh()->product_ids);
        $this->assertSame(15.01, (float) $card->purchase_price);

        $exclusion = ProductImportExclusion::query()->sole();
        $this->assertSame('b2b:'.$this->p4s->id, $exclusion->source_key);
        $this->assertSame('12593', $exclusion->position_key);
        $this->assertSame('b2b:'.$this->p4s->id, $exclusion->product_snapshot['detached_source']);

        $this->assertCount(1, File::glob(storage_path('app/repair-backups/source-detach-b2b'.$this->p4s->id.'-*.json')));

        $this->getJson('/api/import-exclusions')
            ->assertOk()
            ->assertJsonPath('data.0.detached_source', 'b2b:'.$this->p4s->id);
    }

    public function test_card_only_from_distributor_is_deleted_and_card_without_it_is_skipped(): void
    {
        $only = $this->card('GG501SGAF-EU', 'Gogle 3M Gear 501');
        $this->link($this->p4s, '76197', $only);
        $this->slot($only, $this->p4s, 40.0);
        // wiersz pliku wycofany z cennika — karta nie ma już źródła z pliku, a z listy P4S blokujemy tylko P4S
        $oldList = $this->priceList('3M', []);
        ProductIdentifier::query()->create([
            'product_id' => $only->id, 'source_key' => 'file:'.$oldList->id, 'price_list_id' => $oldList->id, 'position_key' => 'GG501',
            'type' => ProductIdentifier::TYPE_SOURCE_CODE, 'value' => 'GG501', 'removed_at' => now(),
        ]);
        $other = $this->card('7000032493', 'Okulary 3M 2800');
        $this->link($this->mmm, '7000032493', $other);

        $this->postJson('/api/products/delete', ['product_ids' => [$only->id, $other->id], 'skip_import' => true, 'b2b_account' => $this->p4s->id])
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('detached', 0)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('positions_excluded', 1)
            ->assertJsonPath('message', 'Usunięto 1 kartę tylko z B2B P4S. Pominięto 1 kartę bez pozycji B2B P4S.');

        $this->assertNull($only->fresh());
        $this->assertNotNull($other->fresh());
        $this->assertSame(1, B2bProductLink::query()->where('product_id', $other->id)->count());
        $exclusion = ProductImportExclusion::query()->sole();
        $this->assertSame('b2b:'.$this->p4s->id, $exclusion->source_key);
        $this->assertArrayNotHasKey('detached_source', $exclusion->product_snapshot);
    }

    public function test_single_delete_without_skip_detaches_and_records_nothing(): void
    {
        $card = $this->card('7100134314', 'Aura 9332+Gen3');
        $this->link($this->mmm, '7100134314', $card);
        $this->link($this->p4s, '87877', $card);

        $this->deleteJson("/api/products/{$card->id}", ['b2b_account' => $this->p4s->id])
            ->assertOk()
            ->assertJsonPath('detached', 1)
            ->assertJsonPath('positions_excluded', 0);

        $this->assertNotNull($card->fresh());
        $this->assertSame(0, B2bProductLink::query()->where('b2b_account_id', $this->p4s->id)->count());
        $this->assertSame(0, ProductImportExclusion::query()->count());
    }

    public function test_sole_manufacturer_account_is_not_detached(): void
    {
        $card = $this->card('7100074368', 'Gogle 3M Goggle Gear 500');
        $this->link($this->mmm, '7100074368', $card);
        $this->link($this->p4s, '76197', $card);

        $response = $this->postJson('/api/products/delete', ['product_ids' => [$card->id], 'skip_import' => true, 'b2b_account' => $this->mmm->id])
            ->assertOk()
            ->assertJsonPath('detached', 0)
            ->assertJsonPath('deleted', 0)
            ->assertJsonPath('positions_excluded', 0)
            ->assertJsonPath('refused.0.id', $card->id);

        $this->assertStringContainsString('jedynym producentem', (string) $response->json('message'));
        $this->assertSame(2, B2bProductLink::query()->where('product_id', $card->id)->count());
        $this->assertSame(0, ProductImportExclusion::query()->count());
        $this->assertSame([], File::glob(storage_path('app/repair-backups/source-detach-*.json')));
    }

    public function test_tender_card_whose_price_would_change_is_refused_and_others_still_detach(): void
    {
        // 3M bez ceny, P4S ceną karty; po odpięciu wygrałby cennik z pliku innej marki — karta jest w przetargu
        $tenderCard = $this->card('7100175101', 'Hełm 3M SecureFit X5000');
        $this->link($this->mmm, '7100175101', $tenderCard);
        $this->link($this->p4s, '236510', $tenderCard);
        $this->slot($tenderCard, $this->p4s, 50.0);
        ProductSourcePrice::query()->create([
            'product_id' => $tenderCard->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $this->priceList('Hurtownia', [])->id,
            'purchase_price' => 80.0, 'currency' => 'PLN',
        ]);
        $tenderCard->forceFill(['purchase_price' => 50.0])->save();
        $this->tenderItem($tenderCard);

        $plain = $this->card('7100175511', 'Hełm 3M SecureFit żółty');
        $this->link($this->mmm, '7100175511', $plain);
        $this->link($this->p4s, '236520', $plain);

        $response = $this->postJson('/api/products/delete', ['product_ids' => [$tenderCard->id, $plain->id], 'skip_import' => true, 'b2b_account' => $this->p4s->id])
            ->assertOk()
            ->assertJsonPath('detached', 1)
            ->assertJsonPath('product_ids_detached', [$plain->id])
            ->assertJsonPath('refused.0.id', $tenderCard->id)
            ->assertJsonPath('positions_excluded', 1);

        $this->assertStringContainsString('pozycjach przetargów', (string) $response->json('message'));
        $this->assertSame(50.0, (float) $tenderCard->fresh()->purchase_price);
        $this->assertSame(1, B2bProductLink::query()->where('product_id', $tenderCard->id)->where('b2b_account_id', $this->p4s->id)->count());
        $this->assertSame(1, ProductSourcePrice::query()->where('product_id', $tenderCard->id)->where('source_key', 'b2b:'.$this->p4s->id)->count());
        $this->assertSame(['236520'], ProductImportExclusion::query()->pluck('position_key')->all());
    }

    public function test_running_account_sync_refuses_and_changes_nothing(): void
    {
        $card = $this->card('7000089312', 'Aura 9332+ zbiorcze');
        $this->link($this->mmm, '7000089312', $card);
        $this->link($this->p4s, '85397', $card);
        B2bSyncRun::query()->create(['b2b_account_id' => $this->p4s->id, 'status' => B2bSyncRun::STATUS_RUNNING, 'trigger' => 'manual', 'started_at' => now()]);

        $this->postJson('/api/products/delete', ['product_ids' => [$card->id], 'b2b_account' => $this->p4s->id])
            ->assertStatus(422);

        $this->assertSame(2, B2bProductLink::query()->where('product_id', $card->id)->count());
    }

    public function test_stale_match_candidates_of_the_card_are_removed(): void
    {
        $card = $this->card('7100258913', 'Aura półmaska FFP3 9332+');
        $this->link($this->mmm, '7100258913', $card);
        $this->link($this->p4s, '12593', $card);
        $distributorCard = $this->card('9332+', 'Półmaska P4S');
        $stale = CardMatchCandidate::query()->create([
            'source_product_id' => $distributorCard->id, 'target_product_id' => $card->id, 'status' => CardMatchCandidate::STATUS_PENDING,
            'matched_source_key' => 'b2b:'.$this->p4s->id,
        ]);
        $kept = CardMatchCandidate::query()->create([
            'source_product_id' => $this->card('X-1')->id, 'target_product_id' => $card->id, 'status' => CardMatchCandidate::STATUS_PENDING,
            'matched_source_key' => 'b2b:'.$this->mmm->id,
        ]);

        $this->postJson('/api/products/delete', ['product_ids' => [$card->id], 'b2b_account' => $this->p4s->id])
            ->assertOk()
            ->assertJsonPath('detached', 1);

        $this->assertNull($stale->fresh());
        $this->assertNotNull($kept->fresh());
    }

    private function card(string $sku, ?string $name = null): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name ?? 'Karta '.$sku,
            'manufacturer' => '3M',
            'purchase_price' => 15.01,
            'catalog_price_net' => 30.01,
            'currency' => 'PLN',
            'stock' => 0,
        ]);
    }

    private function link(B2bAccount $account, string $remoteId, Product $card, ?string $remoteSku = null): void
    {
        B2bProductLink::query()->create([
            'b2b_account_id' => $account->id, 'remote_id' => $remoteId, 'product_id' => $card->id,
            'remote_sku' => $remoteSku ?? $remoteId, 'remote_name' => $card->name,
        ]);
    }

    private function slot(Product $card, B2bAccount $account, float $price): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::b2bKey((int) $account->id),
            'purchase_price' => $price, 'currency' => 'PLN', 'checked_at' => now(),
        ]);
    }

    /**
     * @param  list<int>  $productIds
     */
    private function priceList(string $manufacturer, array $productIds): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer,
            'manufacturer_key' => PriceList::manufacturerKey($manufacturer),
            'version' => '2026',
            'original_filename' => 'cennik.xlsx',
            'rows_total' => count($productIds),
            'products_created' => 0,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => $productIds,
        ]);
    }

    private function tenderItem(Product $card): void
    {
        $tender = Tender::query()->create([
            'number' => 'PRZ/1', 'title' => 'Test', 'client_id' => Client::query()->create(['name' => 'K'])->id,
            'owner_id' => $this->admin->id, 'status' => 'wycena', 'ai_percent' => 0, 'last_activity_at' => now(),
        ]);
        TenderItem::query()->create(['tender_id' => $tender->id, 'line_no' => 1, 'requirement' => 'Hełm', 'main_product_id' => $card->id]);
    }
}
