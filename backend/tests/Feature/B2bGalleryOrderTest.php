<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bManufacturerSite;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\Enrichment\ProductImageDownloader;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Galeria karty u producenta: pierwsze zdjęcie ze sklepu przedstawia wyrób, dalsze to podeszwa i ujęcia serii.
 *
 * Testujący zgłosił, że na liście widać podeszwy zamiast cholewek. Powód: zdjęcie wyrobu karta miała już
 * z wcześniejszego wzbogacania z sieci, więc przebieg producenta pomijał je jako znane i stemplował swoim
 * kontem jedyne zdjęcie, którego karta nie miała — podeszwę. To ona wygrywała pierwszeństwo producenta
 * w ProductImage::resequence i zostawała zdjęciem głównym.
 */
final class B2bGalleryOrderTest extends TestCase
{
    use RefreshDatabase;

    private const SHOE = 'https://cdn.shopify.com/s/files/1/0994/8672/8533/files/AROX_7333_641460_S1_PL_ESD.png';

    private const SOLE = 'https://cdn.shopify.com/s/files/1/0994/8672/8533/files/Lyftor-black.png';

    private User $user;

    private B2bAccount $account;

    private GalleryFakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
        $this->account = B2bAccount::query()->create([
            'username' => 'artra',
            'password' => 'sekret',
            'sites' => [GalleryFakeConnector::host()],
            'connector' => GalleryFakeConnector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
        $this->connector = new GalleryFakeConnector;
    }

    public function test_shoe_from_the_web_takes_the_lead_over_the_sole_from_the_shop(): void
    {
        $product = $this->product();
        // karta ma zdjęcie wyrobu z wcześniejszego wzbogacania: ten sam plik, ale bez konta dostawcy
        $shoe = $this->webImage($product, self::SHOE, $this->png('shoe'));
        $this->connector->urls = [self::SHOE, self::SOLE];

        $this->sync();

        $this->assertSame(2, ProductImage::query()->where('product_id', $product->id)->count());
        $shoe->refresh();
        $this->assertTrue((bool) $shoe->is_primary);
        $this->assertSame(0, (int) $shoe->sort_order);
        $this->assertSame($this->account->id, $shoe->b2b_account_id);

        $sole = ProductImage::query()->where('product_id', $product->id)->where('sort_order', 1)->sole();
        $this->assertSame(self::SOLE, (string) $sole->source_url);
        $this->assertFalse((bool) $sole->is_primary);
    }

    public function test_same_shopify_file_under_the_shop_domain_is_not_downloaded_again(): void
    {
        $product = $this->product();
        // ten sam plik, jaki galeria poda spod cdn.shopify.com — sklep wydaje go też pod własną domeną,
        // z żądanym rozmiarem w zapytaniu; pobrany drugi raz miałby inne bajty i założyłby drugi wiersz
        $shoe = $this->webImage(
            $product,
            'https://artra.example.test/cdn/shop/files/AROX_7333_641460_S1_PL_ESD.png?v=1785835168&width=1728',
            $this->png('shoe'),
        );
        $this->connector->urls = [self::SHOE, self::SOLE];

        $this->sync();

        $this->assertSame(2, ProductImage::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, $this->connector->downloads[self::SHOE] ?? 0);
        $shoe->refresh();
        $this->assertTrue((bool) $shoe->is_primary);
        $this->assertSame($this->account->id, $shoe->b2b_account_id);
    }

    public function test_second_run_changes_nothing(): void
    {
        $product = $this->product();
        $this->connector->urls = [self::SHOE, self::SOLE];
        $this->sync();
        $before = ProductImage::query()->where('product_id', $product->id)
            ->orderBy('sort_order')->get(['source_url', 'sort_order', 'is_primary', 'updated_at']);

        $this->sync();

        $after = ProductImage::query()->where('product_id', $product->id)
            ->orderBy('sort_order')->get(['source_url', 'sort_order', 'is_primary', 'updated_at']);
        $this->assertEquals($before->toArray(), $after->toArray());
        $this->assertSame(self::SHOE, (string) $after->first()->source_url);
    }

    public function test_photo_removed_by_hand_is_neither_downloaded_nor_added_again(): void
    {
        $product = $this->product();
        $this->connector->urls = [self::SHOE, self::SOLE];
        $this->sync();
        $sole = ProductImage::query()->where('product_id', $product->id)->where('source_url', self::SOLE)->sole();
        ProductImageRejection::rejectAndDelete($sole, ProductImageRejection::REASON_MANUAL, $this->user->id);

        $this->sync();

        $left = ProductImage::query()->where('product_id', $product->id)->get();
        $this->assertSame([self::SHOE], $left->pluck('source_url')->map(static fn ($u): string => (string) $u)->all());
        $this->assertTrue((bool) $left->first()->is_primary);
        // pierwszy przebieg pobrał podeszwę, drugi już o nią nie prosił
        $this->assertSame(1, $this->connector->downloads[self::SOLE] ?? 0);
    }

    public function test_key_joins_both_shopify_addresses_of_one_file(): void
    {
        $this->assertSame(
            ProductImageDownloader::sameFileKey(self::SHOE),
            ProductImageDownloader::sameFileKey(
                'https://artra.example.test/cdn/shop/files/AROX_7333_641460_S1_PL_ESD.png?v=1785835168&width=1728'
            ),
        );
        // różne pliki tego samego sklepu zostają różne
        $this->assertNotSame(
            ProductImageDownloader::sameFileKey(self::SHOE),
            ProductImageDownloader::sameFileKey(self::SOLE),
        );
        // poza Shopify o tożsamości decyduje cały adres, bo zapytanie bywa jedynym, co odróżnia obrazy
        $this->assertNotSame(
            ProductImageDownloader::sameFileKey('https://sklep.example/foto.php?id=1'),
            ProductImageDownloader::sameFileKey('https://sklep.example/foto.php?id=2'),
        );
    }

    private function product(): Product
    {
        return Product::query()->create([
            'sku' => 'AROX 7333 641460 S1 PL ESD',
            'name' => 'AROX 7333 641460 S1 PL ESD',
            'manufacturer' => 'ARTRA',
        ]);
    }

    /** Zdjęcie wyłowione z sieci: bez konta dostawcy, tak jak zapisuje je wzbogacanie. */
    private function webImage(Product $product, string $url, string $bytes): ProductImage
    {
        $image = (new ProductImageDownloader)->storeBytes($product, $bytes, 'image/png', $url, 0);
        $this->assertNotNull($image);

        return $image;
    }

    private function sync(): void
    {
        app(B2bAccountSyncRunner::class)->run(
            $this->account->fresh(),
            delayMs: 0,
            connector: $this->connector,
        );
    }

    /** Dwa różne pliki PNG — dedup po sumie kontrolnej ma je rozróżniać. */
    private function png(string $seed): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, $seed === 'shoe' ? 0x102030 : 0xA0B0C0);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

/**
 * Łącznik testowy witryny producenta z galerią: kolejność adresów jest tą ze sklepu — wyrób, potem podeszwa.
 */
final class GalleryFakeConnector implements B2bConnector, B2bImageGallery, B2bManufacturerSite
{
    /** @var list<string> */
    public array $urls = [];

    /** @var array<string, int> ile razy poproszono o bajty spod adresu */
    public array $downloads = [];

    public static function key(): string
    {
        return 'gallery';
    }

    public static function label(): string
    {
        return 'ARTRA testowa';
    }

    public static function host(): string
    {
        return 'artra.example.test';
    }

    public static function ownBrand(): string
    {
        return 'ARTRA';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        yield new B2bRemoteProduct(
            remoteId: 'AROX 7333 641460 S1 PL ESD',
            sku: 'AROX 7333 641460 S1 PL ESD',
            name: 'AROX 7333 641460 S1 PL ESD',
            sourceUrl: 'https://artra.example.test/products/arox-7333',
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
        return '';
    }

    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $this->urls;
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $this->downloads[$url] = ($this->downloads[$url] ?? 0) + 1;
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, str_contains($url, 'Lyftor') ? 0xA0B0C0 : 0x102030);
        ob_start();
        imagepng($image);

        return new B2bRemoteImage(bytes: (string) ob_get_clean(), mime: 'image/png', sourceUrl: $url);
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return $this->urls === [] ? null : $this->imageAt($this->urls[0]);
    }
}
