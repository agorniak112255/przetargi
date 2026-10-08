<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bConnector;
use App\Services\B2b\B2bFatalException;
use App\Services\B2b\B2bImageGallery;
use App\Services\B2b\B2bRemoteImage;
use App\Services\B2b\B2bRemotePrice;
use App\Services\B2b\B2bRemoteProduct;
use App\Services\Enrichment\ProductImageDownloader;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Bezpiecznik łącznika (utrata sesji, seria błędów HTTP — np. HoneywellB2bClient::send po 20 błędach z rzędu)
 * odpalony akurat przy pobieraniu zdjęcia. storeImage i storeGallery łapały każdy Throwable i zapisywały go
 * jako błąd zdjęcia, więc przebieg szedł dalej na zerwanej sesji, zamiast skończyć się jako „failed” — jak przy
 * cenie, opisie i plikach produktu.
 */
final class B2bSyncImageFatalTest extends TestCase
{
    use RefreshDatabase;

    private const FATAL = 'Seria 20 błędów HTTP z rzędu — przebieg przerwany.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->user = User::factory()->withRole('admin')->create();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fatalPoints(): array
    {
        return [
            'jedno zdjęcie karty' => ['image'],
            'lista zdjęć galerii' => ['imageUrls'],
            'zdjęcie z galerii' => ['imageAt'],
        ];
    }

    #[DataProvider('fatalPoints')]
    public function test_fatal_error_while_fetching_an_image_fails_the_run(string $point): void
    {
        $connector = $point === 'image' ? new ImageFatalFakeConnector : new ImageFatalGalleryFakeConnector;
        $connector->failAt = $point;
        $connector->error = new B2bFatalException(self::FATAL);
        $account = $this->account($connector);

        $error = $this->runExpectingFailure($account, $connector);

        $this->assertInstanceOf(B2bFatalException::class, $error);
        $this->assertSame(self::FATAL, $error->getMessage());
        $run = B2bSyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(B2bSyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(self::FATAL, $run->message);
        $this->assertSame('failed', $account->fresh()?->last_sync_status);
        $this->assertSame(self::FATAL, $account->fresh()?->last_sync_message);
        // karta pierwszej pozycji zapisała się przed zdjęciem; drugiej przebieg już nie dotknął
        $this->assertSame(['IMG-1'], Product::query()->orderBy('id')->pluck('sku')->all());
    }

    public function test_gallery_cut_short_by_the_fatal_error_keeps_one_primary_photo(): void
    {
        $connector = new ImageFatalGalleryFakeConnector;
        $connector->urls = [ImageFatalGalleryFakeConnector::FIRST, ImageFatalGalleryFakeConnector::SECOND];
        $connector->failingUrl = ImageFatalGalleryFakeConnector::SECOND;
        $connector->error = new B2bFatalException(self::FATAL);
        $account = $this->account($connector);
        // karta ma już zdjęcie z sieci — główne, z numerem 0, tak jak pierwsze zdjęcie zapisane z galerii dostawcy
        $product = Product::query()->create(['sku' => 'IMG-1', 'name' => 'Produkt IMG-1', 'manufacturer' => 'Fake Shop']);
        $web = (new ProductImageDownloader)->storeBytes(
            $product,
            ImageFatalGalleryFakeConnector::png(0xA0B0C0),
            'image/png',
            'https://web.example.test/img-1.png',
            0,
        );
        $this->assertNotNull($web);

        $this->assertInstanceOf(B2bFatalException::class, $this->runExpectingFailure($account, $connector));

        $images = ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->get();
        $this->assertSame(
            [ImageFatalGalleryFakeConnector::FIRST, 'https://web.example.test/img-1.png'],
            $images->pluck('source_url')->map(static fn ($url): string => (string) $url)->all(),
        );
        $this->assertSame([0, 1], $images->pluck('sort_order')->map(static fn ($order): int => (int) $order)->all());
        $this->assertSame([true, false], $images->pluck('is_primary')->map(static fn ($primary): bool => (bool) $primary)->all());
    }

    public function test_ordinary_image_error_is_logged_and_the_run_goes_on(): void
    {
        $connector = new ImageFatalGalleryFakeConnector;
        $connector->failAt = 'imageAt';
        $connector->error = new RuntimeException('HTTP 404');
        $account = $this->account($connector);

        $result = app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, connector: $connector);

        $this->assertContains('IMG-1: zdjęcie — HTTP 404', $result['errors']);
        $this->assertSame(B2bSyncRun::STATUS_OK, B2bSyncRun::query()->findOrFail($result['sync_run_id'])->status);
        $this->assertSame(['IMG-1', 'IMG-2'], Product::query()->orderBy('id')->pluck('sku')->all());
    }

    private function account(B2bConnector $connector): B2bAccount
    {
        return B2bAccount::query()->create([
            'username' => 'jan',
            'password' => 'sekret',
            'sites' => [$connector::host()],
            'connector' => $connector::key(),
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function runExpectingFailure(B2bAccount $account, B2bConnector $connector): Throwable
    {
        try {
            app(B2bAccountSyncRunner::class)->run($account->fresh(), delayMs: 0, connector: $connector);
        } catch (Throwable $e) {
            return $e;
        }
        $this->fail('Przebieg miał się zakończyć błędem.');
    }
}

/** Łącznik testowy z jednym zdjęciem na kartę: image() pierwszej pozycji rzuca podany wyjątek. */
final class ImageFatalFakeConnector implements B2bConnector
{
    public string $failAt = 'image';

    public ?Throwable $error = null;

    public static function key(): string
    {
        return 'imagefatal';
    }

    public static function label(): string
    {
        return 'Test błędu zdjęcia';
    }

    public static function host(): string
    {
        return 'imagefatal.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        foreach (['IMG-1', 'IMG-2'] as $sku) {
            yield new B2bRemoteProduct(remoteId: $sku, sku: $sku, name: 'Produkt '.$sku);
        }
    }

    public function totalProducts(): int
    {
        return 2;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Fake Shop';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 10.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        if ($this->failAt === 'image' && $product->sku === 'IMG-1' && $this->error !== null) {
            throw $this->error;
        }

        return null;
    }
}

/**
 * Łącznik testowy z galerią: imageUrls() albo imageAt() pierwszej pozycji rzuca podany wyjątek (imageAt — przy
 * $failingUrl, a bez niego przy każdym adresie). Pozostałe adresy dają różne pliki PNG.
 */
final class ImageFatalGalleryFakeConnector implements B2bConnector, B2bImageGallery
{
    public const FIRST = 'https://imagefatalgallery.example.test/img/IMG-1-a.png';

    public const SECOND = 'https://imagefatalgallery.example.test/img/IMG-1-b.png';

    public string $failAt = 'imageAt';

    public ?Throwable $error = null;

    /** @var list<string> galeria pierwszej pozycji */
    public array $urls = [self::FIRST];

    public ?string $failingUrl = null;

    public static function key(): string
    {
        return 'imagefatalgallery';
    }

    public static function label(): string
    {
        return 'Test błędu galerii';
    }

    public static function host(): string
    {
        return 'imagefatalgallery.example.test';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self;
    }

    public function login(): void {}

    public function products(): iterable
    {
        foreach (['IMG-1', 'IMG-2'] as $sku) {
            yield new B2bRemoteProduct(remoteId: $sku, sku: $sku, name: 'Produkt '.$sku);
        }
    }

    public function totalProducts(): int
    {
        return 2;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Fake Shop';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        return new B2bRemotePrice(net: 10.0);
    }

    public function description(B2bRemoteProduct $product): string
    {
        return '';
    }

    public function imageUrls(B2bRemoteProduct $product): array
    {
        if ($product->sku !== 'IMG-1') {
            return [];
        }
        if ($this->failAt === 'imageUrls' && $this->error !== null) {
            throw $this->error;
        }

        return $this->urls;
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        if ($this->failAt === 'imageAt' && $this->error !== null
            && ($this->failingUrl === null || $this->failingUrl === $url)) {
            throw $this->error;
        }

        return new B2bRemoteImage(bytes: self::png(crc32($url) & 0xFFFFFF), mime: 'image/png', sourceUrl: $url);
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        return null;
    }

    /** Jednolity obrazek 8×8 — inny kolor = inny plik dla deduplikacji po sumie kontrolnej. */
    public static function png(int $colour): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, $colour);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
