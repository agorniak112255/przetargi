<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RemoveProductImageBackgroundJob;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Catalog\ProductImageBackgroundRemover;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductWebFileCopier;
use App\Services\ProductImageThumbService;
use App\Services\ProductStoredFiles;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Usuwanie tła ze zdjęć kart przez rembg (atrapa Http::fake odtwarza POST /api/remove: multipart `file`, pole
 * `model`, odpowiedź PNG z kanałem alfa). Oryginał zostaje do przywrócenia, checksum się nie zmienia.
 */
final class ProductImageBackgroundTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{model: string|null, width: int, height: int}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_cut_replaces_the_file_keeps_the_original_and_the_checksum(): void
    {
        $image = $this->image(self::jpeg(2400, 1200));
        $original = (string) $image->path;
        $this->fakeService(self::cutout(1500, 750, 30));

        $status = app(ProductImageBackgroundRemover::class)->remove($image);

        $this->assertSame(ProductImage::BACKGROUND_DONE, $status);
        $image->refresh();
        $this->assertSame($original, $image->original_path);
        $this->assertNotSame($original, $image->path);
        $this->assertStringEndsWith('.png', (string) $image->path);
        $this->assertSame('sum-1', $image->checksum, 'checksum zostaje od oryginału');
        $this->assertNotNull($image->background_removed_at);
        Storage::disk('public')->assertExists($original);
        Storage::disk('public')->assertExists((string) $image->path);
        // model jawnie (domyślny bria-rmbg jest niekomercyjny), wejście zmniejszone do 1500 px dłuższego boku
        $this->assertSame([['model' => 'birefnet-general-lite', 'width' => 1500, 'height' => 750]], $this->sent);
        $this->assertStringContainsString('?v=', $image->thumbUrl(), 'nowy adres miniatury po wycięciu');
        $this->assertSame('done', $image->panelView()['background']['status'] ?? null);
    }

    public function test_thumbnail_cache_key_follows_the_cut_and_the_restore(): void
    {
        $image = $this->image(self::jpeg(400, 300));
        $thumbs = app(ProductImageThumbService::class);
        $before = $thumbs->jpeg($image);
        $this->fakeService(self::cutout(400, 300, 40));
        app(ProductImageBackgroundRemover::class)->remove($image);
        $image->refresh();

        $thumbs->jpeg($image);

        $keys = Storage::disk('local')->allFiles('product-thumbs');
        $this->assertCount(2, $keys, 'miniatura wycięcia ma własny klucz');
        $this->assertNotNull($before);

        app(ProductImageBackgroundRemover::class)->restore($image);
        $this->assertStringNotContainsString('?v=', $image->fresh()->thumbUrl());
    }

    public function test_result_without_a_product_or_without_a_cut_keeps_the_original(): void
    {
        foreach ([[0, 'model nie znalazł produktu na zdjęciu'], [100, 'model nie wyciął tła (cały kadr uznał za produkt)']] as [$coverage, $note]) {
            $image = $this->image(self::jpeg(300, 300), 'sum-'.$coverage);
            $path = (string) $image->path;
            $this->fakeService(self::cutout(300, 300, $coverage));

            $status = app(ProductImageBackgroundRemover::class)->remove($image);

            $this->assertSame(ProductImage::BACKGROUND_FAILED, $status);
            $image->refresh();
            $this->assertSame($path, $image->path);
            $this->assertNull($image->original_path);
            $this->assertSame($note, $image->background_note);
        }
    }

    public function test_already_transparent_png_is_skipped_but_an_opaque_rgba_packshot_is_cut(): void
    {
        $this->fakeService(self::cutout(300, 300, 40));
        $transparent = $this->image(self::cutout(300, 300, 40), 'sum-t');
        $opaqueRgba = $this->image(self::opaqueRgbaPng(300, 300), 'sum-o');

        $remover = app(ProductImageBackgroundRemover::class);

        $this->assertSame(ProductImage::BACKGROUND_SKIPPED, $remover->remove($transparent));
        $this->assertSame('zdjęcie już bez tła', $transparent->fresh()->background_note);
        $this->assertSame(ProductImage::BACKGROUND_DONE, $remover->remove($opaqueRgba), 'kanał alfa w nagłówku to jeszcze nie przezroczyste tło');
        $this->assertCount(1, $this->sent);
    }

    public function test_service_errors_keep_the_original_with_a_reason(): void
    {
        $image = $this->image(self::jpeg(300, 300));
        Http::fake(['127.0.0.1:7000/*' => Http::response('Internal Server Error', 500)]);

        $this->assertSame(ProductImage::BACKGROUND_FAILED, app(ProductImageBackgroundRemover::class)->remove($image));
        $this->assertSame('usługa usuwania tła odpowiedziała HTTP 500', $image->fresh()->background_note);
        $this->assertNull($image->fresh()->original_path);

        Http::fake(static fn () => throw new ConnectionException('Connection refused'));
        $this->assertSame(ProductImage::BACKGROUND_FAILED, app(ProductImageBackgroundRemover::class)->remove($image->fresh()));
        $this->assertStringStartsWith('usługa usuwania tła niedostępna', (string) $image->fresh()->background_note);
    }

    public function test_image_deleted_while_the_model_works_leaves_no_file(): void
    {
        $image = $this->image(self::jpeg(300, 300));
        Http::fake(function () use ($image) {
            // w trakcie ~20 s pracy modelu ktoś usuwa zdjęcie („×”)
            ProductImage::query()->whereKey($image->id)->delete();

            return Http::response(self::cutout(300, 300, 40), 200, ['Content-Type' => 'image/png']);
        });

        $this->assertSame('gone', app(ProductImageBackgroundRemover::class)->remove($image));
        $this->assertSame([(string) $image->path], Storage::disk('public')->allFiles('products/'.$image->product_id), 'świeże wycięcie skasowane');
    }

    public function test_restore_brings_back_the_original_and_removes_the_cut_file(): void
    {
        $image = $this->image(self::jpeg(300, 300));
        $original = (string) $image->path;
        $this->fakeService(self::cutout(300, 300, 40));
        app(ProductImageBackgroundRemover::class)->remove($image);
        $cut = (string) $image->fresh()->path;

        $this->assertTrue(app(ProductImageBackgroundRemover::class)->restore($image->fresh()));

        $image->refresh();
        $this->assertSame($original, $image->path);
        $this->assertNull($image->original_path);
        $this->assertNull($image->background_status);
        Storage::disk('public')->assertMissing($cut);
        $this->assertFalse(app(ProductImageBackgroundRemover::class)->restore($image), 'nic do przywrócenia');
    }

    public function test_api_queues_one_card_and_selected_cards_and_restores(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $first = $this->image(self::jpeg(300, 300), 'a');
        $second = $this->image(self::jpeg(300, 300), 'b', (int) $first->product_id);
        $other = $this->image(self::jpeg(300, 300), 'c');

        $this->postJson("/api/products/{$first->product_id}/images-background")
            ->assertOk()
            ->assertJsonPath('queued_images', 2)
            ->assertJsonPath('images.0.background.status', 'queued');
        Queue::assertPushed(RemoveProductImageBackgroundJob::class, 2);
        Queue::assertPushedOn('images', RemoveProductImageBackgroundJob::class);

        // zaznaczone na liście: zdjęcia w kolejce nie idą drugi raz
        $this->postJson('/api/products/images-background', ['product_ids' => [$first->product_id, $other->product_id]])
            ->assertOk()
            ->assertJsonPath('queued_images', 1)
            ->assertJsonPath('skipped_images', 2)
            ->assertJsonPath('products', 2);

        $this->fakeService(self::cutout(300, 300, 40));
        app(ProductImageBackgroundRemover::class)->remove($second->fresh());
        $this->postJson("/api/products/{$second->product_id}/images/{$second->id}/background-restore")
            ->assertOk()
            ->assertJsonPath('message', 'Przywrócono oryginalne zdjęcie.');
        $this->postJson("/api/products/{$other->product_id}/images/{$second->id}/background-restore")->assertNotFound();
        $this->postJson("/api/products/{$second->product_id}/images/{$second->id}/background-restore")->assertStatus(422);
    }

    public function test_permission_is_admin_only_by_default(): void
    {
        $this->assertContains('products.images.background', PermissionCatalog::ALL);
        foreach (PermissionCatalog::rolePermissions() as $role => $permissions) {
            if ($role !== 'admin') {
                $this->assertNotContains('products.images.background', $permissions, $role);
            }
        }
        $image = $this->image(self::jpeg(100, 100));
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->postJson("/api/products/{$image->product_id}/images-background")->assertForbidden();
        $this->postJson('/api/products/images-background', ['product_ids' => [$image->product_id]])->assertForbidden();
    }

    public function test_failed_job_does_not_leave_the_image_queued(): void
    {
        $image = $this->image(self::jpeg(100, 100));
        $image->forceFill(['background_status' => ProductImage::BACKGROUND_QUEUED])->save();

        (new RemoveProductImageBackgroundJob((int) $image->id))->failed(new RuntimeException('timeout'));

        $this->assertSame(ProductImage::BACKGROUND_FAILED, $image->fresh()->background_status);
        $this->assertSame('przerwane: timeout', $image->fresh()->background_note);
    }

    public function test_original_file_survives_media_cleanup_and_goes_with_the_card(): void
    {
        $image = $this->image(self::jpeg(300, 300));
        $this->fakeService(self::cutout(300, 300, 40));
        app(ProductImageBackgroundRemover::class)->remove($image);
        $image->refresh();

        $this->artisan('products:media-report', ['--apply' => true])->assertExitCode(0);

        Storage::disk('public')->assertExists((string) $image->original_path);
        Storage::disk('public')->assertExists((string) $image->path);
        $this->assertEqualsCanonicalizing(
            [(string) $image->path, (string) $image->original_path],
            app(ProductStoredFiles::class)->pathsOf([(int) $image->product_id]),
        );
    }

    public function test_copy_to_another_card_takes_the_original_and_a_resync_keeps_the_cut(): void
    {
        $image = $this->image(self::jpeg(300, 300));
        $originalBytes = (string) Storage::disk('public')->get((string) $image->path);
        $this->fakeService(self::cutout(300, 300, 40));
        app(ProductImageBackgroundRemover::class)->remove($image);
        $image->refresh();
        $cut = (string) $image->path;
        $from = Product::query()->findOrFail($image->product_id);
        $to = $this->product('KOPIA');

        $copy = app(ProductWebFileCopier::class)->copyImage($from, $image, $to);

        $this->assertNotNull($copy);
        $this->assertSame($originalBytes, Storage::disk('public')->get((string) $copy->path), 'kopia z oryginału');
        $this->assertNull($copy->original_path);

        // ponowne pobranie tego samego pliku (synchronizacja, wzbogacanie): ten sam wiersz, wycięcie zostaje
        $image->forceFill(['checksum' => hash('sha256', $originalBytes)])->save();
        $again = app(ProductImageDownloader::class)->storeBytes($from, $originalBytes, 'image/jpeg', 'https://dostawca.example/a.jpg', 0);
        $this->assertSame($image->id, $again?->id);
        $this->assertSame($cut, $image->fresh()->path);
    }

    // ---- pomocnicze ----

    private function product(string $sku): Product
    {
        return Product::query()->create(['sku' => $sku, 'name' => 'Karta '.$sku, 'manufacturer' => 'Test']);
    }

    private function image(string $bytes, string $checksum = 'sum-1', ?int $productId = null): ProductImage
    {
        $productId ??= (int) $this->product('P-'.$checksum)->id;
        $extension = str_starts_with($bytes, "\x89PNG") ? 'png' : 'jpg';
        $path = 'products/'.$productId.'/'.$checksum.'.'.$extension;
        Storage::disk('public')->put($path, $bytes);

        return ProductImage::query()->create([
            'product_id' => $productId,
            'path' => $path,
            'source_url' => 'https://dostawca.example/'.$checksum.'.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'checksum' => $checksum,
        ]);
    }

    /** PNG, który oddaje atrapa usługi (fakeService rejestruje atrapę raz — kolejne Http::fake nie wygrałyby z pierwszą). */
    private string $servicePng = '';

    private bool $serviceFaked = false;

    private function fakeService(string $png): void
    {
        $this->servicePng = $png;
        if ($this->serviceFaked) {
            return;
        }
        $this->serviceFaked = true;
        Http::fake(function (Request $request) {
            $model = null;
            $size = [0, 0];
            foreach ($request->data() as $part) {
                if (($part['name'] ?? null) === 'model') {
                    $model = (string) $part['contents'];
                }
                if (($part['name'] ?? null) === 'file') {
                    $info = getimagesizefromstring((string) $part['contents']);
                    $size = [(int) $info[0], (int) $info[1]];
                }
            }
            $this->sent[] = ['model' => $model, 'width' => $size[0], 'height' => $size[1]];

            return Http::response($this->servicePng, 200, ['Content-Type' => 'image/png']);
        });
    }

    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 250, 250, 250));
        imagefilledrectangle($image, intdiv($width, 4), intdiv($height, 4), intdiv($width * 3, 4), intdiv($height * 3, 4), 0x333333);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** PNG z przezroczystym tłem i kryjącym prostokątem na ok. $percent% powierzchni (0 = pusty, 100 = cały kadr). */
    private static function cutout(int $width, int $height, int $percent): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        if ($percent >= 100) {
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, 0x445566);
        } elseif ($percent > 0) {
            $side = sqrt($percent / 100);
            $w = (int) ($width * $side);
            $h = (int) ($height * $side);
            $x = intdiv($width - $w, 2);
            $y = intdiv($height - $h, 2);
            imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, 0x445566);
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** PNG z kanałem alfa w nagłówku, ale w pełni kryjący: biały packshot. */
    private static function opaqueRgbaPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 0));
        imagefilledrectangle($image, 100, 100, 200, 200, 0x222222);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
