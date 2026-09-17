<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bCatalogSync;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\B2b\B2bRemoteShopField;
use App\Services\B2b\B2bRemoteVariant;
use App\Services\B2b\B2bShopFieldSource;
use App\Services\B2b\B2bVariantConnector;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Karta wyrobu u dostawcy (ProductShopCard) w synchronizacji katalogu — obie ścieżki zapisu, brama czasu
 * (B2bCatalogSync::SHOP_FIELDS_TTL_DAYS), rozdział per konto i niezmienność opisu karty. Bez HTTP.
 */
final class B2bShopFieldsSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private B2bAccount $account;

    private ShopFieldsFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = $this->b2bAccount('jan');
        $this->connector = new ShopFieldsFakeConnector;
    }

    public function test_shop_card_rows_are_saved_with_sections_and_provenance(): void
    {
        $this->connector->items = [$this->remote()];
        $this->connector->fields = [
            new B2bRemoteShopField('Informacje handlowe', 'Dział towarowy', 'Rękawice'),
            new B2bRemoteShopField('Informacje handlowe', 'Jednostka sprzedaży', 'para'),
            new B2bRemoteShopField('Parametry techniczne', 'Norma', 'EN 388:2016'),
            // powtórzona etykieta zostaje, pusta wartość odpada
            new B2bRemoteShopField('Parametry techniczne', 'Norma', 'EN 420:2003'),
            new B2bRemoteShopField('Parametry techniczne', 'Kolor', '   '),
        ];

        $result = $this->sync();

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['shop_fields']);
        $product = Product::query()->sole();
        $card = ProductShopCard::query()->sole();
        $this->assertSame($product->id, $card->product_id);
        $this->assertSame($this->account->id, $card->b2b_account_id);
        $this->assertSame('https://shopfields.example.test/p/P1', $card->source_url);
        $this->assertNotNull($card->synced_at);
        $this->assertSame([
            [
                'section' => 'Informacje handlowe',
                'rows' => [
                    ['name' => 'Dział towarowy', 'value' => 'Rękawice'],
                    ['name' => 'Jednostka sprzedaży', 'value' => 'para'],
                ],
            ],
            [
                'section' => 'Parametry techniczne',
                'rows' => [
                    ['name' => 'Norma', 'value' => 'EN 388:2016'],
                    ['name' => 'Norma', 'value' => 'EN 420:2003'],
                ],
            ],
        ], $card->fields);

        $this->assertStringContainsString('karty ze sklepu: 1', (string) $this->account->fresh()->last_sync_message);
    }

    public function test_variant_connector_path_also_saves_shop_card(): void
    {
        $account = $this->b2bAccount('supon', ShopFieldsVariantConnector::key(), ShopFieldsVariantConnector::host());
        $connector = new ShopFieldsVariantConnector;
        $connector->fields = [new B2bRemoteShopField('', 'Podłoże', 'FN - folia samoprzylepna')];

        $result = $this->sync($account, $connector);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['shop_fields']);
        $product = Product::query()->sole();
        $card = ProductShopCard::query()->sole();
        $this->assertSame($product->id, $card->product_id);
        $this->assertSame($account->id, $card->b2b_account_id);
        $this->assertSame(
            [['section' => '', 'rows' => [['name' => 'Podłoże', 'value' => 'FN - folia samoprzylepna']]]],
            $card->fields,
        );
        $this->assertSame(1, $connector->shopFieldsCalls);
    }

    public function test_ttl_gate_blocks_second_run_and_lets_expired_card_refresh(): void
    {
        $this->connector->items = [$this->remote()];
        $this->connector->fields = [new B2bRemoteShopField('', 'Kolor', 'czarny')];

        $this->sync();
        $this->assertSame(1, $this->connector->shopFieldsCalls);

        // zaraz po pierwszym przebiegu — żadnego zapytania do sklepu
        $this->connector->fields = [new B2bRemoteShopField('', 'Kolor', 'granatowy')];
        $again = $this->sync();
        $this->assertSame(1, $this->connector->shopFieldsCalls);
        $this->assertSame(0, $again['shop_fields']);
        $this->assertSame('czarny', ProductShopCard::query()->sole()->fields[0]['rows'][0]['value']);

        // po upływie TTL karta jest odświeżana
        $this->expireShopCards();
        $refreshed = $this->sync();
        $this->assertSame(2, $this->connector->shopFieldsCalls);
        $this->assertSame(1, $refreshed['shop_fields']);
        $card = ProductShopCard::query()->sole();
        $this->assertSame('granatowy', $card->fields[0]['rows'][0]['value']);
        $this->assertTrue($card->synced_at->gt(now()->subDay()));
    }

    public function test_supplier_without_fields_removes_stored_card(): void
    {
        $this->connector->items = [$this->remote()];
        $this->connector->fields = [new B2bRemoteShopField('', 'Kolor', 'czarny')];
        $this->sync();
        $this->assertSame(1, ProductShopCard::query()->count());

        $this->expireShopCards();
        $this->connector->fields = [];
        $result = $this->sync();

        $this->assertSame(2, $this->connector->shopFieldsCalls);
        $this->assertSame(0, $result['shop_fields']);
        $this->assertSame(0, ProductShopCard::query()->count());
        // sama karta wyrobu zostaje
        $this->assertSame(1, Product::query()->count());
    }

    public function test_each_b2b_account_keeps_its_own_card(): void
    {
        $this->connector->items = [$this->remote()];
        $this->connector->fields = [new B2bRemoteShopField('', 'Kolor', 'czarny')];
        $this->sync();

        $second = $this->b2bAccount('anna');
        $other = new ShopFieldsFakeConnector;
        $other->items = [$this->remote()];
        $other->fields = [new B2bRemoteShopField('', 'Kolor', 'granatowy')];
        $this->sync($second, $other);

        $product = Product::query()->sole();
        $cards = ProductShopCard::query()->where('product_id', $product->id)->orderBy('b2b_account_id')->get();
        $this->assertCount(2, $cards);
        $this->assertSame(
            [$this->account->id, $second->id],
            $cards->pluck('b2b_account_id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
        $this->assertSame('czarny', $cards[0]->fields[0]['rows'][0]['value']);
        $this->assertSame('granatowy', $cards[1]->fields[0]['rows'][0]['value']);
    }

    public function test_shop_fields_error_does_not_block_price_or_card(): void
    {
        $this->connector->items = [$this->remote()];
        $this->connector->fieldsError = 'Sklep nie odpowiedział';

        $result = $this->sync();

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['shop_fields']);
        $product = Product::query()->sole();
        $this->assertSame('P1', $product->sku);
        $this->assertSame('40.00', ProductSourcePrice::query()->where('product_id', $product->id)->value('purchase_price'));
        $this->assertSame(0, ProductShopCard::query()->count());
        $this->assertContains(
            'P1: dane z karty w sklepie nie zostały odczytane (Sklep nie odpowiedział) — karta zapisana bez nich',
            $this->logTexts($result),
        );
    }

    public function test_shop_fields_do_not_touch_description_or_link_hashes(): void
    {
        $this->connector->items = [$this->remote()];
        $this->connector->descriptionText = 'Rękawice powlekane nitrylem, mankiet ściągaczowy, chwyt w oleju.';
        $this->connector->fields = [new B2bRemoteShopField('', 'Kolor', 'czarny')];

        $first = $this->sync();
        $this->assertSame(1, $first['descriptions']);
        $product = Product::query()->sole();
        $link = B2bProductLink::query()->sole();
        $descriptionHash = (string) $link->description_hash;
        $this->assertSame($this->connector->descriptionText, $product->description);
        $this->assertSame(sha1($this->connector->descriptionText), $descriptionHash);
        $this->assertNull($link->source_description_hash);

        // odświeżenie samych pól karty w sklepie
        $this->expireShopCards();
        $this->connector->fields = [new B2bRemoteShopField('Parametry', 'Kolor', 'granatowy')];
        $second = $this->sync();

        $this->assertSame(1, $second['shop_fields']);
        $this->assertSame(0, $second['descriptions']);
        $product->refresh();
        $link->refresh();
        $this->assertSame($this->connector->descriptionText, $product->description);
        $this->assertSame($descriptionHash, $link->description_hash);
        $this->assertNull($link->source_description_hash);
        $this->assertSame('granatowy', ProductShopCard::query()->sole()->fields[0]['rows'][0]['value']);
    }

    private function remote(): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: 'P1',
            sku: 'P1',
            name: 'Rękawice Anro',
            sourceUrl: 'https://shopfields.example.test/p/P1',
        );
    }

    private function b2bAccount(string $username, ?string $connector = null, ?string $host = null): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => $username,
            'password' => 'sekret',
            'sites' => [$host ?? ShopFieldsFakeConnector::host()],
            'connector' => $connector ?? ShopFieldsFakeConnector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    /** Cofa czas pobrania wszystkich kart poza bramę TTL. */
    private function expireShopCards(): void
    {
        ProductShopCard::query()->toBase()->update([
            'synced_at' => now()->subDays(B2bCatalogSync::SHOP_FIELDS_TTL_DAYS + 1),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(?B2bAccount $account = null, ?B2bConnector $connector = null): array
    {
        return app(B2bAccountSyncRunner::class)->run(
            ($account ?? $this->account)->fresh(),
            delayMs: 0,
            connector: $connector ?? $this->connector,
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

/** Łącznik testowy bez sieci: stała cena, opis i wiersze karty ze sklepu ustawiane w teście. */
final class ShopFieldsFakeConnector implements B2bConnector, B2bShopFieldSource
{
    /** @var list<B2bRemoteProduct> */
    public array $items = [];

    /** @var list<B2bRemoteShopField> */
    public array $fields = [];

    public ?string $fieldsError = null;

    public string $descriptionText = '';

    /** Ile razy synchronizacja poprosiła o wiersze karty — brama TTL ma je oszczędzać. */
    public int $shopFieldsCalls = 0;

    public static function key(): string
    {
        return 'shopfields';
    }

    public static function label(): string
    {
        return 'Sklep testowy';
    }

    public static function host(): string
    {
        return 'shopfields.example.test';
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
        return 'Anro';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 40.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return $this->descriptionText;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }

    public function shopFields(B2bRemoteProduct $product): array
    {
        $this->shopFieldsCalls++;
        if ($this->fieldsError !== null) {
            throw new RuntimeException($this->fieldsError);
        }

        return $this->fields;
    }
}

/** Łącznik testowy z wersjami (ścieżka SignProject) podający też wiersze karty ze sklepu. */
final class ShopFieldsVariantConnector implements B2bShopFieldSource, B2bVariantConnector
{
    /** @var list<B2bRemoteShopField> */
    public array $fields = [];

    public int $shopFieldsCalls = 0;

    public static function key(): string
    {
        return 'shopfieldssign';
    }

    public static function label(): string
    {
        return 'Znaki testowe';
    }

    public static function host(): string
    {
        return 'shopfieldssign.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield $this->remote();
    }

    public function totalProducts(): int
    {
        return 1;
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
        return [
            new B2bRemoteVariant(
                remoteId: '101',
                label: '10 x 14,8 cm',
                attributes: ['Format' => '10 x 14,8 cm'],
                price: new B2bRemotePrice(0.97),
                sourceUrl: 'https://shopfieldssign.example.test/pl/products/znak-101',
            ),
        ];
    }

    public function totalVariants(): int
    {
        return 1;
    }

    public function listedVariantIds(): ?array
    {
        return ['101'];
    }

    public function runBudgetMinutes(): ?int
    {
        return null;
    }

    public function shopFields(B2bRemoteProduct $product): array
    {
        $this->shopFieldsCalls++;

        return $this->fields;
    }

    private function remote(): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: 'BB014',
            sku: 'BB014',
            name: 'Znak BB014',
            sourceUrl: 'https://shopfieldssign.example.test/pl/products/znak-101',
            raw: ['versions' => [['id' => '101', 'name' => '10 x 14,8 cm']]],
        );
    }
}
