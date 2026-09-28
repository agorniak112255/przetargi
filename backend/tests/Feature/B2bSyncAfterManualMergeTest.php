<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardRedirect;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bGroupsSizes;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\Catalog\CardRedirectStore;
use App\Services\ProductSizeMergeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Synchronizacja po ręcznym połączeniu kart (28.09.2026): mapa połączeń (reason „merge”) + mergeDuplicate, karta
 * dołączana z wierszami rozmiarów (kind „size”). Stan zawsze z prawdziwych przebiegów (zastane wiersze), potem DRUGI
 * przebieg — miejsce, w którym synchronizacja kasowała dane (20.09.2026 UVEX: 648 opisów).
 */
final class B2bSyncAfterManualMergeTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCER_TEXT = 'Kurtka robocza Mascot z membraną. Wodoszczelna, oddychająca, taśmy odblaskowe.';

    private const DISTRIBUTOR_TEXT = 'Kurtka zimowa od dystrybutora. Ocieplana, kaptur, kieszenie na suwak.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
    }

    public function test_distributor_second_run_after_merge_keeps_rows_on_producer_card_and_sweeps_only_its_source(): void
    {
        $producer = $this->account('mascot', 'mascot');
        $distributor = $this->account('p4s', 'p4s');
        $producerConnector = new ManualMergeProducerConnector;
        $producerConnector->items = [$this->jacket('M1', ['S' => 100.0, 'M' => 100.0, 'XL' => 120.0], self::PRODUCER_TEXT)];
        $distributorConnector = new ManualMergeDistributorConnector;
        $distributorConnector->manufacturer = 'Mascot';
        $distributorConnector->items = [$this->jacket('D1', ['S' => 110.0, 'M' => 110.0, 'L' => 115.0, 'XL' => 130.0], self::DISTRIBUTOR_TEXT)];

        $this->assertSame(1, $this->sync($producer, $producerConnector)['created']);
        $this->assertSame(1, $this->sync($distributor, $distributorConnector)['created']);
        $keep = Product::query()->where('sku', 'M1')->sole();
        $drop = Product::query()->where('sku', 'D1')->sole();
        $distributorRows = $this->rowIds($drop, $distributor);
        $this->assertCount(4, $distributorRows);

        $this->merge($keep, $drop);

        $fields = $this->cardFields($keep);
        $producerRows = $this->rowsState($keep, $producer);
        $this->assertSame(['M1', 'Kurtka M1', 'MASCOT', 'Rozmiary: S; M; XL', self::PRODUCER_TEXT], $fields);
        $this->assertSame($distributorRows, $this->rowIds($keep, $distributor));

        // drugi przebieg dystrybutora na zastanych wierszach: mapa kieruje grupę na kartę producenta
        $this->travel(5)->minutes();
        $second = $this->sync($distributor, $distributorConnector);

        $this->assertSame(0, $second['created'], implode(' | ', $second['errors']));
        $this->assertSame(0, $second['skipped']);
        $this->assertSame([], $second['errors']);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame($fields, $this->cardFields($keep));
        // te same wiersze dystrybutora, aktywne, na karcie producenta; wiersze producenta nietknięte
        $this->assertSame($distributorRows, $this->rowIds($keep, $distributor));
        $this->assertSame(0, ProductVariant::query()->whereIn('id', $distributorRows)->whereNotNull('removed_at')->count());
        $this->assertSame($producerRows, $this->rowsState($keep, $producer));
        $slot = $this->slot($keep, $distributor);
        $this->assertSame(['110.00', '130.00'], [(string) $slot->purchase_price, (string) $slot->size_price_max]);
        $this->assertSame(
            [$keep->id],
            B2bProductLink::query()->where('b2b_account_id', $distributor->id)->distinct()->pluck('product_id')->map(static fn ($id): int => (int) $id)->all(),
        );

        // rozmiar L znika u dystrybutora — sprzątanie oznacza tylko jego wiersz, wierszy producenta nie dotyka
        $this->travel(5)->minutes();
        $distributorConnector->items = [$this->jacket('D1', ['S' => 110.0, 'M' => 110.0, 'XL' => 130.0], self::DISTRIBUTOR_TEXT)];
        $third = $this->sync($distributor, $distributorConnector);

        $this->assertSame(0, $third['created'], implode(' | ', $third['errors']));
        $this->assertSame(
            ['D1-L'],
            ProductVariant::query()->where('product_id', $keep->id)->whereNotNull('removed_at')->pluck('remote_id')->all(),
        );
        $this->assertSame($producerRows, $this->rowsState($keep, $producer));
        $this->assertSame($fields, $this->cardFields($keep));
    }

    public function test_producer_second_run_after_merge_does_not_touch_distributor_rows(): void
    {
        $producer = $this->account('mascot', 'mascot');
        $distributor = $this->account('p4s', 'p4s');
        $producerConnector = new ManualMergeProducerConnector;
        $producerConnector->items = [$this->jacket('M1', ['S' => 100.0, 'M' => 100.0, 'XL' => 120.0], self::PRODUCER_TEXT)];
        $distributorConnector = new ManualMergeDistributorConnector;
        $distributorConnector->manufacturer = 'Mascot';
        $distributorConnector->items = [$this->jacket('D1', ['S' => 110.0, 'L' => 115.0], self::DISTRIBUTOR_TEXT)];
        $this->sync($producer, $producerConnector);
        $this->sync($distributor, $distributorConnector);
        $keep = Product::query()->where('sku', 'M1')->sole();
        $this->merge($keep, Product::query()->where('sku', 'D1')->sole());
        $distributorRows = $this->rowsState($keep, $distributor);
        $fields = $this->cardFields($keep);

        $this->travel(5)->minutes();
        $second = $this->sync($producer, $producerConnector);
        // producent bez rozmiaru M: sprzątanie jego źródła
        $this->travel(5)->minutes();
        $producerConnector->items = [$this->jacket('M1', ['S' => 100.0, 'XL' => 120.0], self::PRODUCER_TEXT)];
        $third = $this->sync($producer, $producerConnector);

        foreach ([$second, $third] as $result) {
            $this->assertSame(0, $result['created'], implode(' | ', $result['errors']));
            $this->assertSame([], $result['errors']);
        }
        $this->assertSame(1, Product::query()->count());
        $this->assertSame($distributorRows, $this->rowsState($keep, $distributor));
        $this->assertSame(
            ['M1-M'],
            ProductVariant::query()->where('product_id', $keep->id)->whereNotNull('removed_at')->pluck('remote_id')->all(),
        );
        // właściciel karty dalej aktualizuje listę rozmiarów; reszta tożsamości karty bez zmian
        $fields[3] = 'Rozmiary: S; XL';
        $this->assertSame($fields, $this->cardFields($keep));
        // slot dystrybutora bez zmian (przebieg producenta pisze tylko swój)
        $this->assertSame('110.00', (string) $this->slot($keep, $distributor)->purchase_price);
    }

    public function test_unowned_card_guest_group_does_not_rewrite_manufacturer_or_sizes(): void
    {
        [$a, $b, $aConnector, $bConnector] = $this->twoDistributors();
        $aConnector->items = [$this->jacket('A1', ['S' => 50.0, 'M' => 50.0, 'L' => 55.0], self::PRODUCER_TEXT)];
        $bConnector->items = [$this->jacket('B1', ['S' => 52.0, 'M' => 52.0, 'L' => 56.0, 'XL' => 60.0], self::DISTRIBUTOR_TEXT)];

        $this->assertGuestKeepsCard($a, $b, $aConnector, $bConnector, 'Rozmiary: S; M; L');
    }

    public function test_unowned_card_guest_single_position_does_not_rewrite_manufacturer(): void
    {
        [$a, $b, $aConnector, $bConnector] = $this->twoDistributors();
        $aConnector->items = [new B2bRemoteProduct(remoteId: 'A1', sku: 'A1', name: 'Kurtka A1', raw: ['price' => 50.0, 'description' => self::PRODUCER_TEXT], variantSummary: 'Rozmiary: S; M; L')];
        $bConnector->items = [new B2bRemoteProduct(remoteId: 'B1', sku: 'B1', name: 'Kurtka B1', raw: ['price' => 52.0, 'description' => self::DISTRIBUTOR_TEXT], variantSummary: 'Rozmiary: S; M; L; XL')];

        $this->assertGuestKeepsCard($a, $b, $aConnector, $bConnector, 'Rozmiary: S; M; L');
    }

    public function test_account_without_merge_entry_still_writes_its_card(): void
    {
        // karta konta B dołączona do karty konta A (bez właściciela); pozycja konta A nie ma wpisu „merge” — jego
        // przebieg zapisuje producenta i listę rozmiarów jak dotąd (gościem jest tylko konto B)
        [$a, $b, $aConnector, $bConnector] = $this->twoDistributors();
        $aConnector->items = [$this->jacket('A1', ['S' => 50.0, 'M' => 50.0], self::PRODUCER_TEXT)];
        $bConnector->items = [$this->jacket('B1', ['S' => 52.0, 'M' => 52.0], self::DISTRIBUTOR_TEXT)];
        $this->sync($a, $aConnector);
        $this->sync($b, $bConnector);
        $keep = Product::query()->where('sku', 'A1')->sole();
        $this->merge($keep, Product::query()->where('sku', 'B1')->sole());

        $this->travel(5)->minutes();
        $aConnector->manufacturer = 'PORTWEST';
        $aConnector->items = [$this->jacket('A1', ['S' => 50.0, 'M' => 50.0, 'L' => 55.0], self::PRODUCER_TEXT)];
        $result = $this->sync($a, $aConnector);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['PORTWEST', 'Rozmiary: S; M; L'], [$keep->fresh()->manufacturer, $keep->fresh()->variant_summary]);
    }

    private function assertGuestKeepsCard(
        B2bAccount $a,
        B2bAccount $b,
        ManualMergeDistributorConnector $aConnector,
        ManualMergeDistributorConnector $bConnector,
        string $summary,
    ): void {
        $this->assertSame(1, $this->sync($a, $aConnector)['created']);
        $this->assertSame(1, $this->sync($b, $bConnector)['created']);
        $keep = Product::query()->where('sku', 'A1')->sole();
        $drop = Product::query()->where('sku', 'B1')->sole();
        $this->assertSame(['Portwest', $summary], [$keep->manufacturer, $keep->variant_summary]);
        $this->merge($keep, $drop);
        $fields = $this->cardFields($keep);

        // przebiegi obu kont na zmianę — producent i lista rozmiarów nie przeskakują między brzmieniami kont
        foreach ([[$b, $bConnector], [$a, $aConnector], [$b, $bConnector]] as [$account, $connector]) {
            $this->travel(5)->minutes();
            $result = $this->sync($account, $connector);
            $this->assertSame(0, $result['created'], implode(' | ', $result['errors']));
            $this->assertSame([], $result['errors']);
            $this->assertSame($fields, $this->cardFields($keep));
        }
        $this->assertSame(1, Product::query()->count());
        // slot i powiązanie gościa na karcie, która została
        $this->assertSame('52.00', (string) $this->slot($keep, $b)->purchase_price);
        $this->assertSame($keep->id, (int) B2bProductLink::query()->where('b2b_account_id', $b->id)->value('product_id'));
    }

    /**
     * Dwa konta dystrybutorów marki spoza ich łączników (PORTWEST) — karta bez właściciela; każde konto podaje
     * producenta innym brzmieniem.
     *
     * @return array{0: B2bAccount, 1: B2bAccount, 2: ManualMergeDistributorConnector, 3: ManualMergeDistributorConnector}
     */
    private function twoDistributors(): array
    {
        $a = $this->account('p4s', 'p4s');
        $b = $this->account('procera', 'procera');
        $aConnector = new ManualMergeDistributorConnector;
        $aConnector->manufacturer = 'Portwest';
        $bConnector = new ManualMergeDistributorConnector;
        $bConnector->manufacturer = 'PORTWEST Ltd';

        return [$a, $b, $aConnector, $bConnector];
    }

    /** Ręczne połączenie jak ManualCardMerger: mapa połączeń, potem scalenie — w jednej transakcji. */
    private function merge(Product $keep, Product $drop): void
    {
        DB::transaction(function () use ($keep, $drop): void {
            app(CardRedirectStore::class)->recordMerge($drop, $keep, CardRedirect::REASON_MERGE, null, $this->user);
            app(ProductSizeMergeService::class)->mergeDuplicate($keep, $drop);
        });
        $this->assertNull(Product::query()->find($drop->id));
    }

    /**
     * @param  array<string, float>  $prices  rozmiar => cena
     */
    private function jacket(string $code, array $prices, string $description): B2bRemoteProduct
    {
        $members = [];
        foreach ($prices as $size => $net) {
            $members[] = [
                'remote_id' => $code.'-'.$size,
                'sku' => $code.' '.$size,
                'name' => 'Kurtka '.$code.' '.$size,
                'availability' => 'Na stanie',
                'size' => (string) $size,
                'price' => new B2bRemotePrice(net: $net),
            ];
        }

        return new B2bRemoteProduct(
            remoteId: $code.'-'.array_key_first($prices),
            sku: $code,
            name: 'Kurtka '.$code,
            raw: ['price' => min($prices), 'description' => $description],
            availability: 'Na stanie',
            variantSummary: 'Rozmiary: '.implode('; ', array_keys($prices)),
            members: $members,
        );
    }

    /**
     * @return list<mixed>
     */
    private function cardFields(Product $card): array
    {
        $fresh = $card->fresh();

        return [$fresh->sku, $fresh->name, $fresh->manufacturer, $fresh->variant_summary, $fresh->description];
    }

    /**
     * @return list<int>
     */
    private function rowIds(Product $card, B2bAccount $account): array
    {
        return ProductVariant::query()
            ->where('product_id', $card->id)
            ->where('source', ProductSourcePrice::b2bKey((int) $account->id))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Wiersze rozmiarów konta na karcie z polami, które przebieg innego konta nie może zmienić.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsState(Product $card, B2bAccount $account): array
    {
        return ProductVariant::query()
            ->where('product_id', $card->id)
            ->where('source', ProductSourcePrice::b2bKey((int) $account->id))
            ->orderBy('id')
            ->get()
            ->map(static fn (ProductVariant $v): array => [
                'id' => (int) $v->id,
                'remote_id' => $v->remote_id,
                'purchase_price' => (string) $v->purchase_price,
                'removed_at' => $v->removed_at?->toIso8601String(),
                'last_seen_at' => $v->last_seen_at?->toIso8601String(),
                'updated_at' => $v->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    private function slot(Product $card, B2bAccount $account): ProductSourcePrice
    {
        return ProductSourcePrice::query()
            ->where('product_id', $card->id)
            ->where('source_key', ProductSourcePrice::b2bKey((int) $account->id))
            ->sole();
    }

    private function account(string $username, string $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [$connector.'.example.test'],
            'connector' => $connector,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(B2bAccount $account, B2bConnector $connector): array
    {
        return app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, connector: $connector);
    }
}

/**
 * Łącznik testowy bez sieci: cena grupy z raw['price'] (najtańszy rozmiar), ceny rozmiarów w members[].price.
 */
abstract class ManualMergeFakeConnector implements B2bConnector, B2bGroupsSizes
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    public string $manufacturer = 'MASCOT';

    public static function host(): string
    {
        return static::key().'.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): static
    {
        return new static;
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
        return $this->manufacturer;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $product->raw['price'] ?? null;

        return $net !== null ? new B2bRemotePrice(net: (float) $net) : null;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}

/** Witryna producenta (opis producenta zastępuje opis karty — najbardziej ryzykowna droga opisu). */
final class ManualMergeProducerConnector extends ManualMergeFakeConnector implements B2bManufacturerSite
{
    public static function key(): string
    {
        return 'manual-merge-producer';
    }

    public static function label(): string
    {
        return 'Producent — test';
    }

    public static function ownBrand(): string
    {
        return 'MASCOT';
    }
}

/** Dystrybutor wielu marek. */
final class ManualMergeDistributorConnector extends ManualMergeFakeConnector
{
    public static function key(): string
    {
        return 'manual-merge-distributor';
    }

    public static function label(): string
    {
        return 'Dystrybutor — test';
    }
}
