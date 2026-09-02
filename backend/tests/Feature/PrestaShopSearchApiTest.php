<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Presta\PrestaCatalogGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePrestaCatalogGateway;
use Tests\TestCase;

final class PrestaShopSearchApiTest extends TestCase
{
    use RefreshDatabase;

    private FakePrestaCatalogGateway $presta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->presta = new FakePrestaCatalogGateway;
        $this->app->instance(PrestaCatalogGateway::class, $this->presta);
    }

    public function test_search_does_not_write_description(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct(['sku' => '34700018', 'manufacturer' => 'MAPA', 'name' => 'TEMP-ICE 700']);
        $this->presta->rows = [$this->card(10, '34700018', 'TEMP-ICE 700', 'MAPA')];

        $this->postJson("/api/products/{$product->id}/presta-search")
            ->assertOk()
            ->assertJsonPath('auto.presta_id', 10)
            ->assertJsonPath('candidates.0.action', 'auto');

        $this->assertNull($product->fresh()->description);
        $this->assertSame(10.0, (float) $product->fresh()->catalog_price_net);
    }

    public function test_search_falls_back_to_manufacturer_and_name(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct([
            'sku' => 'INNY-SKU',
            'manufacturer' => 'AJ Group',
            'name' => 'TEMP-ICE 700',
        ]);
        $this->presta->rows = [
            $this->card(10, '34700018', 'Rękawice TEMP-ICE 700', 'AJ Group'),
            $this->card(11, '999', 'Rękawice lateksowe niebieskie', 'AJ Group'),
            $this->card(12, '34700018', 'TEMP-ICE 700', 'MAPA'),
        ];

        $ids = $this->postJson("/api/products/{$product->id}/presta-search")
            ->assertOk()
            ->assertJsonPath('candidates.0.presta_id', 10)
            ->assertJsonPath('candidates.0.manufacturer', 'AJ Group')
            ->json('candidates');

        $this->assertNotContains(11, collect($ids)->pluck('presta_id')->all());
        $this->assertNotContains(12, collect($ids)->pluck('presta_id')->all());
    }

    public function test_apply_copies_description_not_price(): void
    {
        Queue::fake();
        Http::fake();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct([
            'sku' => '34700018',
            'manufacturer' => 'MAPA',
            'name' => 'TEMP-ICE 700',
            'catalog_price_net' => 4.9,
            'purchase_price' => 3.1,
        ]);
        $this->presta->rows = [$this->card(10, '34700018', 'TEMP-ICE 700', 'MAPA')];

        $this->postJson("/api/products/{$product->id}/presta-apply", [
            'presta_id' => 10,
            'method' => 'reference',
            'score' => 96,
        ])
            ->assertOk()
            ->assertJsonPath('match.presta_id', 10);

        $fresh = $product->fresh();
        $this->assertStringContainsString('Pełny opis', (string) $fresh->description);
        $this->assertSame('4.90', (string) $fresh->catalog_price_net);
        $this->assertSame('3.10', (string) $fresh->purchase_price);
        $this->assertTrue((bool) ($fresh->enrichment_payload['from_presta'] ?? false));
    }

    public function test_apply_downloads_shop_image(): void
    {
        Queue::fake();
        Storage::fake('public');
        $jpeg = $this->sampleJpeg();
        $this->assertNotSame('', $jpeg);
        Http::fake([
            '*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct(['sku' => '34258328', 'manufacturer' => 'MAPA', 'name' => 'ALTO 258']);
        $this->presta->rows = [$this->card(4797, '34258328', 'ALTO 258', 'MAPA')];
        $this->presta->images = ['https://www.supon.rzeszow.pl/img/p/9/3/2/9/9329.jpg'];

        $this->postJson("/api/products/{$product->id}/presta-apply", [
            'presta_id' => 4797,
            'method' => 'reference',
            'score' => 96,
        ])
            ->assertOk()
            ->assertJsonPath('images_count', 1);

        $this->assertSame(1, $product->fresh()->images()->count());
        $this->assertNotSame('remote', (string) $product->fresh()->images()->first()?->path);
    }

    public function test_apply_adds_photos_when_description_already_exists(): void
    {
        Queue::fake();
        Storage::fake('public');
        Http::fake([
            '*' => Http::response('not-an-image', 404),
        ]);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->makeProduct([
            'sku' => '34258328',
            'manufacturer' => 'MAPA',
            'name' => 'ALTO 258',
            'description' => 'Istniejący opis produktu z cennika, wystarczająco długi.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        $this->presta->rows = [$this->card(4797, '34258328', 'ALTO 258', 'MAPA')];
        $this->presta->images = ['https://www.supon.rzeszow.pl/img/p/9/3/2/9/9329.jpg'];

        $this->postJson("/api/products/{$product->id}/presta-apply", [
            'presta_id' => 4797,
            'force' => false,
        ])->assertOk()->assertJsonPath('images_count', 1);

        $fresh = $product->fresh();
        $this->assertStringContainsString('Istniejący opis', (string) $fresh->description);
        $this->assertSame('remote', (string) $fresh->images()->first()?->path);
        $this->assertStringContainsString('/img/p/9/3/2/9/9329.jpg', (string) $fresh->images()->first()?->url());
    }

    public function test_apply_batch_imports_best_card_per_product(): void
    {
        Queue::fake();
        Http::fake();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $a = $this->makeProduct(['sku' => 'URGENT-1000', 'name' => '1000', 'manufacturer' => 'URGENT']);
        $b = $this->makeProduct(['sku' => '34700018', 'name' => 'TEMP-ICE 700', 'manufacturer' => 'MAPA']);
        $this->presta->rows = [
            $this->card(10, 'URGENT-1000', 'Rękawice 1000', 'URGENT'),
            $this->card(11, '34700018', 'TEMP-ICE 700', 'MAPA'),
        ];

        $this->postJson('/api/products/presta-apply-batch', [
            'force' => false,
            'items' => [
                ['product_id' => $a->id, 'presta_id' => 10, 'method' => 'reference', 'score' => 96],
                ['product_id' => $b->id, 'presta_id' => 11, 'method' => 'reference', 'score' => 96],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('applied', 2)
            ->assertJsonPath('failed', 0);

        $this->assertStringContainsString('Pełny opis', (string) $a->fresh()->description);
        $this->assertStringContainsString('Pełny opis', (string) $b->fresh()->description);
    }

    public function test_settings_hide_password(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/presta-settings', [
            'enabled' => true,
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'supon_presta',
            'username' => 'rag_readonly',
            'password' => 'secret-pass',
            'prefix' => 'ps_',
            'id_lang' => 1,
            'shop_url' => 'https://supon.rzeszow.pl',
        ])->assertOk()->assertJsonMissing(['password']);

        $this->getJson('/api/admin/presta-settings')
            ->assertOk()
            ->assertJsonPath('has_password', true)
            ->assertJsonMissing(['password' => 'secret-pass']);
    }

    public function test_settings_reject_invalid_webservice_key(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/admin/presta-settings', [
            'webservice_key' => 'za-krotki',
        ])->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProduct(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => 'SKU-1',
            'name' => 'Produkt',
            'manufacturer' => 'X',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function card(int $id, string $reference, string $name, string $manufacturer): array
    {
        return [
            'id_product' => $id,
            'reference' => $reference,
            'ean13' => '',
            'name' => $name,
            'link_rewrite' => 'temp-ice-700',
            'description_short' => 'Krótki opis rękawic TEMP-ICE 700 do pracy w zimnie.',
            'description' => '<p>Pełny opis rękawic TEMP-ICE 700. EN 388 EN 511.</p>',
            'manufacturer' => $manufacturer,
            'features' => 'EN 388; EN 511',
            'url' => 'https://supon.rzeszow.pl/'.$id.'-temp-ice-700.html',
        ];
    }

    private function sampleJpeg(): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return '';
        }
        $img = imagecreatetruecolor(220, 220);
        $bg = imagecolorallocate($img, 40, 120, 200);
        imagefill($img, 0, 0, $bg);
        ob_start();
        imagejpeg($img, null, 85);
        imagedestroy($img);
        $bytes = ob_get_clean();

        return is_string($bytes) ? $bytes : '';
    }
}
