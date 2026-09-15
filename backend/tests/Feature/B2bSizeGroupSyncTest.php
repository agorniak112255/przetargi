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
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bListProgressAware;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Grupy rozmiarów z łącznika (members — decyzja użytkownika 15.09.2026: jedna karta na rozmiary o tej samej cenie),
 * dostępność w slocie konta, variant_summary, błąd opisu bez utraty ceny i postęp pobierania listy — bez HTTP.
 */
final class B2bSizeGroupSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private B2bAccount $account;

    private SizeGroupFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => [SizeGroupFakeConnector::host()],
            'connector' => SizeGroupFakeConnector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $this->connector = new SizeGroupFakeConnector;
    }

    public function test_group_creates_one_card_with_link_per_member_availability_and_sizes(): void
    {
        $this->connector->items = [$this->group('U1', ['S', 'M', 'L'], availability: 'Dostępny', summary: '  S; M; L ')];
        $this->connector->prices = ['U1-S' => 25.5];

        $result = $this->sync();

        $this->assertSame(1, $result['created']);
        $product = Product::query()->sole();
        $this->assertSame('U1-S', $product->sku);
        $this->assertSame('Rękawice Uvex', $product->name);
        $this->assertSame('S; M; L', $product->variant_summary);

        $links = B2bProductLink::query()->orderBy('id')->get();
        $this->assertSame(['U1-S', 'U1-M', 'U1-L'], $links->pluck('remote_id')->all());
        $this->assertSame(['U1-S', 'U1-M', 'U1-L'], $links->pluck('remote_sku')->all());
        $this->assertSame(
            ['Rękawice Uvex rozm. S', 'Rękawice Uvex rozm. M', 'Rękawice Uvex rozm. L'],
            $links->pluck('remote_name')->all(),
        );
        $this->assertSame([$product->id], $links->pluck('product_id')->unique()->values()->all());

        $slot = ProductSourcePrice::query()->sole();
        $this->assertSame(ProductSourcePrice::b2bKey((int) $this->account->id), $slot->source_key);
        $this->assertSame('Dostępny', $slot->availability);
        $this->assertSame('25.50', $slot->purchase_price);
        $this->assertSame(1, ProductPriceHistory::query()->count());

        Sanctum::actingAs($this->user);
        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('variant_summary', 'S; M; L')
            ->assertJsonPath('source_prices.0.availability', 'Dostępny');
    }

    public function test_split_off_size_gets_new_card_and_group_keeps_its_card(): void
    {
        $this->connector->items = [$this->group('U1', ['S', 'M', 'L'], summary: 'S; M; L')];
        $this->connector->prices = ['U1-S' => 25.5];
        $this->sync();
        $card = Product::query()->sole();

        // rozmiar L dostał inną cenę — łącznik podaje go jako osobną grupę, po grupie z kodem karty
        $this->connector->items = [
            $this->group('U1', ['S', 'M'], summary: 'S; M'),
            $this->group('U1', ['L'], summary: 'L'),
        ];
        $this->connector->prices = ['U1-S' => 25.5, 'U1-L' => 30.0];
        $result = $this->sync();

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $card->refresh();
        $this->assertSame('S; M', $card->variant_summary);
        $this->assertSame($card->id, B2bProductLink::query()->where('remote_id', 'U1-S')->value('product_id'));
        $this->assertSame($card->id, B2bProductLink::query()->where('remote_id', 'U1-M')->value('product_id'));

        $large = Product::query()->where('sku', 'U1-L')->sole();
        $this->assertNotSame($card->id, $large->id);
        $this->assertSame($large->id, B2bProductLink::query()->where('remote_id', 'U1-L')->value('product_id'));
        $this->assertSame('30.00', ProductSourcePrice::query()->where('product_id', $large->id)->value('purchase_price'));
        $this->assertSame('25.50', ProductSourcePrice::query()->where('product_id', $card->id)->value('purchase_price'));

        $updated = collect(PriceList::query()->sole()->updated_products)->firstWhere('sku', 'U1-S');
        $this->assertNotNull($updated);
        $this->assertSame(['rozmiary'], $updated['fields']);
        $this->assertEmpty(array_filter($this->logTexts($result), static fn (string $t): bool => str_contains($t, 'nie ma już kodów')));
    }

    public function test_group_whose_card_was_taken_earlier_in_run_is_skipped_with_reason(): void
    {
        $this->connector->items = [$this->group('U1', ['S', 'M', 'L'])];
        $this->connector->prices = ['U1-S' => 25.5];
        $this->sync();
        $card = Product::query()->sole();

        // osobna grupa L przed grupą z kodem karty — L zabiera kartę po swoim powiązaniu
        $this->connector->items = [
            $this->group('U1', ['L']),
            $this->group('U1', ['S', 'M']),
        ];
        $this->connector->prices = ['U1-L' => 30.0, 'U1-S' => 25.5];
        $result = $this->sync();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame('30.00', ProductSourcePrice::query()->where('product_id', $card->id)->value('purchase_price'));
        $this->assertContains(
            'U1-S: kod U1-S jest już SKU karty #'.$card->id.' użytej w tym przebiegu przez inną grupę rozmiarów',
            $result['errors'],
        );
    }

    public function test_card_that_lost_all_codes_gets_warning_and_is_kept(): void
    {
        $this->connector->items = [$this->group('U3', ['S']), $this->group('U3', ['M'])];
        $this->connector->prices = ['U3-S' => 10.0, 'U3-M' => 12.0];
        $this->sync();
        $small = Product::query()->where('sku', 'U3-S')->sole();
        $medium = Product::query()->where('sku', 'U3-M')->sole();

        // ceny się zrównały — jedna grupa
        $this->connector->items = [$this->group('U3', ['S', 'M'])];
        $this->connector->prices = ['U3-S' => 10.0];
        $result = $this->sync();

        $this->assertSame($small->id, B2bProductLink::query()->where('remote_id', 'U3-M')->value('product_id'));
        $this->assertNotNull($medium->fresh());
        $this->assertTrue(ProductSourcePrice::query()->where('product_id', $medium->id)->exists());
        $this->assertContains(
            'U3-S: karta #'.$medium->id.' (U3-M) nie ma już kodów w B2B tego konta — jej cena z konta zostaje do decyzji',
            $this->logTexts($result),
        );
    }

    public function test_availability_only_change_updates_slot_and_dry_run_reports_it(): void
    {
        $this->connector->items = [$this->group('U1', ['S', 'M'], availability: 'Dostępny')];
        $this->connector->prices = ['U1-S' => 25.5];
        $this->sync();
        $slot = ProductSourcePrice::query()->sole();

        $this->connector->items = [$this->group('U1', ['S', 'M'], availability: 'Na zamówienie')];
        $dry = $this->sync(dryRun: true);
        $this->assertSame(1, $dry['updated']);
        $this->assertSame('Dostępny', $slot->fresh()->availability);

        $result = $this->sync();
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['prices_changed']);
        $this->assertSame('Na zamówienie', $slot->fresh()->availability);
        $this->assertSame(1, ProductPriceHistory::query()->count());
        $updated = collect(PriceList::query()->sole()->updated_products)->firstWhere('sku', 'U1-S');
        $this->assertNotNull($updated);
        $this->assertSame(['dostępność'], $updated['fields']);

        // źródło bez dostępności — zapisana zostaje
        $this->connector->items = [$this->group('U1', ['S', 'M'])];
        $again = $this->sync();
        $this->assertSame(1, $again['unchanged']);
        $this->assertSame('Na zamówienie', $slot->fresh()->availability);
    }

    public function test_empty_variant_summary_clears_and_card_with_active_variants_is_not_touched(): void
    {
        $this->connector->items = [$this->group('U1', ['S', 'M'], summary: 'S; M')];
        $this->connector->prices = ['U1-S' => 25.5];
        $this->sync();

        $this->connector->items = [$this->group('U1', ['S', 'M'], summary: '')];
        $result = $this->sync();
        $this->assertSame(1, $result['updated']);
        $this->assertNull(Product::query()->where('sku', 'U1-S')->sole()->variant_summary);
        $this->assertContains('rozmiary', collect(PriceList::query()->sole()->updated_products)->firstWhere('sku', 'U1-S')['fields']);

        $signed = Product::query()->create([
            'sku' => 'SIGN-1',
            'name' => 'Znak',
            'manufacturer' => 'Uvex',
            'variant_summary' => 'Format: A4',
            'catalog_price_net' => 0,
            'purchase_price' => 0,
            'discount_percent' => 0,
            'currency' => 'PLN',
        ]);
        ProductVariant::query()->forceCreate([
            'product_id' => $signed->id,
            'source' => 'b2b:inne',
            'remote_id' => 'v1',
            'label' => 'A4',
        ]);
        $this->connector->items = [$this->group('SIGN', ['1'], summary: 'rozmiar 1')];
        $this->connector->prices = ['SIGN-1' => 5.0];
        $this->sync();

        $this->assertSame('Format: A4', $signed->fresh()->variant_summary);
    }

    public function test_description_error_does_not_block_price_for_single_row_connector(): void
    {
        $this->connector->items = [new B2bRemoteProduct(remoteId: 'P1', sku: 'P1', name: 'Kask')];
        $this->connector->prices = ['P1' => 40.0];
        $this->connector->descriptionError = 'Timeout opisu';

        $result = $this->sync();

        $this->assertSame(1, $result['created']);
        $product = Product::query()->sole();
        $this->assertSame('40.00', ProductSourcePrice::query()->where('product_id', $product->id)->value('purchase_price'));
        $this->assertNull(ProductSourcePrice::query()->sole()->availability);
        $this->assertSame('Kask', B2bProductLink::query()->sole()->remote_name);
        $this->assertContains(
            'P1: opis nie został pobrany (Timeout opisu) — opis bez zmian, ceny zaktualizowane',
            $this->logTexts($result),
        );
    }

    public function test_list_progress_messages_are_in_run_log(): void
    {
        $this->connector->listMessages = ['Lista: strona 1/2', 'Lista: strona 2/2'];
        $this->connector->items = [$this->group('U1', ['S'])];
        $this->connector->prices = ['U1-S' => 25.5];

        $result = $this->sync();

        $texts = $this->logTexts($result);
        $this->assertContains('Lista: strona 1/2', $texts);
        $this->assertContains('Lista: strona 2/2', $texts);
    }

    /**
     * @param  list<string>  $sizes
     */
    private function group(string $prefix, array $sizes, ?string $availability = null, ?string $summary = null): B2bRemoteProduct
    {
        $members = array_map(static fn (string $size): array => [
            'remote_id' => $prefix.'-'.$size,
            'sku' => $prefix.'-'.$size,
            'name' => 'Rękawice Uvex rozm. '.$size,
        ], $sizes);

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $members[0]['sku'],
            name: 'Rękawice Uvex',
            availability: $availability,
            variantSummary: $summary,
            members: $members,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(bool $dryRun = false): array
    {
        return app(B2bAccountSyncRunner::class)->run(
            $this->account->fresh(),
            dryRun: $dryRun,
            delayMs: 0,
            connector: $this->connector,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function logTexts(array $result): array
    {
        return array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text');
    }
}

/** Łącznik testowy bez sieci: grupy rozmiarów, cena wg remoteId, komunikaty postępu listy. */
final class SizeGroupFakeConnector implements B2bConnector, B2bListProgressAware
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    /** @var array<string, float> */
    public array $prices = [];

    /** @var list<string> */
    public array $listMessages = [];

    public ?string $descriptionError = null;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public static function key(): string
    {
        return 'sizegroup';
    }

    public static function label(): string
    {
        return 'Rozmiary testowe';
    }

    public static function host(): string
    {
        return 'sizegroup.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    public function products(): iterable
    {
        foreach ($this->listMessages as $message) {
            if ($this->listProgress !== null) {
                ($this->listProgress)($message);
            }
        }

        yield from $this->items;
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Uvex';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $this->prices[$product->remoteId] ?? null;

        return $net !== null ? new B2bRemotePrice(net: $net) : null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        if ($this->descriptionError !== null) {
            throw new RuntimeException($this->descriptionError);
        }

        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}
