<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import B2B zapisuje cenę do slotu konta (product_source_prices „b2b:{id}”), nie wprost na kartę; slot pliku
 * zostaje nietknięty, a cena obowiązująca karty pochodzi z B2B (decyzja użytkownika 15.09.2026). Raport zmian cen
 * porównuje z poprzednią ceną tego konta. Nazwa istniejącej karty zostaje dla każdego łącznika.
 */
final class B2bSourcePriceSlotTest extends TestCase
{
    use RefreshDatabase;

    private const FILE_NAME = 'Okulary ochronne z cennika EMEA';

    private const SOURCE_NAME = 'Safety glasses from B2B shop';

    private User $user;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = $this->makeAccount('jan');
    }

    public function test_b2b_run_saves_account_slot_and_effective_price_while_file_slot_and_name_stay(): void
    {
        $card = $this->fileCard();

        $result = $this->runSync($this->account, $this->shop());

        $card->refresh();
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['prices_changed']);
        // nazwa istniejącej karty nietknięta (łącznik bez znacznika B2bKeepsExistingNames)
        $this->assertSame(self::FILE_NAME, $card->name);
        $this->assertSame(self::SOURCE_NAME, B2bProductLink::query()->where('remote_id', '1')->value('remote_name'));

        $slot = $this->slot($card, ProductSourcePrice::b2bKey($this->account->id));
        $this->assertSame('50.00', $slot->purchase_price);
        $this->assertSame('70.00', $slot->catalog_price_net);
        $this->assertSame('10.00', $slot->discount_percent);
        $this->assertSame('PLN', $slot->currency);
        $this->assertSame($this->account->id, $slot->b2b_account_id);

        // cena obowiązująca z B2B
        $this->assertSame('50.00', $card->purchase_price);
        $this->assertSame('70.00', $card->catalog_price_net);
        $this->assertSame('10.00', $card->discount_percent);

        // slot pliku bez zmian
        $file = $this->slot($card, ProductSourcePrice::SOURCE_FILE);
        $this->assertSame('40.00', $file->purchase_price);
        $this->assertSame('60.00', $file->catalog_price_net);

        $history = ProductPriceHistory::query()->where('product_id', $card->id)->sole();
        $this->assertSame('b2b:slottest', $history->source);
        $this->assertSame($result['sync_run_id'], $history->b2b_sync_run_id);
        $this->assertEquals(50, $history->purchase_price);
        $this->assertEquals(70, $history->catalog_price_net);
    }

    public function test_second_run_without_changes_is_unchanged_without_new_history(): void
    {
        $card = $this->fileCard();
        $shop = $this->shop();
        $this->runSync($this->account, $shop);
        $checkedAt = $this->slot($card, ProductSourcePrice::b2bKey($this->account->id))->checked_at;

        $this->travel(1)->days();
        $second = $this->runSync($this->account, $shop);

        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['created'] + $second['updated']);
        $this->assertSame(0, $second['prices_changed']);
        $this->assertSame(1, ProductPriceHistory::query()->where('product_id', $card->id)->count());
        $this->assertSame('50.00', $card->fresh()->purchase_price);
        // slot odnotowuje sprawdzenie — najświeższy slot B2B wyznacza cenę przy kilku kontach
        $this->assertTrue($this->slot($card, ProductSourcePrice::b2bKey($this->account->id))->checked_at->greaterThan($checkedAt));
    }

    public function test_supplier_price_change_reports_old_and_new_b2b_price_not_file_price(): void
    {
        $card = $this->fileCard();
        $shop = $this->shop();
        $this->runSync($this->account, $shop);

        $shop->net = 55.0;
        $shop->base = 75.0;
        $this->travel(1)->days();
        $second = $this->runSync($this->account, $shop);

        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, $second['prices_changed']);
        $change = B2bSyncRun::query()->findOrFail($second['sync_run_id'])->price_changes[0];
        $this->assertSame('SLOT-1', $change['sku']);
        $this->assertEquals(50.0, $change['purchase_old']);
        $this->assertEquals(55.0, $change['purchase_new']);
        $this->assertEquals(70.0, $change['catalog_old']);
        $this->assertEquals(75.0, $change['catalog_new']);
        $updated = collect(PriceList::query()->sole()->updated_products)->firstWhere('sku', 'SLOT-1');
        $this->assertNotNull($updated);
        $this->assertEquals(50.0, $updated['purchase_old']);
        $this->assertEquals(55.0, $updated['purchase_new']);

        $this->assertSame('55.00', $card->fresh()->purchase_price);
        $this->assertSame('40.00', $this->slot($card, ProductSourcePrice::SOURCE_FILE)->purchase_price);
        $this->assertTrue(ProductPriceHistory::query()
            ->where('product_id', $card->id)
            ->where('b2b_sync_run_id', $second['sync_run_id'])
            ->where('purchase_price', 55)
            ->where('catalog_price_net', 75)
            ->exists());
    }

    public function test_price_change_compares_with_slot_of_this_account_not_with_card_price_from_other_account(): void
    {
        $card = $this->fileCard();
        $shopA = $this->shop();
        $this->runSync($this->account, $shopA);

        $otherAccount = $this->makeAccount('ewa');
        $shopB = $this->shop();
        $shopB->net = 80.0;
        $shopB->base = 90.0;
        $this->travel(1)->hours();
        $this->runSync($otherAccount, $shopB);
        // najświeższy slot B2B wyznacza cenę karty
        $this->assertSame('80.00', $card->fresh()->purchase_price);

        $shopA->net = 55.0;
        $shopA->base = 75.0;
        $this->travel(1)->hours();
        $third = $this->runSync($this->account, $shopA);

        $change = B2bSyncRun::query()->findOrFail($third['sync_run_id'])->price_changes[0];
        $this->assertEquals(50.0, $change['purchase_old']);
        $this->assertEquals(55.0, $change['purchase_new']);
        $this->assertSame('55.00', $card->fresh()->purchase_price);
        $this->assertSame('80.00', $this->slot($card, ProductSourcePrice::b2bKey($otherAccount->id))->purchase_price);

        // usunięcie konta z najświeższą ceną — cena wraca do drugiego konta
        Sanctum::actingAs($this->user);
        $this->deleteJson("/api/b2b-accounts/{$this->account->id}")->assertOk();
        $this->assertSame('80.00', $card->fresh()->purchase_price);
        $this->assertSame('90.00', $card->fresh()->catalog_price_net);
    }

    public function test_new_card_from_b2b_gets_slot_card_price_and_source_name(): void
    {
        $result = $this->runSync($this->account, $this->shop());

        $card = Product::query()->where('sku', 'SLOT-1')->sole();
        $this->assertSame(1, $result['created']);
        $this->assertSame(self::SOURCE_NAME, $card->name);
        $this->assertSame('50.00', $card->purchase_price);
        $this->assertSame('70.00', $card->catalog_price_net);
        $this->assertSame('10.00', $card->discount_percent);
        $this->assertSame(1, ProductSourcePrice::query()->where('product_id', $card->id)->count());
        $this->assertSame('50.00', $this->slot($card, ProductSourcePrice::b2bKey($this->account->id))->purchase_price);
        $history = ProductPriceHistory::query()->where('product_id', $card->id)->sole();
        $this->assertEquals(50, $history->purchase_price);
        $this->assertEquals(70, $history->catalog_price_net);
    }

    public function test_dry_run_creates_no_slot_and_changes_no_price(): void
    {
        $card = $this->fileCard();
        $shop = $this->shop();
        $shop->items['2'] = ['sku' => 'SLOT-2', 'name' => self::SOURCE_NAME.' 2'];

        $result = app(B2bAccountSyncRunner::class)->run($this->account->fresh(), dryRun: true, delayMs: 0, connector: $shop);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, ProductSourcePrice::query()->count());
        $this->assertSame('40.00', $card->fresh()->purchase_price);
        $this->assertSame(self::FILE_NAME, $card->fresh()->name);
        $this->assertFalse(Product::query()->where('sku', 'SLOT-2')->exists());
        $this->assertSame(0, ProductPriceHistory::query()->count());
    }

    public function test_deleting_account_returns_card_price_to_file_slot(): void
    {
        $card = $this->fileCard();
        $shop = $this->shop();
        $shop->items['2'] = ['sku' => 'SLOT-2', 'name' => self::SOURCE_NAME.' 2'];
        $this->runSync($this->account, $shop);
        $onlyB2b = Product::query()->where('sku', 'SLOT-2')->sole();
        $this->assertSame('50.00', $card->fresh()->purchase_price);

        Sanctum::actingAs($this->user);
        $this->deleteJson("/api/b2b-accounts/{$this->account->id}")->assertOk();

        $card->refresh();
        $this->assertFalse(B2bAccount::query()->whereKey($this->account->id)->exists());
        $this->assertSame('40.00', $card->purchase_price);
        $this->assertSame('60.00', $card->catalog_price_net);
        $this->assertSame('0.00', $card->discount_percent);
        $this->assertSame(0, ProductSourcePrice::query()->where('source_key', ProductSourcePrice::b2bKey($this->account->id))->count());
        $this->assertSame('40.00', $this->slot($card, ProductSourcePrice::SOURCE_FILE)->purchase_price);
        // karta bez innego źródła zachowuje ostatnią cenę (brak slotów = cena karty bez zmian)
        $this->assertSame('50.00', $onlyB2b->fresh()->purchase_price);
    }

    /**
     * @return array<string, mixed>
     */
    private function runSync(B2bAccount $account, B2bConnector $connector): array
    {
        return app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, connector: $connector);
    }

    private function makeAccount(string $username): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [SourceSlotConnector::host()],
            'connector' => SourceSlotConnector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function shop(): SourceSlotConnector
    {
        $shop = new SourceSlotConnector;
        $shop->items = ['1' => ['sku' => 'SLOT-1', 'name' => self::SOURCE_NAME]];

        return $shop;
    }

    /** Karta z ceną z cennika z pliku: slot „file” i ta sama cena na karcie. */
    private function fileCard(): Product
    {
        $card = Product::query()->create([
            'sku' => 'SLOT-1',
            'name' => self::FILE_NAME,
            'manufacturer' => 'Testowy',
            'catalog_price_net' => 60.00,
            'purchase_price' => 40.00,
            'discount_percent' => 0,
            'currency' => 'PLN',
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id,
            'source_key' => ProductSourcePrice::SOURCE_FILE,
            'catalog_price_net' => 60.00,
            'purchase_price' => 40.00,
            'discount_percent' => 0,
            'currency' => 'PLN',
            'checked_at' => now()->subDay(),
        ]);

        return $card;
    }

    private function slot(Product $card, string $sourceKey): ProductSourcePrice
    {
        return ProductSourcePrice::query()->where('product_id', $card->id)->where('source_key', $sourceKey)->sole();
    }
}

/** Łącznik testowy bez sieci: cena konta i bazowa ustawiane w teście, rabat 10%, bez opisu i zdjęć. */
final class SourceSlotConnector implements B2bConnector
{
    /** @var array<string, array{sku: string, name: string}> */
    public array $items = [];

    public float $net = 50.0;

    public ?float $base = 70.0;

    public static function key(): string
    {
        return 'slottest';
    }

    public static function label(): string
    {
        return 'Testowy';
    }

    public static function host(): string
    {
        return 'slottest.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        foreach ($this->items as $id => $item) {
            yield new B2bRemoteProduct(remoteId: (string) $id, sku: $item['sku'], name: $item['name']);
        }
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Testowy';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: $this->net, base: $this->base, discountPercent: 10.0);
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
