<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Models\Role;
use App\Models\User;
use App\Services\Enrichment\ProductImageDownloader;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ręczne usunięcie zdjęcia z karty (23.09.2026): karty ARTRA miały zdjęcia innego wariantu z pierwszego pobierania.
 * Usunięte zdjęcie nie może wrócić przy następnym pobraniu — ani spod tego samego adresu, ani jako ten sam plik.
 */
final class ProductImageDeleteApiTest extends TestCase
{
    use RefreshDatabase;

    private const WRONG = 'https://artra.example.test/cdn/shop/files/ARDOR_330_619060_S3L_ESD.png?v=1764059517&width=1728';

    private const RIGHT = 'https://cdn.shopify.com/s/files/1/2/3/files/ARDOR_330_Air_619060_S1_PL_ESD.png';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_removes_photo_and_it_does_not_come_back(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);
        $product = $this->product();
        $downloader = new ProductImageDownloader;
        $wrong = $downloader->storeBytes($product, $this->png(0x102030), 'image/png', self::WRONG, 0);
        $right = $downloader->storeBytes($product, $this->png(0xA0B0C0), 'image/png', self::RIGHT, 1);
        $this->assertNotNull($wrong);
        $this->assertNotNull($right);

        $this->deleteJson("/api/products/{$product->id}/images/{$wrong->id}")
            ->assertOk()
            ->assertJsonCount(1, 'images')
            ->assertJsonPath('images.0.id', $right->id)
            ->assertJsonPath('images.0.is_primary', true);

        $this->assertNull(ProductImage::query()->find($wrong->id));
        $rejection = ProductImageRejection::query()->where('product_id', $product->id)->sole();
        $this->assertSame(ProductImageRejection::REASON_MANUAL, $rejection->reason);
        $this->assertSame($user->id, (int) $rejection->user_id);

        // ten sam adres Shopify spod drugiej domeny i ten sam plik pod zupełnie innym adresem
        $this->assertNull($downloader->storeBytes(
            $product,
            'inne bajty',
            'image/png',
            'https://cdn.shopify.com/s/files/1/9/9/files/ARDOR_330_619060_S3L_ESD.png',
            0,
        ));
        $this->assertNull($downloader->storeBytes($product, $this->png(0x102030), 'image/png', 'https://sklep.example/but.png', 0));
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());

        // odrzucenie dotyczy tylko tej karty
        $other = Product::query()->create(['sku' => 'ARDOR 330 619060 S3L ESD', 'name' => 'ARDOR 330 619060 S3L ESD', 'manufacturer' => 'ARTRA']);
        $this->assertNotNull($downloader->storeBytes($other, $this->png(0x102030), 'image/png', self::WRONG, 0));
    }

    public function test_photo_of_another_card_is_not_found(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product();
        $other = Product::query()->create(['sku' => 'X-1', 'name' => 'Inna karta', 'manufacturer' => 'ARTRA']);
        $image = (new ProductImageDownloader)->storeBytes($other, $this->png(0x102030), 'image/png', self::WRONG, 0);
        $this->assertNotNull($image);

        $this->deleteJson("/api/products/{$product->id}/images/{$image->id}")->assertNotFound();

        $this->assertNotNull(ProductImage::query()->find($image->id));
        $this->assertSame(0, ProductImageRejection::query()->count());
    }

    public function test_salesperson_cannot_remove_photos(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $product = $this->product();
        $image = (new ProductImageDownloader)->storeBytes($product, $this->png(0x102030), 'image/png', self::WRONG, 0);
        $this->assertNotNull($image);

        $this->deleteJson("/api/products/{$product->id}/images/{$image->id}")->assertForbidden();

        $this->assertNotNull(ProductImage::query()->find($image->id));
    }

    public function test_role_granted_the_permission_can_remove_photos(): void
    {
        // uprawnienie nadane w edycji ról — bez prawa usuwania produktów
        $role = Role::findOrCreate('opiekun_zdjec', 'web');
        $role->givePermissionTo(['products.view', 'products.images.delete']);
        Sanctum::actingAs(User::factory()->withRole('opiekun_zdjec')->create());
        $product = $this->product();
        $image = (new ProductImageDownloader)->storeBytes($product, $this->png(0x102030), 'image/png', self::WRONG, 0);
        $this->assertNotNull($image);

        $this->deleteJson("/api/products/{$product->id}/images/{$image->id}")->assertOk();
        // usuwanie całej karty dalej wymaga products.delete
        $this->deleteJson("/api/products/{$product->id}")->assertForbidden();

        $this->assertNull(ProductImage::query()->find($image->id));
    }

    private function product(): Product
    {
        return Product::query()->create([
            'sku' => 'ARDOR 330 Air 619060 S1 PL ESD',
            'name' => 'ARDOR 330 Air 619060 S1 PL ESD',
            'manufacturer' => 'ARTRA',
        ]);
    }

    private function png(int $color): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
