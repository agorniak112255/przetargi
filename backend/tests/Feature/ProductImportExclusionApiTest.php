<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImportExclusion;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Catalog\CardRedirectStore;
use App\Services\Catalog\ProductIdentifierStore;
use App\Services\Catalog\ProductImportExclusions;
use App\Services\ProductDeletionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * „Usuń i pomijaj przy imporcie” (28.09.2026): zapis blokad pozycji usuwanej karty (B2B, kody wierszy pliku, wpis
 * „sku” karty bez kodów wierszy), podgląd grup i przywracanie. Pomijanie w synchronizacji B2B i imporcie pliku —
 * osobne testy (B2bSyncImportExclusionTest, PriceListImportExclusionTest).
 */
final class ProductImportExclusionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create(['username' => 'p4s', 'password' => 'x', 'sites' => ['b2b.p4s.pl'], 'connector' => 'p4s']);
    }

    public function test_delete_with_skip_import_records_every_source_position(): void
    {
        Sanctum::actingAs($this->admin);
        $card = $this->card('25960', 'Paczka pierwszej pomocy CEDERROTH');
        B2bProductLink::query()->create([
            'b2b_account_id' => $this->account->id, 'remote_id' => 'P4S-100', 'product_id' => $card->id,
            'remote_sku' => '25960', 'remote_name' => 'Paczka CEDERROTH',
        ]);
        $withRows = $this->priceList('CEDERROTH');
        $this->fileIdentifier($card, $withRows, '25960', 'rozm. uniwersalny');
        // slot pliku z kodami wierszy tego cennika — bez zapasowego wpisu „sku”
        $this->fileSlot($card, $withRows);

        $this->deleteJson("/api/products/{$card->id}", ['skip_import' => true])
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('positions_excluded', 2)
            ->assertJsonPath('message', 'Usunięto produkt 25960.');

        $this->assertNull($card->fresh());
        $rows = ProductImportExclusion::query()->orderBy('source_key')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows->pluck('deletion_id')->unique()->count());

        $b2b = $rows->firstWhere('source_key', 'b2b:'.$this->account->id);
        $this->assertNotNull($b2b);
        $this->assertSame('P4S-100', $b2b->position_key);
        $this->assertSame(ProductImportExclusion::KIND_POSITION, $b2b->match_kind);
        $this->assertSame('25960', $b2b->remote_sku);
        $this->assertSame('Paczka CEDERROTH', $b2b->position_label);
        $this->assertSame((int) $card->id, $b2b->product_id);
        $this->assertSame('25960', $b2b->product_sku);
        $this->assertSame((int) $this->admin->id, $b2b->deleted_by);

        $file = $rows->firstWhere('source_key', 'file:'.$withRows->id);
        $this->assertNotNull($file);
        $this->assertSame('file:cederroth', $file->scope_key);
        $this->assertSame('rozm. uniwersalny', $file->position_label);
        $this->assertSame((int) $withRows->id, $file->price_list_id);
    }

    public function test_legacy_file_slot_without_rows_is_matched_by_card_sku(): void
    {
        $card = $this->card('ABC-1');
        $legacy = $this->priceList('Canis');
        $this->fileSlot($card, $legacy);

        $result = app(ProductDeletionService::class)->deleteMany([$card->id], $this->admin, true);

        $this->assertSame(1, $result['positions_excluded']);
        $row = ProductImportExclusion::query()->sole();
        $this->assertSame(ProductImportExclusion::KIND_SKU, $row->match_kind);
        $this->assertSame('ABC-1', $row->position_key);
        $this->assertSame('file:canis', $row->scope_key);
    }

    public function test_plain_delete_records_nothing(): void
    {
        Sanctum::actingAs($this->admin);
        $card = $this->card('PLAIN-1');
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-1', 'product_id' => $card->id]);

        $this->deleteJson("/api/products/{$card->id}")
            ->assertOk()
            ->assertJsonPath('positions_excluded', 0)
            ->assertJsonPath('message', 'Usunięto produkt PLAIN-1.');

        $this->assertSame(0, ProductImportExclusion::query()->count());
    }

    public function test_bulk_delete_with_skip_import_groups_per_card_and_reports_zero_for_cards_without_sources(): void
    {
        Sanctum::actingAs($this->admin);
        $a = $this->card('BULK-A');
        $b = $this->card('BULK-B');
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-A', 'product_id' => $a->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-B', 'product_id' => $b->id]);

        $this->postJson('/api/products/delete', ['product_ids' => [$a->id, $b->id], 'skip_import' => true])
            ->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonPath('positions_excluded', 2);
        $this->assertSame(2, ProductImportExclusion::query()->distinct()->count('deletion_id'));

        $manual = $this->card('MANUAL-1');
        $this->postJson('/api/products/delete', ['product_ids' => [$manual->id], 'skip_import' => true])
            ->assertOk()
            ->assertJsonPath('positions_excluded', 0);
        $this->assertSame(0, ProductImportExclusion::query()->where('product_id', $manual->id)->count());
    }

    public function test_positions_owned_by_another_card_are_not_excluded(): void
    {
        $card = $this->card('OWN-1');
        $other = $this->card('OTHER-1');
        // pozycja B2B, którą mapa połączeń kieruje na inną kartę — należy do tamtej
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-MAP', 'product_id' => $card->id]);
        CardRedirect::query()->create([
            'source_key' => 'b2b:'.$this->account->id, 'position_key' => 'R-MAP', 'b2b_account_id' => $this->account->id,
            'product_id' => $other->id, 'reason' => CardRedirect::REASON_MERGE, 'target_snapshot' => CardRedirectStore::snapshot($other),
        ]);
        // pozycja pliku ze starym (zniknętym) identyfikatorem na tej karcie i aktywnym na innej (zmieniony EAN)
        $list = $this->priceList('ANRO');
        $this->fileIdentifier($card, $list, 'ROW-1', null, '5901111111111', now());
        $this->fileIdentifier($other, $list, 'ROW-1', null, '5902222222222');
        // własna pozycja zostaje zablokowana
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-OWN', 'product_id' => $card->id]);

        $positions = app(ProductImportExclusions::class)->positionsOf([$card->id]);

        $this->assertSame(['R-OWN'], array_column($positions[(int) $card->id] ?? [], 'position_key'));
    }

    public function test_list_groups_positions_and_restore_lifts_the_block(): void
    {
        Sanctum::actingAs($this->admin);
        $card = $this->card('LIST-1', 'Rękawice LIST');
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-L1', 'product_id' => $card->id, 'remote_sku' => 'L1']);
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-L2', 'product_id' => $card->id, 'remote_sku' => 'L2']);
        app(ProductDeletionService::class)->deleteMany([$card->id], $this->admin, true);
        $exclusions = app(ProductImportExclusions::class);
        $this->assertNotNull($exclusions->forAccount((int) $this->account->id)->position('r-l1'));

        $response = $this->getJson('/api/import-exclusions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.sku', 'LIST-1')
            ->assertJsonPath('data.0.product.name', 'Rękawice LIST')
            ->assertJsonPath('data.0.active_count', 2)
            ->assertJsonPath('data.0.positions.0.source_label', 'B2B P4S')
            ->assertJsonPath('data.0.deleted_by', $this->admin->name);
        $ids = array_column($response->json('data.0.positions'), 'id');
        $this->getJson('/api/import-exclusions?q=nie-ma-takiego')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/import-exclusions?q=L2')->assertOk()->assertJsonPath('meta.total', 1);

        $this->postJson('/api/import-exclusions/restore', ['ids' => [$ids[0]]])
            ->assertOk()
            ->assertJsonPath('restored', 1);
        $this->getJson('/api/import-exclusions')->assertJsonPath('data.0.active_count', 1);
        $this->postJson('/api/import-exclusions/restore', ['ids' => $ids])->assertOk()->assertJsonPath('restored', 1);
        $this->postJson('/api/import-exclusions/restore', ['ids' => $ids])->assertOk()->assertJsonPath('restored', 0);

        $this->getJson('/api/import-exclusions')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/import-exclusions?status=restored')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.positions.0.restored_by', $this->admin->name);
        $this->assertTrue($exclusions->forAccount((int) $this->account->id)->isEmpty());
        $this->assertNull($exclusions->activeB2bPosition((int) $this->account->id, 'R-L1'));
    }

    public function test_deleting_again_after_restore_reactivates_the_same_position(): void
    {
        $first = $this->card('AGAIN-1');
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-AGAIN', 'product_id' => $first->id]);
        $exclusions = app(ProductImportExclusions::class);
        app(ProductDeletionService::class)->deleteMany([$first->id], $this->admin, true);
        $row = ProductImportExclusion::query()->sole();
        $exclusions->registerHits([(int) $row->id]);
        $exclusions->restore([(int) $row->id], $this->admin);

        // synchronizacja założyła kartę od nowa, a człowiek znów ją usuwa z pominięciem
        $second = $this->card('AGAIN-1');
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-AGAIN', 'product_id' => $second->id]);
        app(ProductDeletionService::class)->deleteMany([$second->id], $this->admin, true);

        $again = ProductImportExclusion::query()->sole();
        $this->assertSame((int) $row->id, (int) $again->id);
        $this->assertNotSame($row->deletion_id, $again->deletion_id);
        $this->assertNull($again->restored_at);
        $this->assertSame(0, $again->hits);
        $this->assertSame((int) $second->id, $again->product_id);
        $this->assertNotNull($exclusions->activeB2bPosition((int) $this->account->id, 'R-AGAIN'));
    }

    public function test_file_block_survives_price_list_entry_being_recreated(): void
    {
        $card = $this->card('KEEP-1');
        $list = $this->priceList('Cederroth');
        $this->fileIdentifier($card, $list, 'KEEP-1');
        app(ProductDeletionService::class)->deleteMany([$card->id], $this->admin, true);

        $list->delete();
        $this->assertNull(ProductImportExclusion::query()->sole()->price_list_id);
        $fresh = $this->priceList('CEDERROTH');

        $set = app(ProductImportExclusions::class)->forPriceList($fresh);
        $this->assertNotNull($set->position('keep-1'));
        $this->assertNull($set->sku('KEEP-1'));
        $this->assertTrue(app(ProductImportExclusions::class)->forPriceList($this->priceList('ANRO'))->isEmpty());
    }

    public function test_list_filters_sorts_and_lists_filter_options(): void
    {
        Sanctum::actingAs($this->admin);
        $other = User::factory()->withRole('admin')->create(['name' => 'Zenon Kasujący']);
        $alpha = $this->card('ALPHA-1', 'Rękawice Alpha');
        $beta = $this->card('BETA-1', 'Buty Beta');
        $beta->forceFill(['manufacturer' => 'UVEX'])->save();
        $list = $this->priceList('CEDERROTH');
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-A', 'product_id' => $alpha->id]);
        $this->fileIdentifier($alpha, $list, 'ROW-A');
        B2bProductLink::query()->create(['b2b_account_id' => $this->account->id, 'remote_id' => 'R-B', 'product_id' => $beta->id]);
        $this->travelTo(now()->setDate(2026, 9, 20)->setTime(10, 0));
        app(ProductDeletionService::class)->deleteMany([$alpha->id], $this->admin, true);
        $this->travelTo(now()->setDate(2026, 9, 25)->setTime(10, 0));
        app(ProductDeletionService::class)->deleteMany([$beta->id], $other, true);
        $this->travelBack();
        $betaRow = ProductImportExclusion::query()->where('position_key', 'R-B')->sole();
        app(ProductImportExclusions::class)->registerHits([(int) $betaRow->id]);

        // domyślnie: najnowsze usunięcie pierwsze
        $this->getJson('/api/import-exclusions')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.sort', 'deleted_at')
            ->assertJsonPath('meta.dir', 'desc')
            ->assertJsonPath('data.0.product.sku', 'BETA-1');
        $this->getJson('/api/import-exclusions?sort=sku&dir=asc')->assertJsonPath('data.0.product.sku', 'ALPHA-1');
        $this->getJson('/api/import-exclusions?sort=hits')->assertJsonPath('meta.dir', 'desc')->assertJsonPath('data.0.product.sku', 'BETA-1');
        $this->getJson('/api/import-exclusions?sort=positions')->assertJsonPath('data.0.product.sku', 'ALPHA-1');

        // źródło zawęża grupy i pozycje w grupie; reszta pozycji karty liczona jako ukryta
        $this->getJson('/api/import-exclusions?source=file:cederroth')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.sku', 'ALPHA-1')
            ->assertJsonCount(1, 'data.0.positions')
            ->assertJsonPath('data.0.positions.0.position_key', 'ROW-A')
            ->assertJsonPath('data.0.positions.0.scope_key', 'file:cederroth')
            ->assertJsonPath('data.0.hidden_count', 1)
            ->assertJsonPath('data.0.active_count', 2);
        $this->getJson('/api/import-exclusions?source=b2b:'.$this->account->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.hidden_count', 0);

        $this->getJson('/api/import-exclusions?hits=hit')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.product.sku', 'BETA-1');
        $this->getJson('/api/import-exclusions?hits=never')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.product.sku', 'ALPHA-1');
        $this->getJson('/api/import-exclusions?manufacturer=UVEX')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.product.sku', 'BETA-1');
        $this->getJson('/api/import-exclusions?deleted_by='.$other->id)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.product.sku', 'BETA-1');
        $this->getJson('/api/import-exclusions?from=2026-09-21')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.product.sku', 'BETA-1');
        $this->getJson('/api/import-exclusions?to=2026-09-20')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.product.sku', 'ALPHA-1');
        $this->getJson('/api/import-exclusions?kind=deleted')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/import-exclusions?kind=detached')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/import-exclusions?per_page=50')->assertJsonPath('meta.per_page', 50);

        // wybór w filtrach z całej tabeli, niezależnie od bieżących filtrów
        $response = $this->getJson('/api/import-exclusions?q=nie-ma-takiego')->assertJsonPath('meta.total', 0);
        $sources = collect($response->json('facets.sources'))->keyBy('key');
        $this->assertSame('B2B P4S', $sources['b2b:'.$this->account->id]['label']);
        $this->assertSame(2, $sources['b2b:'.$this->account->id]['active']);
        $this->assertSame('Cennik z pliku CEDERROTH', $sources['file:cederroth']['label']);
        $this->assertSame([['name' => 'CEDERROTH', 'cards' => 1], ['name' => 'UVEX', 'cards' => 1]], $response->json('facets.manufacturers'));
        $this->assertEqualsCanonicalizing([$this->admin->id, $other->id], array_column($response->json('facets.users'), 'id'));

        $this->getJson('/api/import-exclusions?sort=hacked')->assertUnprocessable();
        $this->getJson('/api/import-exclusions?source=cokolwiek')->assertUnprocessable();
        $this->getJson('/api/import-exclusions?per_page=1000')->assertUnprocessable();
    }

    public function test_exclusions_need_delete_permission(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->getJson('/api/import-exclusions')->assertForbidden();
        $this->postJson('/api/import-exclusions/restore', ['ids' => [1]])->assertForbidden();
    }

    private function card(string $sku, ?string $name = null): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name ?? 'Karta '.$sku,
            'manufacturer' => 'CEDERROTH',
            'catalog_price_net' => 35.2,
            'purchase_price' => 24.5,
            'currency' => 'PLN',
            'stock' => 0,
        ]);
    }

    private function priceList(string $manufacturer): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => $manufacturer,
            'manufacturer_key' => PriceList::manufacturerKey($manufacturer),
            'version' => '2026',
            'original_filename' => 'cennik.xlsx',
            'rows_total' => 1,
            'products_created' => 1,
            'products_updated' => 0,
            'rows_skipped' => 0,
        ]);
    }

    private function fileSlot(Product $card, PriceList $list): void
    {
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'price_list_id' => $list->id,
            'purchase_price' => 24.5,
            'catalog_price_net' => 30,
            'currency' => 'PLN',
        ]);
    }

    private function fileIdentifier(Product $card, PriceList $list, string $position, ?string $label = null, ?string $value = null, mixed $removedAt = null): void
    {
        ProductIdentifier::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductIdentifierStore::fileKey((int) $list->id),
            'price_list_id' => $list->id,
            'position_key' => $position,
            'type' => $value !== null ? ProductIdentifier::TYPE_EAN : ProductIdentifier::TYPE_SOURCE_CODE,
            'value' => $value ?? $position,
            'variant_label' => $label,
            'removed_at' => $removedAt,
        ]);
    }
}
