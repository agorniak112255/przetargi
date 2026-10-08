<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Services\Enrichment\ProductWebFileCopier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pliki lidera modelu na karcie członka bez sieci (etap 2 opisów z cenników).
 */
final class ProductWebFileCopierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
    }

    public function test_copies_image_file_under_member_path_with_same_checksum_and_source(): void
    {
        $leader = $this->product('AF060001');
        $member = $this->product('AF060002');
        $image = $this->image($leader, 'JPEG-BYTES-1', 'https://www.coba.com/x/orthomat-grey.jpg');

        $copy = (new ProductWebFileCopier)->copyImage($leader, $image, $member);

        $this->assertNotNull($copy);
        $this->assertSame((int) $member->id, (int) $copy->product_id);
        $this->assertStringStartsWith('products/'.$member->id.'/', (string) $copy->path);
        $this->assertStringEndsWith('.jpg', (string) $copy->path);
        $this->assertNotSame($image->path, $copy->path);
        Storage::disk('public')->assertExists($copy->path);
        Storage::disk('public')->assertExists($image->path);
        $this->assertSame('JPEG-BYTES-1', Storage::disk('public')->get($copy->path));
        $this->assertSame($image->checksum, $copy->checksum);
        $this->assertSame($image->source_url, $copy->source_url);
        $this->assertTrue((bool) $copy->is_primary);
        $this->assertSame(0, (int) $copy->sort_order);
        $this->assertNull($copy->b2b_account_id);

        // ta sama suma już na karcie docelowej → istniejący wiersz, bez drugiego pliku
        $again = (new ProductWebFileCopier)->copyImage($leader, $image, $member);
        $this->assertSame((int) $copy->id, (int) $again?->id);
        $this->assertSame(1, ProductImage::query()->where('product_id', $member->id)->count());
        $this->assertCount(1, Storage::disk('public')->files('products/'.$member->id));
    }

    public function test_image_is_not_copied_when_foreign_missing_or_rejected(): void
    {
        $leader = $this->product('AF060001');
        $member = $this->product('AF060002');
        $other = $this->product('DP0106');
        $copier = new ProductWebFileCopier;

        $foreign = $this->image($other, 'OTHER', 'https://www.coba.com/x/other.jpg');
        $this->assertNull($copier->copyImage($leader, $foreign, $member), 'zdjęcie nie należy do lidera');

        $missing = ProductImage::query()->create([
            'product_id' => $leader->id, 'path' => 'products/'.$leader->id.'/missing.jpg',
            'source_url' => 'https://www.coba.com/x/missing.jpg', 'is_primary' => true, 'sort_order' => 0, 'checksum' => hash('sha256', 'missing'),
        ]);
        $this->assertNull($copier->copyImage($leader, $missing, $member), 'pliku nie ma na dysku');

        $remote = ProductImage::query()->create([
            'product_id' => $leader->id, 'path' => 'https://cdn.example/remote.jpg', 'source_url' => 'https://cdn.example/remote.jpg',
            'is_primary' => false, 'sort_order' => 1, 'checksum' => null,
        ]);
        $this->assertNull($copier->copyImage($leader, $remote, $member), 'adres zdalny zamiast pliku');

        $rejected = $this->image($leader, 'REJECTED-BYTES', 'https://www.coba.com/x/rejected.jpg');
        ProductImageRejection::query()->create([
            'product_id' => $member->id, 'file_key_hash' => hash('sha256', 'inny-adres'), 'file_key' => 'inny-adres',
            'checksum' => $rejected->checksum, 'source_url' => 'https://inny.example/a.jpg', 'reason' => 'test',
        ]);
        $this->assertNull($copier->copyImage($leader, $rejected, $member), 'karta docelowa usunęła ten plik świadomie');
        $this->assertSame(0, ProductImage::query()->where('product_id', $member->id)->count());
        $this->assertNull($copier->copyImage($leader, $rejected, $leader), 'ta sama karta');
    }

    public function test_copies_documents_with_text_kind_and_title_and_skips_missing_or_foreign(): void
    {
        $leader = $this->product('AF060001');
        $member = $this->product('AF060002');
        $other = $this->product('DP0106');
        $certificate = $this->document($leader, 'PDF-CERT', 'https://www.coba.com/doc/cert.pdf', 'Deklaracja zgodności UE.pdf', ProductDocument::KIND_CERTIFICATE, 'EN 14041 declaration', 0);
        $datasheet = $this->document($leader, 'PDF-DATA', 'https://www.coba.com/doc/data.pdf', 'Karta produktu.pdf', ProductDocument::KIND_DATASHEET, null, 1);
        $missing = ProductDocument::query()->create([
            'product_id' => $leader->id, 'path' => 'products/'.$leader->id.'/docs/missing.pdf', 'source_url' => 'https://www.coba.com/doc/missing.pdf',
            'title' => 'Brak.pdf', 'kind' => ProductDocument::KIND_OTHER, 'sort_order' => 2, 'checksum' => hash('sha256', 'missing'), 'size_bytes' => 7,
        ]);
        $foreign = $this->document($other, 'PDF-OTHER', 'https://www.coba.com/doc/other.pdf', 'Cudzy.pdf', ProductDocument::KIND_OTHER, null, 0);

        $copies = (new ProductWebFileCopier)->copyDocuments($leader, [(int) $certificate->id, (int) $datasheet->id, (int) $missing->id, (int) $foreign->id], $member);

        $this->assertCount(2, $copies);
        $this->assertSame(['Deklaracja zgodności UE.pdf', 'Karta produktu.pdf'], array_map(static fn (ProductDocument $d): string => (string) $d->title, $copies));
        $this->assertSame([ProductDocument::KIND_CERTIFICATE, ProductDocument::KIND_DATASHEET], array_map(static fn (ProductDocument $d): string => (string) $d->kind, $copies));
        $this->assertSame([0, 1], array_map(static fn (ProductDocument $d): int => (int) $d->sort_order, $copies));
        $this->assertSame('EN 14041 declaration', $copies[0]->text);
        $this->assertNull($copies[1]->text);
        foreach ($copies as $i => $copy) {
            $this->assertSame((int) $member->id, (int) $copy->product_id);
            $this->assertStringStartsWith('products/'.$member->id.'/docs/', (string) $copy->path);
            Storage::disk('public')->assertExists($copy->path);
        }
        $this->assertSame('PDF-CERT', Storage::disk('public')->get($copies[0]->path));
        $this->assertSame($certificate->checksum, $copies[0]->checksum);
        $this->assertSame($certificate->source_url, $copies[0]->source_url);
        $this->assertSame(8, (int) $copies[0]->size_bytes);

        // powtórka: te same wiersze (suma albo adres już na karcie), bez nowych plików
        $again = (new ProductWebFileCopier)->copyDocuments($leader, [(int) $certificate->id, (int) $datasheet->id], $member);
        $this->assertSame([(int) $copies[0]->id, (int) $copies[1]->id], array_map(static fn (ProductDocument $d): int => (int) $d->id, $again));
        $this->assertSame(2, ProductDocument::query()->where('product_id', $member->id)->count());
        $this->assertSame([], (new ProductWebFileCopier)->copyDocuments($leader, [], $member));
    }

    /**
     * Etap 3, runda 3: kopia nie omija bramek pobierania — znana zaślepka po sumie (config/image_blocklist.php; „404 nginx”
     * na kartach HR Matting) i grafika witryny po adresie (ImageUrlBlocklist z regułami profilu Coby) nie są kopiowane.
     */
    public function test_placeholder_checksum_and_blocklisted_url_are_not_copied(): void
    {
        $leader = $this->product('HR060001');
        $member = $this->product('HR060002');
        $copier = new ProductWebFileCopier;

        $placeholder = $this->image($leader, '<html>404 Not Found nginx</html>', 'https://www.coba.com/wp-content/uploads/hr-matting-grey.jpg');
        config()->set('image_blocklist.checksums', [(string) $placeholder->checksum]);
        $this->assertNull($copier->copyImage($leader, $placeholder, $member), 'znana zaślepka');

        $banner = $this->image($leader, 'BANNER', 'https://www.coba.com/wp-content/uploads/StandUpforHealth-PL.jpg');
        $this->assertNull($copier->copyImage($leader, $banner, $member), 'grafika witryny z listy profilu Coby');

        $photo = $this->image($leader, 'PHOTO', 'https://www.coba.com/wp-content/uploads/hr-matting-grey-2.jpg');
        $this->assertNotNull($copier->copyImage($leader, $photo, $member), 'zwykłe zdjęcie dalej kopiowane');
        $this->assertSame(1, ProductImage::query()->where('product_id', $member->id)->count());
    }

    private function product(string $sku): Product
    {
        return Product::query()->create(['sku' => $sku, 'name' => 'Orthomat Standard Szary '.$sku, 'manufacturer' => 'Coba']);
    }

    private function image(Product $product, string $bytes, string $sourceUrl): ProductImage
    {
        $path = 'products/'.$product->id.'/'.strtolower(substr(md5($bytes), 0, 16)).'.jpg';
        Storage::disk('public')->put($path, $bytes);
        $sort = ProductImage::query()->where('product_id', $product->id)->count();

        return ProductImage::query()->create([
            'product_id' => $product->id, 'path' => $path, 'source_url' => $sourceUrl,
            'is_primary' => $sort === 0, 'sort_order' => $sort, 'checksum' => hash('sha256', $bytes),
        ]);
    }

    private function document(Product $product, string $bytes, string $sourceUrl, string $title, string $kind, ?string $text, int $sort): ProductDocument
    {
        $path = 'products/'.$product->id.'/docs/'.strtolower(substr(md5($bytes), 0, 16)).'.pdf';
        Storage::disk('public')->put($path, $bytes);

        return ProductDocument::query()->create([
            'product_id' => $product->id, 'path' => $path, 'source_url' => $sourceUrl, 'title' => $title, 'text' => $text,
            'kind' => $kind, 'sort_order' => $sort, 'checksum' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes),
        ]);
    }
}
