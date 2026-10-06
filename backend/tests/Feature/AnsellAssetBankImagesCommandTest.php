<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AnsellAssetBankImagesCommand;
use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\B2b\AnsellAssetBankClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Packshot z portalu zdjęć Ansella na karcie cennika: wyszukanie po modelu, prawa użycia ze strony pliku, pobranie
 * JPG przez formularz i podgląd portalu, zapis jako zdjęcie konta przed zdjęciem ze sklepu. Strony portalu
 * odtworzone z zalogowanego konta #37 (06.10.2026), skrócone.
 */
final class AnsellAssetBankImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const LOGGED = '<a href="/assetbank-ansell/action/logout?CSRF=x">Logout</a>';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        AnsellAssetBankImagesCommand::$clientFactory = static fn (B2bAccount $a): AnsellAssetBankClient => new AnsellAssetBankClient((string) $a->username, (string) $a->password, 0, static function (int $ms): void {});
    }

    protected function tearDown(): void
    {
        AnsellAssetBankImagesCommand::$clientFactory = null;
        parent::tearDown();
    }

    public function test_packshot_becomes_the_primary_image_before_the_shop_photo(): void
    {
        $account = B2bAccount::query()->create([
            'username' => 'dystrybutor@example.com', 'password' => 'sekret',
            'sites' => ['https://assetbank.ansell.com/assetbank-ansell/action/viewLogin'],
        ]);
        $edge = $this->card('48501110', 'EDGE 48501');
        $sizeVariant = $this->card('48501100', 'EDGE 48501');
        ProductImage::query()->create([
            'product_id' => $edge->id, 'path' => 'products/'.$edge->id.'/shop.jpg', 'source_url' => 'https://cas-technik.eu/media/48-501.jpg',
            'is_primary' => true, 'sort_order' => 0, 'checksum' => str_repeat('a', 64),
        ]);
        $requests = [];
        $this->fakePortal($requests);

        $this->artisan('products:ansell-assetbank-images', ['--id' => [$edge->id, $sizeVariant->id]])
            ->expectsOutputToContain('zapisano 2')
            ->assertSuccessful();

        $images = ProductImage::query()->where('product_id', $edge->id)->orderBy('sort_order')->get();
        $this->assertSame(AnsellAssetBankClient::assetUrl(24770), $images[0]->source_url);
        $this->assertSame($account->id, $images[0]->b2b_account_id);
        $this->assertTrue((bool) $images[0]->is_primary);
        $this->assertSame('https://cas-technik.eu/media/48-501.jpg', $images[1]->source_url, 'zdjęcie ze sklepu zostaje za packshotem');
        $this->assertSame(1, ProductImage::query()->where('product_id', $sizeVariant->id)->count());
        $this->assertSame(1, collect($requests)->filter(fn (string $r): bool => str_contains($r, 'action/search'))->count(), 'jedno wyszukiwanie na model');
        $this->assertSame(1, collect($requests)->filter(fn (string $r): bool => str_contains($r, 'servlet/display'))->count(), 'jedno pobranie pliku na przebieg');
        $this->assertSame(1, collect($requests)->filter(fn (string $r): bool => str_contains($r, 'viewAsset?id=24770'))->count());

        // drugi przebieg pomija karty, które mają już zdjęcie z portalu
        $this->artisan('products:ansell-assetbank-images', ['--id' => [$edge->id, $sizeVariant->id]])
            ->expectsOutputToContain('Kart 0')
            ->assertSuccessful();
    }

    public function test_internal_use_packshot_is_skipped_for_the_next_one(): void
    {
        B2bAccount::query()->create([
            'username' => 'dystrybutor@example.com', 'password' => 'sekret',
            'sites' => ['https://assetbank.ansell.com/assetbank-ansell/action/viewLogin'],
        ]);
        $edge = $this->card('48501110', 'EDGE 48501');
        $requests = [];
        $this->fakePortal($requests, internalFront: true);

        $this->artisan('products:ansell-assetbank-images', ['--id' => [$edge->id], '--dry-run' => true])
            ->expectsOutputToContain('48-501 EDGE Black and white EMEA - U-Card (#42595, Unlimited Use)')
            ->assertSuccessful();
        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_layered_file_still_converting_is_fetched_again(): void
    {
        B2bAccount::query()->create([
            'username' => 'dystrybutor@example.com', 'password' => 'sekret',
            'sites' => ['https://assetbank.ansell.com/assetbank-ansell/action/viewLogin'],
        ]);
        $edge = $this->card('48501110', 'EDGE 48501');
        $requests = [];
        $this->fakePortal($requests, placeholderFirst: true);

        $this->artisan('products:ansell-assetbank-images', ['--id' => [$edge->id]])
            ->expectsOutputToContain('zapisano 1')
            ->assertSuccessful();

        $this->assertSame(1, ProductImage::query()->where('product_id', $edge->id)->count());
        $this->assertSame(2, collect($requests)->filter(fn (string $r): bool => str_contains($r, 'servlet/display'))->count(), 'zaślepka, potem plik');
    }

    private function card(string $sku, string $name): Product
    {
        return Product::query()->create(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Ansell', 'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 1]);
    }

    /** @param  list<string>  $requests */
    private function fakePortal(array &$requests, bool $internalFront = false, bool $placeholderFirst = false): void
    {
        $base = AnsellAssetBankClient::BASE;
        $results = '<ul class="panel__attributes"><li><a href="viewAsset?id=24772&amp;index=0" title="View asset details"> EDGE 48-501 Black Product Prop EMEA - Pipe </a></li><li> Product images - static </li><li> EMEA </li><li> Document Type: Image </li></ul>'
            .'<ul class="panel__attributes"><li><a href="viewAsset?id=24770&amp;index=1" title="View asset details"> EDGE 48-501 Black Product EMEA - Front </a></li><li> Product images - static </li><li> EMEA </li><li> Document Type: Image </li></ul>'
            .'<ul class="panel__attributes"><li><a href="viewAsset?id=42595&amp;index=2" title="View asset details"> 48-501 EDGE Black and white EMEA - U-Card </a></li><li> Product images - static </li><li> Asia Pacific, EMEA </li><li> Document Type: Image </li></ul>';
        $details = static fn (string $title, string $rights): string => self::LOGGED."<dl>\n<dt>Title</dt>\n<dd>{$title}</dd>\nShow more\n<dt>Usage Rights</dt>\n<dd>{$rights}</dd>\nShow more\n</dl>";
        $im = imagecreatetruecolor(400, 600);
        ob_start();
        imagejpeg($im);
        $jpeg = (string) ob_get_clean();

        $displayCalls = 0;
        Http::fake(function (Request $request) use (&$requests, &$displayCalls, $base, $results, $details, $jpeg, $internalFront, $placeholderFirst) {
            $url = $request->url();
            $requests[] = $request->method().' '.$url;

            return match (true) {
                $url === $base.'/action/viewLogin' => Http::response('<form><input type="hidden" name="CSRF" value="abc" /></form>'),
                $url === $base.'/action/login' => Http::response(self::LOGGED.'Home'),
                $url === $base.'/action/viewSearch' => Http::response(self::LOGGED.'<input type="hidden" name="CSRF" value="def" />'),
                $url === $base.'/action/search' => Http::response(self::LOGGED.$results),
                str_contains($url, 'viewAsset?id=24770') => Http::response($details('EDGE 48-501 Black Product EMEA - Front', $internalFront ? 'Internal Use Only' : 'Limited Use - Region-specific')),
                str_contains($url, 'viewAsset?id=42595') => Http::response($details('48-501 EDGE Black and white EMEA - U-Card', 'Unlimited Use')),
                str_contains($url, 'viewDownloadImage') => Http::response(self::LOGGED.'<form method="post" action="../action/downloadImage"><input type="hidden" name="CSRF" value="ghi" /><input type="hidden" name="asset.id" value="24770" /><input type="text" name="width" value="5184" /><input type="text" name="height" value="2920" /><input type="submit" name="b_downloadOriginal" value="Download original" /></form>'),
                $url === $base.'/action/downloadImage' => Http::response(self::LOGGED.'<img src="../servlet/display?file=abc123.jpg" />'),
                str_contains($url, 'servlet/display?file=abc123.jpg') => $placeholderFirst && $displayCalls++ === 0
                    ? Http::response(str_repeat('x', 20), 200, ['Content-Type' => 'image/jpeg'])
                    : Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
                default => Http::response('nieoczekiwane '.$url, 500),
            };
        });
    }
}
