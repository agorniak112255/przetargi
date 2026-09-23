<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bAccountManufacturerRule;
use App\Models\B2bProductLink;
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
use Tests\TestCase;

/**
 * Znaczniki „cena” i „opis” producenta z okna „Producenci” w synchronizacji dystrybutora wielu marek
 * (decyzja użytkownika 23.09.2026).
 */
final class B2bManufacturerRulesSyncTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'Okulary ochronne z poliwęglanu, powłoka przeciw parowaniu, EN 166.';

    private User $user;

    private B2bAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00'));
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'hurt', 'password' => 'sekret', 'sites' => [MultiBrandConnector::host()],
            'connector' => MultiBrandConnector::key(), 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
        ]);
    }

    public function test_price_off_keeps_card_price_writes_description_and_does_not_ask_shop_for_price(): void
    {
        $card = $this->card('BOL-1', 'Bolle');
        $this->rule('BOLLE', price: false, description: true);
        $shop = $this->shop(['BOL-1' => 'Bolle', 'ANS-1' => 'Ansell']);
        $this->card('ANS-1', 'Ansell');

        $result = $this->runSync($shop);

        $card->refresh();
        $this->assertSame('99.00', $card->purchase_price);
        $this->assertSame(self::DESCRIPTION, $card->description);
        $this->assertNull(ProductSourcePrice::query()->where('product_id', $card->id)->first());
        $this->assertFalse(ProductPriceHistory::query()->where('product_id', $card->id)->exists());
        $this->assertSame(['ANS-1'], $shop->priceAskedFor);
        // powiązanie zapisane z producentem w brzmieniu konta
        $this->assertSame('Bolle', B2bProductLink::query()->where('product_id', $card->id)->value('manufacturer'));
        $this->assertSame(0, $result['excluded']);
        $this->assertSame(0, $result['skipped']);
        // inny producent tego konta bez zmian: slot i cena z B2B
        $this->assertSame('50.00', Product::query()->where('sku', 'ANS-1')->value('purchase_price'));
    }

    public function test_price_off_does_not_create_new_cards(): void
    {
        $this->rule('Bolle', price: false, description: true);

        $result = $this->runSync($this->shop(['BOL-NEW' => 'Bolle']));

        $this->assertNull(Product::query()->where('sku', 'BOL-NEW')->first());
        $this->assertSame(1, $result['excluded']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([], $result['errors']);
        $this->assertFalse(B2bProductLink::query()->exists());
    }

    public function test_description_off_leaves_description_but_updates_price(): void
    {
        $card = $this->card('BOL-1', 'Bolle');
        $this->rule('Bolle', price: true, description: false);

        $this->runSync($this->shop(['BOL-1' => 'Bolle']));

        $card->refresh();
        $this->assertNull($card->description);
        $this->assertSame('50.00', $card->purchase_price);
        $this->assertNotNull(ProductSourcePrice::query()->where('source_key', ProductSourcePrice::b2bKey($this->account->id))->first());
    }

    public function test_both_off_skips_item_entirely_without_link(): void
    {
        $card = $this->card('BOL-1', 'Bolle');
        $this->rule('Bolle', price: false, description: false);
        $shop = $this->shop(['BOL-1' => 'Bolle']);

        $result = $this->runSync($shop);

        $this->assertSame(1, $result['excluded']);
        $this->assertSame(0, $result['updated'] + $result['unchanged'] + $result['skipped']);
        $this->assertFalse(B2bProductLink::query()->exists());
        $this->assertNull($card->fresh()->description);
        $this->assertSame([], $shop->priceAskedFor);
        $this->assertSame([], $shop->descriptionAskedFor);
    }

    public function test_dry_run_reports_exclusion_without_writing(): void
    {
        $this->rule('Bolle', price: false, description: false);

        $result = app(B2bAccountSyncRunner::class)->run(
            $this->account->fresh(),
            dryRun: true,
            delayMs: 0,
            connector: $this->shop(['BOL-1' => 'Bolle']),
        );

        $this->assertSame(1, $result['excluded']);
        $this->assertFalse(Product::query()->exists());
    }

    /** @param  array<string, string>  $items  kod => producent */
    private function shop(array $items): MultiBrandConnector
    {
        $shop = new MultiBrandConnector;
        $shop->items = $items;
        $shop->description = self::DESCRIPTION;

        return $shop;
    }

    private function card(string $sku, string $manufacturer): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => 'Okulary '.$sku, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 99.00, 'purchase_price' => 99.00, 'discount_percent' => 0, 'currency' => 'PLN',
        ]);
    }

    private function rule(string $manufacturer, bool $price, bool $description): void
    {
        B2bAccountManufacturerRule::query()->create([
            'b2b_account_id' => $this->account->id,
            'manufacturer' => $manufacturer,
            'manufacturer_key' => PriceList::manufacturerKey($manufacturer),
            'take_price' => $price,
            'take_description' => $description,
        ]);
    }

    /** @return array<string, mixed> */
    private function runSync(B2bConnector $connector): array
    {
        return app(B2bAccountSyncRunner::class)->run($this->account->fresh(), delayMs: 0, connector: $connector);
    }
}

/** Dystrybutor wielu marek: producent z listy, cena 50/70 PLN, jeden opis dla wszystkich. */
final class MultiBrandConnector implements B2bConnector
{
    /** @var array<string, string> kod => producent */
    public array $items = [];

    public string $description = '';

    /** @var list<string> */
    public array $priceAskedFor = [];

    /** @var list<string> */
    public array $descriptionAskedFor = [];

    public static function key(): string
    {
        return 'multibrandtest';
    }

    public static function label(): string
    {
        return 'Hurtownia testowa';
    }

    public static function host(): string
    {
        return 'hurt.example.pl';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        foreach (array_keys($this->items) as $i => $sku) {
            yield new B2bRemoteProduct(remoteId: (string) ($i + 1), sku: $sku, name: 'Okulary '.$sku);
        }
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return $this->items[$product->sku];
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $this->priceAskedFor[] = $product->sku;

        return new B2bRemotePrice(net: 50.0, currency: 'PLN', base: 70.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        $this->descriptionAskedFor[] = $product->sku;

        return $this->description;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}
