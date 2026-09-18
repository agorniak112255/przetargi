<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Hierarchia źródeł opisu: witryna producenta stoi najwyżej i zastępuje opis już zapisany na
 * karcie. Dystrybutor nie zastępuje niczego, a producent cudzej marki (uvex sprzedający HECKEL)
 * jest dla tej karty dystrybutorem. Bez HTTP.
 */
final class B2bManufacturerDescriptionTest extends TestCase
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

    public function test_manufacturer_description_replaces_the_one_on_the_card(): void
    {
        $product = $this->product('Opis przeniesiony z Presty, napisany dawno temu i nieaktualny.');
        $connector = $this->connector(MfrFakeConnector::class);
        $connector->descriptionText = 'Trzewik ARTRA ARMEN z podnoskiem kompozytowym, cholewka ze skóry licowej, wkładka antyprzebiciowa.';

        $this->sync($connector);

        $product->refresh();
        $this->assertSame($connector->descriptionText, $product->description);
        // poprzedni tekst zostaje w karcie — nadpisanie ma być odwracalne
        $this->assertSame(
            'Opis przeniesiony z Presty, napisany dawno temu i nieaktualny.',
            $product->enrichment_payload['replaced_description'] ?? null
        );
        $this->assertSame(sha1($connector->descriptionText), (string) B2bProductLink::query()->sole()->description_hash);
    }

    public function test_distributor_does_not_touch_the_description(): void
    {
        $product = $this->product('Opis karty, którego dystrybutor nie rusza, bo nie jest autorem wyrobu.');
        $connector = $this->connector(DistributorFakeConnector::class);
        $connector->descriptionText = 'Krótszy opis ze sklepu dystrybutora, inny niż ten na karcie wyrobu.';

        $this->sync($connector);

        // karta została dopasowana — opis był podany i świadomie odrzucony
        $this->assertSame(1, B2bProductLink::query()->count());
        $this->assertSame('Opis karty, którego dystrybutor nie rusza, bo nie jest autorem wyrobu.', $product->refresh()->description);
    }

    public function test_manufacturer_of_another_brand_is_a_distributor_for_this_card(): void
    {
        // karta marki HECKEL, konto uvex — dla tej karty uvex sprzedaje cudzą markę
        $product = $this->product('Opis karty HECKEL, którego konto uvex nie może zastąpić.', 'HECKEL');
        $connector = new OtherBrandShopConnector;
        $connector->descriptionText = 'Opis z witryny uvex, dotyczy wyrobu innej marki niż karta.';

        $this->sync($connector);

        $this->assertSame(1, B2bProductLink::query()->count());
        $this->assertSame('Opis karty HECKEL, którego konto uvex nie może zastąpić.', $product->refresh()->description);
    }

    public function test_label_does_not_replace_a_real_description(): void
    {
        $product = $this->product('Pełny opis wyrobu na karcie, dłuższy niż etykieta z cennika.');
        $connector = $this->connector(MfrFakeConnector::class);
        $connector->descriptionText = 'Jednostka: szt.';

        $this->sync($connector);

        $this->assertSame('Pełny opis wyrobu na karcie, dłuższy niż etykieta z cennika.', $product->refresh()->description);
    }

    public function test_second_run_does_not_rewrite_the_same_description(): void
    {
        $this->product('Opis z AI, który producent zastąpi w pierwszym przebiegu.');
        $connector = $this->connector(MfrFakeConnector::class);
        $connector->descriptionText = 'Trzewik ARTRA ARMEN z podnoskiem kompozytowym i wkładką antyprzebiciową.';

        $first = $this->sync($connector);
        $second = $this->sync($connector);

        $this->assertSame(1, $first['descriptions']);
        // drugi przebieg nie ma czego zapisywać — inaczej karta byłaby zmieniana w kółko
        $this->assertSame(0, $second['descriptions']);
        $this->assertSame($connector->descriptionText, Product::query()->sole()->description);
    }

    private function product(string $description, string $manufacturer = 'ARTRA'): Product
    {
        return Product::query()->create([
            'sku' => 'P1',
            'name' => 'Trzewik ARMEN',
            'manufacturer' => $manufacturer,
            'description' => $description,
        ]);
    }

    /**
     * @param  class-string<MfrFakeConnector|DistributorFakeConnector>  $class
     */
    private function connector(string $class): MfrFakeConnector|DistributorFakeConnector
    {
        return new $class;
    }

    /**
     * @return array<string, mixed>
     */
    private function sync(B2bConnector $connector): array
    {
        $account = B2bAccount::query()->firstOrCreate(
            ['username' => 'jan'],
            [
                'password' => 'sekret',
                'sites' => [$connector::host()],
                'connector' => $connector::key(),
                'created_by' => $this->user->id,
                'updated_by' => $this->user->id,
            ]
        );

        return app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, connector: $connector);
    }
}

/** Łącznik testowy witryny producenta — marka konta zgodna z marką karty. */
final class MfrFakeConnector implements B2bConnector, B2bManufacturerSite
{
    public string $descriptionText = '';

    public static function ownBrand(): string
    {
        return 'ARTRA';
    }

    public static function key(): string
    {
        return 'mfrfake';
    }

    public static function label(): string
    {
        return 'Producent testowy';
    }

    public static function host(): string
    {
        return 'mfrfake.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield new B2bRemoteProduct(
            remoteId: 'P1',
            sku: 'P1',
            name: 'Trzewik ARMEN',
            sourceUrl: 'https://mfrfake.example.test/p/P1',
        );
    }

    public function totalProducts(): int
    {
        return 1;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'ARTRA';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 120.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return $this->descriptionText;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}

/**
 * Witryna producenta, która prowadzi też sklep cudzej marki (uvex ↔ HECKEL) — dla takiej karty
 * jest dystrybutorem i opisu nie zastępuje.
 */
final class OtherBrandShopConnector implements B2bConnector, B2bManufacturerSite
{
    public string $descriptionText = '';

    public static function ownBrand(): string
    {
        return 'uvex';
    }

    public static function key(): string
    {
        return 'otherbrand';
    }

    public static function label(): string
    {
        return 'uvex testowy';
    }

    public static function host(): string
    {
        return 'otherbrand.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield new B2bRemoteProduct(
            remoteId: 'P1',
            sku: 'P1',
            name: 'Trzewik ARMEN',
            sourceUrl: 'https://otherbrand.example.test/p/P1',
        );
    }

    public function totalProducts(): int
    {
        return 1;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        // konektor uvex podaje markę WYROBU, nie markę sklepu
        return 'HECKEL';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 120.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return $this->descriptionText;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}

/** Łącznik testowy dystrybutora — bez markera producenta. */
final class DistributorFakeConnector implements B2bConnector
{
    public string $descriptionText = '';

    public static function key(): string
    {
        return 'distfake';
    }

    public static function label(): string
    {
        return 'Dystrybutor testowy';
    }

    public static function host(): string
    {
        return 'distfake.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield new B2bRemoteProduct(
            remoteId: 'P1',
            sku: 'P1',
            name: 'Trzewik ARMEN',
            sourceUrl: 'https://distfake.example.test/p/P1',
        );
    }

    public function totalProducts(): int
    {
        return 1;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'ARTRA';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 120.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return $this->descriptionText;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }
}
