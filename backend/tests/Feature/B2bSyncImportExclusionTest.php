<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductImportExclusion;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteVariant;
use App\Services\B2b\B2bVariantConnector;
use App\Services\Catalog\ProductImportExclusions;
use App\Services\ProductDeletionService;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Usuń i pomijaj przy imporcie” (decyzja użytkownika 28.09.2026) w synchronizacji B2B: pozycja usuniętej karty nie
 * wraca — ani jako nowa karta, ani na innej karcie o tym samym kodzie; grupa rozmiarów traci tylko zablokowane pozycje;
 * status „suppressed” jest cichy (licznik + jedna linia podsumowania); trafienia liczy tylko pełny przebieg.
 */
final class B2bSyncImportExclusionTest extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY = 'Pominięto 1 pozycji usuniętych z pominięciem (Cenniki → Usunięte z pominięciem)';

    private User $user;

    private B2bAccount $account;

    private ExclusionFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = $this->account(ExclusionFakeConnector::key());
        $this->connector = new ExclusionFakeConnector;
    }

    public function test_deleted_single_position_is_suppressed_and_not_attached_to_other_card_with_same_sku(): void
    {
        $this->connector->items = [$this->single('P1', 'ABC-1', 10.0), $this->single('P2', 'ABC-2', 20.0)];
        $this->assertSame(2, $this->sync()['created']);
        $this->deleteSkipping(Product::query()->where('sku', 'ABC-1')->sole());

        // karta z tym samym kodem (np. z cennika z pliku) — dopasowanie po kodzie nie może jej podpiąć
        $other = Product::query()->create(['sku' => 'ABC-1', 'name' => 'Inna karta', 'manufacturer' => 'TESTBRAND']);
        $this->travel(5)->minutes();

        $result = $this->sync();

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(1, $result['suppressed']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame([], $result['errors']);
        $this->assertFalse(B2bProductLink::query()->where('remote_id', 'P1')->exists());
        $this->assertFalse(ProductSourcePrice::query()->where('product_id', $other->id)->exists());
        $this->assertSame('0.00', (string) $other->fresh()->purchase_price);

        $run = B2bSyncRun::query()->findOrFail($result['sync_run_id']);
        $texts = array_column($run->log, 'text');
        $this->assertContains(self::SUMMARY, $texts);
        // bez linii dziennika na pozycję i bez listy pominięć
        $this->assertSame([], array_values(array_filter($texts, static fn (string $t): bool => str_contains($t, 'ABC-1'))));
        $this->assertSame(0, (int) $run->skipped);
        $this->assertStringContainsString('usunięte z pominięciem: 1', (string) $run->message);
        $this->assertSame(1, ProductImportExclusion::query()->sole()->hits);
    }

    public function test_restored_position_is_created_again(): void
    {
        $this->connector->items = [$this->single('P1', 'ABC-1', 10.0)];
        $this->sync();
        $this->deleteSkipping(Product::query()->sole());
        $this->assertSame(1, $this->sync()['suppressed']);
        $this->assertSame(0, Product::query()->count());

        app(ProductImportExclusions::class)->restore([(int) ProductImportExclusion::query()->sole()->id], $this->user);
        $result = $this->sync();

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['suppressed']);
        $card = Product::query()->sole();
        $this->assertSame('ABC-1', $card->sku);
        $this->assertTrue(B2bProductLink::query()->where('remote_id', 'P1')->where('product_id', $card->id)->exists());
    }

    public function test_group_drops_blocked_size_and_updates_existing_card_without_it(): void
    {
        // stan sprzed rozmiarów w cenach: S na karcie „K1 S”, M i L na karcie „K1 M”
        $this->connector->items = [$this->legacyGroup(['S'], 90.0), $this->legacyGroup(['M', 'L'], 100.0)];
        $this->assertSame(2, $this->sync()['created']);
        $small = Product::query()->where('sku', 'K1 S')->sole();
        $medium = Product::query()->where('sku', 'K1 M')->sole();
        $this->deleteSkipping($small);
        $this->travel(5)->minutes();

        // łącznik po zmianie: jeden wyrób z cenami rozmiarów, zablokowany S jest najtańszy i wiodący
        $this->connector->items = [$this->jacket(['S' => 90.0, 'M' => 100.0, 'L' => 110.0])];
        $result = $this->sync();

        $this->assertSame(0, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['suppressed']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, Product::query()->count());
        $slot = ProductSourcePrice::query()->where('product_id', $medium->id)->sole();
        // cena najtańszego rozmiaru z pozostałych, nie z zablokowanego S
        $this->assertSame('100.00', (string) $slot->purchase_price);
        $this->assertSame('110.00', (string) $slot->size_price_max);
        $this->assertSame(
            ['K1-L', 'K1-M'],
            ProductVariant::query()->where('product_id', $medium->id)->where('kind', ProductVariant::KIND_SIZE)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $this->assertSame(['K1-L', 'K1-M'], B2bProductLink::query()->orderBy('remote_id')->pluck('remote_id')->all());
        // lista rozmiarów od łącznika wymienia też zablokowany S — karta zostaje z zapisaną
        $this->assertSame('Rozmiary: M; L', $medium->fresh()->variant_summary);
        $this->assertContains(self::SUMMARY, $this->logTexts($result));
    }

    public function test_group_left_without_card_after_dropping_blocked_sizes_is_suppressed(): void
    {
        $this->connector->items = [$this->jacket(['S' => 90.0, 'M' => 100.0])];
        $this->assertSame(1, $this->sync()['created']);
        $this->deleteSkipping(Product::query()->sole());
        $this->travel(5)->minutes();

        // cała grupa zablokowana
        $all = $this->sync();
        $this->assertSame(1, $all['suppressed']);
        $this->assertSame(0, $all['created']);

        // nowy rozmiar u dostawcy — pozostała pozycja należy do usuniętego wyrobu, nowej karty nie ma
        $this->connector->items = [$this->jacket(['S' => 90.0, 'M' => 100.0, 'XL' => 120.0])];
        $result = $this->sync();

        $this->assertSame(1, $result['suppressed']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, B2bProductLink::query()->count());
        $this->assertSame(0, ProductVariant::query()->count());
    }

    public function test_hits_count_only_full_real_runs(): void
    {
        $this->connector->items = [$this->single('P1', 'ABC-1', 10.0)];
        $this->sync();
        $this->deleteSkipping(Product::query()->sole());
        $row = ProductImportExclusion::query()->sole();
        $this->assertSame(0, $row->hits);

        $preview = $this->sync(dryRun: true);
        $this->assertSame(1, $preview['suppressed']);
        $this->assertSame(0, $preview['created']);
        $this->assertSame(0, $row->fresh()->hits);

        $this->sync(limit: 1);
        $this->assertSame(0, $row->fresh()->hits);

        $this->sync();
        $this->assertSame(1, $row->fresh()->hits);
        $this->assertNotNull($row->fresh()->last_hit_at);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_position_blocked_during_the_run_is_not_created_also_in_preview(): void
    {
        $this->connector->items = [$this->single('P1', 'ABC-1', 10.0), $this->single('P2', 'ABC-2', 20.0)];
        // blokada zapisana po wczytaniu blokad przebiegu (usunięcie z pominięciem w trakcie przebiegu)
        $this->connector->beforeItem = function (B2bRemoteProduct $remote): void {
            if ($remote->remoteId === 'P2' && ! ProductImportExclusion::query()->exists()) {
                $this->blockDirectly('P2');
            }
        };

        $preview = $this->sync(dryRun: true);
        $this->assertSame(1, $preview['created']);
        $this->assertSame(1, $preview['suppressed']);

        ProductImportExclusion::query()->delete();
        $result = $this->sync();

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['suppressed']);
        $this->assertSame(['ABC-1'], Product::query()->pluck('sku')->all());
        // blokada spoza mapy przebiegu nie liczy trafienia (liczy następny pełny przebieg)
        $this->assertSame(0, ProductImportExclusion::query()->sole()->hits);
    }

    public function test_variant_connector_suppresses_blocked_product_without_fetching_versions(): void
    {
        $connector = new ExclusionFakeVariantConnector;
        $connector->items = [new B2bRemoteProduct(remoteId: 'BB014', sku: 'BB014', name: 'Znak BB014', raw: ['versions' => [['id' => '101']]])];
        $account = $this->account(ExclusionFakeVariantConnector::key());
        $this->assertSame(1, $this->sync(account: $account, connector: $connector)['created']);
        $this->deleteSkipping(Product::query()->sole());
        $connector->variantCalls = 0;

        $result = $this->sync(account: $account, connector: $connector);

        $this->assertSame(1, $result['suppressed']);
        $this->assertSame(0, $result['created']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $connector->variantCalls);
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(1, ProductImportExclusion::query()->sole()->hits);
    }

    private function deleteSkipping(Product $card): void
    {
        app(ProductDeletionService::class)->deleteMany([(int) $card->id], $this->user, true);
        $this->assertNull(Product::query()->find($card->id));
    }

    private function blockDirectly(string $remoteId): void
    {
        $scope = ProductImportExclusions::b2bScope((int) $this->account->id);
        ProductImportExclusion::query()->forceCreate([
            'deletion_id' => (string) Str::uuid(),
            'source_key' => $scope,
            'scope_key' => $scope,
            'match_kind' => ProductImportExclusion::KIND_POSITION,
            'position_key' => $remoteId,
            'match_key' => ProductImportExclusions::matchKey($scope, ProductImportExclusion::KIND_POSITION, $remoteId),
            'b2b_account_id' => $this->account->id,
            'product_sku' => 'X',
            'product_name' => 'X',
            'deleted_by' => $this->user->id,
        ]);
    }

    private function single(string $remoteId, string $sku, float $price): B2bRemoteProduct
    {
        return new B2bRemoteProduct(remoteId: $remoteId, sku: $sku, name: 'Wyrób '.$sku, raw: ['price' => $price]);
    }

    /**
     * Grupa bez cen pozycji (stan sprzed rozmiarów w cenach): karta o kodzie pierwszego rozmiaru.
     *
     * @param  list<string>  $sizes
     */
    private function legacyGroup(array $sizes, float $price): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: 'K1-'.$sizes[0],
            sku: 'K1 '.$sizes[0],
            name: 'Kurtka K1 (rozm. '.implode(', ', $sizes).')',
            raw: ['price' => $price],
            variantSummary: 'Rozmiary: '.implode('; ', $sizes),
            members: array_map(static fn (string $size): array => [
                'remote_id' => 'K1-'.$size, 'sku' => 'K1 '.$size, 'name' => 'Kurtka K1 '.$size,
            ], $sizes),
        );
    }

    /**
     * Wyrób z ceną każdego rozmiaru (pozycja wiodąca — pierwszy rozmiar).
     *
     * @param  array<string, float>  $prices  rozmiar => cena konta
     */
    private function jacket(array $prices): B2bRemoteProduct
    {
        $members = [];
        foreach ($prices as $size => $net) {
            $members[] = [
                'remote_id' => 'K1-'.$size,
                'sku' => 'K1 '.$size,
                'name' => 'Kurtka K1 '.$size,
                'size' => (string) $size,
                'price' => new B2bRemotePrice(net: $net),
            ];
        }

        return new B2bRemoteProduct(
            remoteId: 'K1-'.array_key_first($prices),
            sku: 'K1',
            name: 'Kurtka K1',
            raw: ['price' => min($prices)],
            variantSummary: 'Rozmiary: '.implode('; ', array_keys($prices)),
            members: $members,
        );
    }

    private function account(string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => 'jan-'.$connector,
            'password' => 'sekret',
            'sites' => [$connector.'.example.test'],
            'connector' => $connector,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function logTexts(array $result): array
    {
        return array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text');
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(bool $dryRun = false, ?int $limit = null, ?B2bAccount $account = null, ?B2bConnector $connector = null): array
    {
        return app(B2bAccountSyncRunner::class)->run(
            ($account ?? $this->account)->fresh(),
            limit: $limit,
            dryRun: $dryRun,
            delayMs: 0,
            connector: $connector ?? $this->connector,
        );
    }
}

/**
 * Łącznik testowy bez sieci: cena z raw['price']; beforeItem — wołane przed podaniem pozycji (zmiana stanu w trakcie).
 */
final class ExclusionFakeConnector implements B2bConnector
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    public ?Closure $beforeItem = null;

    public static function key(): string
    {
        return 'exclusionfake';
    }

    public static function label(): string
    {
        return 'Pomijanie przy imporcie — test';
    }

    public static function host(): string
    {
        return 'exclusionfake.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        foreach ($this->items as $item) {
            if ($this->beforeItem !== null) {
                ($this->beforeItem)($item);
            }
            yield $item;
        }
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'TESTBRAND';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $product->raw['price'] ?? null;

        return $net !== null ? new B2bRemotePrice(net: (float) $net) : null;
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

final class ExclusionFakeVariantConnector implements B2bVariantConnector
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    public int $variantCalls = 0;

    public static function key(): string
    {
        return 'exclusionsign';
    }

    public static function label(): string
    {
        return 'Pomijanie przy imporcie — wersje, test';
    }

    public static function host(): string
    {
        return 'exclusionsign.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield from $this->items;
    }

    public function totalProducts(): int
    {
        return count($this->items);
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'SignProject';
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
        $this->variantCalls++;

        return [new B2bRemoteVariant(remoteId: '101', label: '10 x 15 cm', attributes: ['Format' => '10 x 15 cm'], price: new B2bRemotePrice(5.0))];
    }

    public function totalVariants(): int
    {
        return count($this->items);
    }

    public function listedVariantIds(): ?array
    {
        return null;
    }

    public function runBudgetMinutes(): ?int
    {
        return null;
    }
}
