<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bKeepsExistingNames;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Łącznik ze znacznikiem B2bKeepsExistingNames: istniejąca karta zachowuje nazwę, reszta pól jak dotąd;
 * nowa karta dostaje nazwę ze źródła. Łącznik bez znacznika nadpisuje nazwę (kontrola).
 */
final class B2bKeepsExistingNamesTest extends TestCase
{
    use RefreshDatabase;

    private const POLISH_NAME = 'Okulary ochronne Tryon BSSI, soczewka miedziana';

    private const SOURCE_NAME = 'TRYON BSSI – Copper safety glasses';

    private User $user;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => [KeepNamesPlainConnector::host()],
            'connector' => KeepNamesPlainConnector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    public function test_marked_connector_keeps_name_of_existing_card_and_updates_price(): void
    {
        $existing = $this->existingCard();
        $shop = $this->shop(new KeepNamesMarkedConnector);

        $result = $this->runSync($shop);

        $existing->refresh();
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['prices_changed']);
        $this->assertSame(self::POLISH_NAME, $existing->name);
        $this->assertSame('50.00', $existing->purchase_price);
        $this->assertSame('60.00', $existing->catalog_price_net);
        $this->assertSame('Testowy', $existing->manufacturer);
        $this->assertTrue(ProductPriceHistory::query()
            ->where('product_id', $existing->id)
            ->where('b2b_sync_run_id', $result['sync_run_id'])
            ->where('source', 'b2b:keepnames')
            ->where('purchase_price', 50)
            ->exists());
        $this->assertSame($existing->id, B2bProductLink::query()->where('remote_id', '1')->value('product_id'));

        // zmiana ceny i podsumowanie aktualizacji pokazują nazwę karty, nazwa nie jest liczona jako zmienione pole
        $run = B2bSyncRun::query()->findOrFail($result['sync_run_id']);
        $this->assertSame(self::POLISH_NAME, $run->price_changes[0]['name']);
        $updated = collect(PriceList::query()->sole()->updated_products)->firstWhere('sku', 'BOL-1');
        $this->assertNotNull($updated);
        $this->assertSame(self::POLISH_NAME, $updated['name']);
        $this->assertContains('purchase_price', $updated['fields']);
        $this->assertNotContains('name', $updated['fields']);

        // nowa karta — nazwa ze źródła
        $this->assertSame(self::SOURCE_NAME.' 2', Product::query()->where('sku', 'BOL-2')->sole()->name);
    }

    public function test_marked_connector_second_run_without_price_change_is_unchanged_and_keeps_name(): void
    {
        $existing = $this->existingCard();
        $shop = $this->shop(new KeepNamesMarkedConnector);
        $this->runSync($shop);
        $historyAfterFirst = ProductPriceHistory::query()->count();

        $second = $this->runSync($shop);

        $this->assertSame(2, $second['unchanged']);
        $this->assertSame(0, $second['created'] + $second['updated']);
        $this->assertSame(0, $second['prices_changed']);
        $this->assertSame($historyAfterFirst, ProductPriceHistory::query()->count());
        $this->assertSame(self::POLISH_NAME, $existing->fresh()->name);
        $this->assertSame(self::SOURCE_NAME.' 2', Product::query()->where('sku', 'BOL-2')->sole()->name);
    }

    public function test_connector_without_marker_overwrites_name(): void
    {
        $existing = $this->existingCard();
        $shop = $this->shop(new KeepNamesPlainConnector);

        $result = $this->runSync($shop);

        $existing->refresh();
        $this->assertSame(1, $result['updated']);
        $this->assertSame(self::SOURCE_NAME, $existing->name);
        $this->assertSame('50.00', $existing->purchase_price);
    }

    /**
     * @return array<string, mixed>
     */
    private function runSync(B2bConnector $connector): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $connector);
    }

    private function shop(KeepNamesPlainConnector $shop): KeepNamesPlainConnector
    {
        $shop->items = [
            '1' => ['sku' => 'BOL-1', 'name' => self::SOURCE_NAME],
            '2' => ['sku' => 'BOL-2', 'name' => self::SOURCE_NAME.' 2'],
        ];

        return $shop;
    }

    private function existingCard(): Product
    {
        return Product::query()->create([
            'sku' => 'BOL-1',
            'name' => self::POLISH_NAME,
            'manufacturer' => 'Testowy',
            'catalog_price_net' => 60.00,
            'purchase_price' => 40.00,
            'discount_percent' => 0,
            'currency' => 'PLN',
        ]);
    }
}

/** Łącznik testowy bez sieci: stałe produkty, cena zakupu 50, katalogowa 60. */
class KeepNamesPlainConnector implements B2bConnector
{
    /** @var array<string, array{sku: string, name: string}> */
    public array $items = [];

    public static function key(): string
    {
        return 'keepnames';
    }

    public static function label(): string
    {
        return 'Testowy';
    }

    public static function host(): string
    {
        return 'keepnames.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new static;
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
        return new B2bRemotePrice(net: 50.0, base: 60.0);
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

final class KeepNamesMarkedConnector extends KeepNamesPlainConnector implements B2bKeepsExistingNames {}
