<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteVariant;
use App\Services\B2b\B2bVariantConnector;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Jeden stały wpis konta B2B w Cennikach: tworzenie, aktualizacja tego samego wiersza, przejęcie dawnego wpisu
 * i blokada usuwania/edycji, dopóki konto istnieje.
 */
final class B2bAccountPriceListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
    }

    public function test_first_run_creates_one_list_and_next_runs_update_the_same_row(): void
    {
        $account = $this->account('fakeshop');
        $shop = new FakeShopPriceListConnector;
        $shop->items = ['1' => ['sku' => 'A-1', 'price' => 10.0]];
        $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00', B2bAccount::SYNC_TIMEZONE));

        $first = $this->runSync($account, $shop);

        $list = PriceList::query()->sole();
        $productA = Product::query()->where('sku', 'A-1')->sole();
        $this->assertSame($first['price_list_id'], $list->id);
        $this->assertSame($list->id, $account->fresh()->last_price_list_id);
        $this->assertSame('Fake Shop', $list->manufacturer);
        $this->assertSame('fakeshop.example.test (API)', $list->original_filename);
        $this->assertSame('B2B · aktualizacja 2026-09-14 10:00', $list->version);
        $this->assertSame($this->user->id, $list->imported_by);
        $this->assertSame([$productA->id], $list->product_ids);
        $this->assertSame(1, $list->rows_total);
        $this->assertSame(1, $list->products_created);
        $this->assertSame(0, $list->products_updated);
        $this->assertTrue(ProductPriceHistory::query()
            ->where('product_id', $productA->id)
            ->where('price_list_id', $list->id)
            ->where('b2b_sync_run_id', $first['sync_run_id'])
            ->where('source', 'b2b:fakeshop')
            ->exists());

        $this->travelTo(CarbonImmutable::parse('2026-09-15 02:10', B2bAccount::SYNC_TIMEZONE));
        $shop->items['1']['price'] = 12.0;
        $shop->items['2'] = ['sku' => 'B-2', 'price' => 5.0];
        $second = $this->runSync($account, $shop);

        $list->refresh();
        $productB = Product::query()->where('sku', 'B-2')->sole();
        $this->assertSame(1, PriceList::query()->count());
        $this->assertSame($list->id, $second['price_list_id']);
        $this->assertSame('B2B · aktualizacja 2026-09-15 02:10', $list->version);
        $this->assertSame([$productA->id, $productB->id], $list->product_ids);
        $this->assertSame(2, $list->rows_total);
        $this->assertSame(1, $list->products_created);
        $this->assertSame(1, $list->products_updated);
        $this->assertSame(1, $list->prices_changed);
        $this->assertCount(1, $list->price_changes);
        $this->assertSame('A-1', $list->price_changes[0]['sku']);
        $this->assertEquals(10.0, $list->price_changes[0]['purchase_old']);
        $this->assertEquals(12.0, $list->price_changes[0]['purchase_new']);
        $this->assertSame('A-1', $list->updated_products[0]['sku']);
        $this->assertTrue($list->updated_products[0]['price_changed']);
        $this->assertContains('purchase_price', $list->updated_products[0]['fields']);
        $this->assertTrue(ProductPriceHistory::query()
            ->where('product_id', $productA->id)
            ->where('price_list_id', $list->id)
            ->where('b2b_sync_run_id', $second['sync_run_id'])
            ->exists());

        // przebieg bez zmian: ta sama pozycja, data sprawdzenia i liczniki ostatniego przebiegu
        $this->travelTo(CarbonImmutable::parse('2026-09-16 02:10', B2bAccount::SYNC_TIMEZONE));
        $shop->items['3'] = ['sku' => 'C-3', 'price' => null];
        $this->runSync($account, $shop);

        $list->refresh();
        $this->assertSame(1, PriceList::query()->count());
        $this->assertSame('B2B · aktualizacja 2026-09-16 02:10', $list->version);
        $this->assertSame(2, $list->rows_total);
        $this->assertSame(0, $list->products_created);
        $this->assertSame(0, $list->products_updated);
        $this->assertSame(0, $list->prices_changed);
        $this->assertSame(1, $list->rows_skipped);
        $this->assertSame([], $list->price_changes);
        $this->assertSame([], $list->updated_products);
        $this->assertSame(
            [['reason' => 'brak ceny w B2B', 'row' => 3, 'sheet' => null, 'sku' => 'C-3', 'name' => 'Produkt C-3']],
            $list->skipped_details,
        );
        $this->assertSame(['C-3: brak ceny w B2B'], $list->errors);
    }

    public function test_adopts_newest_leftover_list_that_is_not_another_accounts_list(): void
    {
        $older = $this->apiList('fakeshop.example.test', 'B2B 2026-09-10 02:00');
        $newer = $this->apiList('fakeshop.example.test', 'B2B 2026-09-12 02:00');
        $other = $this->account('fakeshop', 'inne');
        $owned = $this->apiList('fakeshop.example.test', 'B2B · aktualizacja 2026-09-13 02:00');
        $other->forceFill(['last_price_list_id' => $owned->id])->save();
        $account = $this->account('fakeshop');
        $shop = new FakeShopPriceListConnector;
        $shop->items = ['1' => ['sku' => 'A-1', 'price' => 10.0]];

        $result = $this->runSync($account, $shop);

        $this->assertSame($newer->id, $result['price_list_id']);
        $this->assertSame($newer->id, $account->fresh()->last_price_list_id);
        $this->assertSame(3, PriceList::query()->count());
        $this->assertSame([Product::query()->where('sku', 'A-1')->sole()->id], $newer->fresh()->product_ids);
        $this->assertNull($older->fresh()->product_ids);
        $this->assertNull($owned->fresh()->product_ids);
        $this->assertSame($owned->id, $other->fresh()->last_price_list_id);
    }

    public function test_list_deleted_by_someone_is_created_again_and_linked(): void
    {
        $account = $this->account('fakeshop');
        $shop = new FakeShopPriceListConnector;
        $shop->items = ['1' => ['sku' => 'A-1', 'price' => 10.0]];
        $first = $this->runSync($account, $shop);
        PriceList::query()->whereKey($first['price_list_id'])->delete();

        $second = $this->runSync($account, $shop);

        $list = PriceList::query()->sole();
        $this->assertNotSame($first['price_list_id'], $list->id);
        $this->assertSame($list->id, $second['price_list_id']);
        $this->assertSame($list->id, $account->fresh()->last_price_list_id);
        $this->assertSame([Product::query()->where('sku', 'A-1')->sole()->id], $list->product_ids);
    }

    public function test_variant_connector_list_shows_variant_price_changes(): void
    {
        $account = $this->account('fakesignlist');
        $sign = new FakeSignPriceListConnector;
        $sign->prices = ['101' => 1.00, '102' => 2.00];
        $this->runSync($account, $sign);

        $card = Product::query()->where('sku', 'BB014')->sole();
        $list = PriceList::query()->sole();
        $this->assertSame([$card->id], $list->product_ids);
        $this->assertSame(1, $list->products_created);

        $sign->prices['102'] = 2.50;
        $this->runSync($account, $sign);

        $list->refresh();
        $this->assertSame(1, PriceList::query()->count());
        $this->assertSame([$card->id], $list->product_ids);
        $this->assertSame(1, $list->products_updated);
        $this->assertSame(1, $list->prices_changed);
        $this->assertCount(1, $list->price_changes);
        $change = $list->price_changes[0];
        $this->assertSame('BB014', $change['sku']);
        $this->assertSame('A4 \ folia', $change['variant_label']);
        $this->assertEquals(2.0, $change['purchase_old']);
        $this->assertEquals(2.5, $change['purchase_new']);
        $this->assertNull($change['catalog_old']);
        $this->assertSame(['wersje'], $list->updated_products[0]['fields']);
        $this->assertTrue($list->updated_products[0]['price_changed']);
    }

    public function test_dry_run_creates_no_list(): void
    {
        $account = $this->account('fakeshop');
        $shop = new FakeShopPriceListConnector;
        $shop->items = ['1' => ['sku' => 'A-1', 'price' => 10.0]];

        $result = $this->runSync($account, $shop, dryRun: true);

        $this->assertNull($result['price_list_id']);
        $this->assertSame(0, PriceList::query()->count());
        $this->assertNull($account->fresh()->last_price_list_id);
    }

    public function test_failed_run_without_writes_leaves_no_list_and_failure_after_writes_updates_it(): void
    {
        $account = $this->account('fakeshop');
        $shop = new FakeShopPriceListConnector;
        $shop->loginError = 'Nieprawidłowy login lub hasło';

        $this->assertInstanceOf(RuntimeException::class, $this->runSyncExpectingFailure($account, $shop));
        $this->assertSame(0, PriceList::query()->count());
        $this->assertNull($account->fresh()->last_price_list_id);

        $shop->loginError = null;
        $shop->items = ['1' => ['sku' => 'A-1', 'price' => 10.0], '2' => ['sku' => 'B-2', 'price' => 5.0, 'fatal' => true]];
        $this->assertInstanceOf(B2bFatalException::class, $this->runSyncExpectingFailure($account, $shop));

        $list = PriceList::query()->sole();
        $this->assertSame($list->id, $account->fresh()->last_price_list_id);
        $this->assertSame([Product::query()->where('sku', 'A-1')->sole()->id], $list->product_ids);
        $this->assertSame(1, $list->products_created);
        $this->assertSame('failed', B2bSyncRun::query()->latest('id')->firstOrFail()->status);
    }

    public function test_account_list_cannot_be_deleted_or_edited_while_account_exists(): void
    {
        Sanctum::actingAs($this->user);
        $product = $this->anroProduct();
        $list = $this->apiList('b2b.anro.net.pl', 'B2B · aktualizacja 2026-09-14 02:10', [$product->id]);
        $account = $this->account('anro');
        $account->forceFill(['last_price_list_id' => $list->id])->save();
        $fileList = PriceList::query()->create(['manufacturer' => 'Anro', 'version' => '2026', 'original_filename' => 'anro.xlsx']);

        $this->deleteJson("/api/price-lists/{$list->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nie można usunąć cennika konta B2B (Anro · jan) — najpierw usuń konto w zakładce Cenniki → B2B.');
        $this->patchJson("/api/price-lists/{$list->id}", ['manufacturer' => 'Inny'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nie można edytować cennika konta B2B (Anro · jan) — nazwę i wersję ustawia pobieranie z konta. Najpierw usuń konto w zakładce Cenniki → B2B.');

        $this->assertSame('Anro', $list->fresh()->manufacturer);
        $this->assertSame('Anro', $product->fresh()->manufacturer);

        $rows = collect($this->getJson('/api/price-lists')->assertOk()->json())->keyBy('id');
        $this->assertSame(['id' => $account->id, 'username' => 'jan', 'connector_label' => 'Anro'], $rows[$list->id]['b2b_account']);
        $this->assertNull($rows[$fileList->id]['b2b_account']);
        $this->getJson("/api/price-lists/{$list->id}")->assertOk()->assertJsonPath('b2b_account.username', 'jan');
    }

    public function test_api_list_of_connector_is_protected_until_account_has_its_own_list(): void
    {
        Sanctum::actingAs($this->user);
        $leftover = $this->apiList('b2b.anro.net.pl', 'B2B 2026-09-14 18:25');
        $account = $this->account('anro');

        // konto przed pierwszym pobraniem po zmianie przejmie ten wpis
        $this->deleteJson("/api/price-lists/{$leftover->id}")->assertStatus(422);
        $rows = collect($this->getJson('/api/price-lists')->assertOk()->json())->keyBy('id');
        $this->assertSame('jan', $rows[$leftover->id]['b2b_account']['username']);

        $own = $this->apiList('b2b.anro.net.pl', 'B2B · aktualizacja 2026-09-15 02:10');
        $account->forceFill(['last_price_list_id' => $own->id])->save();

        $this->deleteJson("/api/price-lists/{$leftover->id}")->assertOk();
        $this->assertNotNull($own->fresh());
    }

    public function test_list_becomes_ordinary_after_account_is_deleted(): void
    {
        Sanctum::actingAs($this->user);
        $product = $this->anroProduct();
        $list = $this->apiList('b2b.anro.net.pl', 'B2B · aktualizacja 2026-09-14 02:10', [$product->id]);
        $account = $this->account('anro');
        $account->forceFill(['last_price_list_id' => $list->id])->save();

        $this->deleteJson("/api/b2b-accounts/{$account->id}")->assertOk();

        $this->assertNotNull($list->fresh());
        $this->assertNotNull($product->fresh());
        $rows = collect($this->getJson('/api/price-lists')->assertOk()->json())->keyBy('id');
        $this->assertNull($rows[$list->id]['b2b_account']);

        $this->deleteJson("/api/price-lists/{$list->id}")
            ->assertOk()
            ->assertJsonPath('products_deleted', 1);
        $this->assertNull($list->fresh());
        $this->assertNull($product->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function runSync(B2bAccount $account, B2bConnector $connector, bool $dryRun = false): array
    {
        return app(B2bAccountSyncRunner::class)->run($account->fresh(), dryRun: $dryRun, delayMs: 0, connector: $connector);
    }

    private function runSyncExpectingFailure(B2bAccount $account, B2bConnector $connector): Throwable
    {
        try {
            $this->runSync($account, $connector);
        } catch (Throwable $e) {
            return $e;
        }
        $this->fail('Przebieg miał się zakończyć błędem.');
    }

    private function account(string $connector, string $username = 'jan'): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [$connector === 'anro' ? 'b2b.anro.net.pl' : $connector.'.example.test'],
            'connector' => $connector,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    /**
     * @param  list<int>|null  $productIds
     */
    private function apiList(string $host, string $version, ?array $productIds = null): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => 'Anro',
            'version' => $version,
            'original_filename' => $host.' (API)',
            'product_ids' => $productIds,
            'rows_total' => count($productIds ?? []),
        ]);
    }

    private function anroProduct(): Product
    {
        return Product::query()->create([
            'sku' => 'N/IF005',
            'name' => 'Alarm pożarowy',
            'manufacturer' => 'Anro',
            'catalog_price_net' => 40.80,
            'purchase_price' => 36.72,
            'currency' => 'PLN',
        ]);
    }
}

final class FakeShopPriceListConnector implements B2bConnector
{
    /** @var array<string|int, array{sku: string, price: float|null, fatal?: bool}> */
    public array $items = [];

    public ?string $loginError = null;

    public static function key(): string
    {
        return 'fakeshop';
    }

    public static function label(): string
    {
        return 'Fake Shop';
    }

    public static function host(): string
    {
        return 'fakeshop.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void
    {
        if ($this->loginError !== null) {
            throw new RuntimeException($this->loginError);
        }
    }

    public function products(): iterable
    {
        foreach ($this->items as $id => $item) {
            yield new B2bRemoteProduct(remoteId: (string) $id, sku: $item['sku'], name: 'Produkt '.$item['sku']);
        }
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Fake Shop';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $item = $this->items[$product->remoteId];
        if ($item['fatal'] ?? false) {
            throw new B2bFatalException('Utracono sesję konta.');
        }

        return $item['price'] !== null ? new B2bRemotePrice($item['price']) : null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}

final class FakeSignPriceListConnector implements B2bVariantConnector
{
    /** @var array<string|int, float> id wersji => cena konta netto */
    public array $prices = [];

    public static function key(): string
    {
        return 'fakesignlist';
    }

    public static function label(): string
    {
        return 'Fake Sign';
    }

    public static function host(): string
    {
        return 'fakesignlist.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        $versions = [];
        foreach (array_keys($this->prices) as $id) {
            $versions[] = ['id' => (string) $id, 'name' => $this->versionLabel((string) $id)];
        }

        yield new B2bRemoteProduct(remoteId: 'BB014', sku: 'BB014', name: 'Znak BB014', raw: ['versions' => $versions]);
    }

    public function totalProducts(): int
    {
        return 1;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Fake Sign';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }

    public function variants(B2bRemoteProduct $product): array
    {
        $variants = [];
        $i = 0;
        foreach ($this->prices as $id => $price) {
            $variants[] = new B2bRemoteVariant(
                remoteId: (string) $id,
                label: $this->versionLabel((string) $id),
                attributes: ['Format' => (string) $id === '101' ? 'A5' : 'A4', 'Podłoże' => 'folia'],
                price: new B2bRemotePrice($price),
                priceError: null,
                sourceUrl: null,
                sortOrder: $i++,
                vatRate: 23.0,
                unit: 'szt.',
            );
        }

        return $variants;
    }

    public function totalVariants(): int
    {
        return count($this->prices);
    }

    public function listedVariantIds(): ?array
    {
        return null;
    }

    public function runBudgetMinutes(): ?int
    {
        return null;
    }

    private function versionLabel(string $id): string
    {
        return ($id === '101' ? 'A5' : 'A4').' \ folia';
    }
}
