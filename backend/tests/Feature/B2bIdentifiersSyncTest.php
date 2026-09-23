<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bAccountManufacturerRule;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bManufacturerRules;
use App\Services\B2b\B2bRemoteIdentifier;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Identyfikatory pozycji z łącznika (EAN, kod producenta) w product_identifiers — z pochodzeniem, bez nadpisywania
 * i bez kasowania; także ponowny przebieg na kartach i powiązaniach sprzed wdrożenia (bez identyfikatorów).
 */
final class B2bIdentifiersSyncTest extends TestCase
{
    use RefreshDatabase;

    private B2bAccount $account;

    private IdentifierFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => [IdentifierFakeConnector::host()],
            'connector' => IdentifierFakeConnector::key(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $this->connector = new IdentifierFakeConnector;
    }

    public function test_group_saves_identifiers_per_position_with_provenance(): void
    {
        $this->connector->items = [$this->group(['S' => '5711074644834', 'M' => '5711074644803'], withCode: true)];

        $result = $this->sync();

        $product = Product::query()->sole();
        $rows = ProductIdentifier::query()->orderBy('position_key')->orderBy('type')->get();
        $this->assertCount(3, $rows);

        $ean = $rows->firstWhere('value', '5711074644834');
        $this->assertSame('ean', $ean->type);
        $this->assertSame('X1-S', $ean->position_key);
        $this->assertSame('05711074644834', $ean->normalized);
        $this->assertSame('S', $ean->variant_label);
        $this->assertSame('EanNumber', $ean->source_field);
        $this->assertSame('b2b:'.$this->account->id, $ean->source_key);
        $this->assertSame((int) $this->account->id, (int) $ean->b2b_account_id);
        $this->assertSame('Tegera', $ean->manufacturer);
        $this->assertSame('tegera', $ean->brand_key);
        $this->assertSame((int) $result['sync_run_id'], (int) $ean->b2b_sync_run_id);
        $this->assertSame([$product->id], $rows->pluck('product_id')->unique()->values()->all());

        // identyfikator bez pozycji należy do karty — pod jej remoteId
        $code = $rows->firstWhere('type', 'manufacturer_code');
        $this->assertSame('X1-S', $code->position_key);
        $this->assertSame('728', $code->value);
        $this->assertSame('728', $code->normalized);
    }

    public function test_rerun_on_legacy_rows_adds_identifiers_without_changing_card_status_and_without_duplicates(): void
    {
        // przebieg sprzed wdrożenia: karta i powiązania bez identyfikatorów
        $this->connector->items = [$this->group(['S' => '5711074644834', 'M' => '5711074644803'], identifiers: false)];
        $this->sync();
        $this->assertSame(0, ProductIdentifier::query()->count());

        $this->connector->items = [$this->group(['S' => '5711074644834', 'M' => '5711074644803'])];
        $second = $this->sync();

        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(2, ProductIdentifier::query()->count());
        $firstSeen = ProductIdentifier::query()->where('value', '5711074644834')->value('first_seen_at');

        $this->travel(2)->hours();
        $third = $this->sync();

        $this->assertSame(1, $third['unchanged']);
        $this->assertSame(2, ProductIdentifier::query()->count());
        $row = ProductIdentifier::query()->where('value', '5711074644834')->sole();
        $this->assertEquals($firstSeen, $row->first_seen_at);
        $this->assertTrue($row->last_seen_at->greaterThan($row->first_seen_at));
        $this->assertSame((int) $third['sync_run_id'], (int) $row->b2b_sync_run_id);
    }

    public function test_identifier_missing_from_source_is_marked_removed_and_cleared_when_it_returns(): void
    {
        $this->connector->items = [$this->group(['S' => '5711074644834', 'M' => '5711074644803'])];
        $this->sync();

        $this->connector->items = [$this->group(['S' => '5711074644834', 'M' => ''])];
        $this->sync();

        $this->assertSame(2, ProductIdentifier::query()->count());
        $this->assertNotNull(ProductIdentifier::query()->where('value', '5711074644803')->value('removed_at'));
        $this->assertNull(ProductIdentifier::query()->where('value', '5711074644834')->value('removed_at'));

        $this->connector->items = [$this->group(['S' => '5711074644834', 'M' => '5711074644803'])];
        $this->sync();

        $this->assertSame(2, ProductIdentifier::query()->count());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
    }

    public function test_connector_without_identifiers_keeps_saved_rows_and_split_off_size_takes_its_rows(): void
    {
        $this->connector->items = [$this->group(['S' => '5711074644834', 'M' => '5711074644803', 'L' => '5711074644810'])];
        $this->connector->prices = ['X1-S' => 25.5];
        $this->sync();
        $card = Product::query()->sole();

        // L w innej cenie — osobna karta; łącznik tym razem identyfikatorów nie podaje
        $this->connector->items = [
            $this->group(['S' => '', 'M' => ''], identifiers: false),
            $this->group(['L' => ''], identifiers: false),
        ];
        $this->connector->prices = ['X1-S' => 25.5, 'X1-L' => 30.0];
        $this->sync();

        $large = Product::query()->where('sku', 'X1-L')->sole();
        $this->assertSame(3, ProductIdentifier::query()->count());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        $this->assertSame($large->id, (int) ProductIdentifier::query()->where('position_key', 'X1-L')->value('product_id'));
        $this->assertSame(
            [$card->id],
            ProductIdentifier::query()->whereIn('position_key', ['X1-S', 'X1-M'])->pluck('product_id')->unique()->values()->all(),
        );
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->connector->items = [$this->group(['S' => '5711074644834'])];

        $this->sync(dryRun: true);

        $this->assertSame(0, ProductIdentifier::query()->count());
    }

    public function test_excluded_manufacturer_saves_nothing_and_disabled_price_on_existing_card_still_saves(): void
    {
        $this->connector->items = [$this->group(['S' => '5711074644834'])];
        $rule = B2bAccountManufacturerRule::query()->create([
            'b2b_account_id' => $this->account->id,
            'manufacturer' => 'Tegera',
            'manufacturer_key' => B2bManufacturerRules::key('Tegera'),
            'take_price' => false,
            'take_description' => false,
        ]);

        $this->sync();
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, ProductIdentifier::query()->count());

        // karta istnieje, cena producenta wyłączona — pozycja idzie drogą „tylko treść” i identyfikatory zapisuje
        $rule->update(['take_price' => true]);
        $this->sync();
        $rule->update(['take_price' => false, 'take_description' => true]);
        $this->connector->items = [$this->group(['S' => '5711074644834', 'M' => '5711074644803'])];
        $this->sync();

        $this->assertSame(2, ProductIdentifier::query()->count());
    }

    public function test_foreign_position_and_unknown_type_are_skipped_with_warning(): void
    {
        $item = $this->group(['S' => '5711074644834']);
        $this->connector->items = [new B2bRemoteProduct(
            remoteId: $item->remoteId,
            sku: $item->sku,
            name: $item->name,
            members: $item->members,
            identifiers: [
                ...$item->identifiers,
                new B2bRemoteIdentifier(type: 'ean', value: '5711074644803', remoteId: 'OBCY-1'),
                new B2bRemoteIdentifier(type: 'kolor', value: 'czarny'),
            ],
        )];

        $result = $this->sync();

        $this->assertSame(['5711074644834'], ProductIdentifier::query()->pluck('value')->all());
        $texts = implode("\n", array_column(B2bSyncRun::query()->findOrFail($result['sync_run_id'])->log, 'text'));
        $this->assertStringContainsString('5711074644803 wskazuje pozycję OBCY-1 spoza karty', $texts);
        $this->assertStringContainsString('nieznany rodzaj „kolor”', $texts);
    }

    public function test_values_differing_only_in_case_or_accents_are_one_row_like_mysql_unique(): void
    {
        $item = $this->group(['S' => '']);
        $this->connector->items = [new B2bRemoteProduct(
            remoteId: $item->remoteId,
            sku: $item->sku,
            name: $item->name,
            members: $item->members,
            identifiers: [
                new B2bRemoteIdentifier(type: 'source_code', value: 'Żółty-728'),
                new B2bRemoteIdentifier(type: 'source_code', value: 'zolty-728'),
                new B2bRemoteIdentifier(type: 'source_code', value: 'ABC  1'),
                new B2bRemoteIdentifier(type: 'source_code', value: 'abc 1'),
            ],
        )];

        $this->sync();
        $this->sync();

        $this->assertSame(['Żółty-728', 'ABC 1'], ProductIdentifier::query()->orderBy('id')->pluck('value')->all());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
    }

    public function test_deleting_card_removes_its_identifiers(): void
    {
        $this->connector->items = [$this->group(['S' => '5711074644834'])];
        $this->sync();

        Product::query()->sole()->delete();

        $this->assertSame(0, ProductIdentifier::query()->count());
    }

    /**
     * @param  array<string, string>  $sizes  rozmiar => EAN ('' = źródło EAN nie podaje)
     */
    private function group(array $sizes, bool $withCode = false, bool $identifiers = true): B2bRemoteProduct
    {
        $members = [];
        $found = [];
        foreach ($sizes as $size => $ean) {
            $id = 'X1-'.$size;
            $members[] = ['remote_id' => $id, 'sku' => $id, 'name' => 'Rękawice Tegera 728 rozm. '.$size];
            if ($ean !== '') {
                $found[] = new B2bRemoteIdentifier(type: 'ean', value: $ean, remoteId: $id, label: $size, field: 'EanNumber');
            }
        }
        if ($withCode) {
            $found[] = new B2bRemoteIdentifier(type: 'manufacturer_code', value: '728', field: 'Kod producenta');
        }

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $members[0]['sku'],
            name: 'Rękawice Tegera 728',
            members: $members,
            identifiers: $identifiers ? $found : null,
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
}

/** Łącznik testowy bez sieci: grupy rozmiarów z identyfikatorami, cena wg remoteId (domyślnie 25,50). */
final class IdentifierFakeConnector implements B2bConnector
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    /** @var array<string, float> */
    public array $prices = [];

    public static function key(): string
    {
        return 'identifiers';
    }

    public static function label(): string
    {
        return 'Identyfikatory testowe';
    }

    public static function host(): string
    {
        return 'identifiers.example.test';
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
        return 'Tegera';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: $this->prices[$product->remoteId] ?? 25.5);
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
