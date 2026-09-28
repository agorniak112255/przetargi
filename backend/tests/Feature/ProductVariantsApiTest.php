<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportProductToPrestaJob;
use App\Models\B2bAccount;
use App\Models\Product;
use App\Models\ProductSourcePrice;
use App\Models\ProductVariant;
use App\Models\ProductVariantPriceHistory;
use App\Models\User;
use App\Services\Presta\PrestaExportGateway;
use App\Services\Presta\PrestaProductExportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePrestaExportGateway;
use Tests\TestCase;

final class ProductVariantsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::forget('nbp.table_a.rates');
        Http::fake([
            'api.nbp.pl/*' => Http::response([[
                'effectiveDate' => '2026-08-27',
                'rates' => [['code' => 'EUR', 'mid' => 4.0]],
            ]]),
        ]);
    }

    public function test_card_without_variants_has_null_variants(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('PLAIN-1', 10, 5);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('variants', null);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.variants_count', 0)
            ->assertJsonPath('data.0.variants_min_price', null)
            ->assertJsonPath('data.0.variants_currency', null);
    }

    public function test_card_shows_variants_with_range_dimensions_and_last_change(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('BB014', 0, 0);
        $large = $this->variant($product, '1419', '20 x 29,6 cm \\ FS - folia fotoluminescencyjna', ['Format' => '20 x 29,6 cm', 'Podłoże' => 'FS - folia fotoluminescencyjna'], 24.10, 2);
        $small = $this->variant($product, '7109', '10 x 14,8 cm \\ FN - folia samoprzylepna', ['Format' => '10 x 14,8 cm', 'Podłoże' => 'FN - folia samoprzylepna'], 0.97, 0);
        $noPrice = $this->variant($product, '7110', '10 x 14,8 cm \\ PN - płyta', ['Format' => '10 x 14,8 cm', 'Podłoże' => 'PN - płyta'], null, 1);
        // Wycofana, tańsza — widoczna na liście, ale nie liczy się do „od”.
        $removed = $this->variant($product, '7111', 'Wersja bez atrybutów', [], 0.10, 3, removed: true);

        $this->history($small, 0.95, '2026-09-01 02:00:00');
        $this->history($small, 0.97, '2026-09-10 02:00:00', syncRunId: null);
        // Ta sama cena ponownie — nie jest nowszą zmianą.
        $this->history($small, 0.97, '2026-09-12 02:00:00');
        $this->history($large, 24.10, '2026-09-10 02:00:00');

        $response = $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('variants.kind', 'version')
            ->assertJsonPath('variants.count', 4)
            ->assertJsonPath('variants.active_count', 3)
            ->assertJsonPath('variants.min_price', '0.97')
            ->assertJsonPath('variants.max_price', '24.10')
            ->assertJsonPath('variants.currency', 'PLN')
            ->assertJsonPath('variants.source_label', 'Anro B2B')
            ->assertJsonPath('variants.dimensions', ['Format', 'Podłoże'])
            ->assertJsonCount(4, 'variants.items')
            ->assertJsonPath('variants.items.0.id', $small->id)
            ->assertJsonPath('variants.items.0.remote_id', '7109')
            ->assertJsonPath('variants.items.0.attributes.Podłoże', 'FN - folia samoprzylepna')
            ->assertJsonPath('variants.items.0.purchase_price', '0.97')
            ->assertJsonPath('variants.items.0.list_price_net', null)
            ->assertJsonPath('variants.items.0.currency', 'PLN')
            ->assertJsonPath('variants.items.0.vat_rate', 23)
            ->assertJsonPath('variants.items.0.unit', 'szt.')
            ->assertJsonPath('variants.items.0.sort_order', 0)
            ->assertJsonPath('variants.items.0.price_checked_at', '2026-09-12T02:00:00.000000Z')
            ->assertJsonPath('variants.items.0.removed_at', null)
            ->assertJsonPath('variants.items.1.id', $noPrice->id)
            ->assertJsonPath('variants.items.1.purchase_price', null)
            ->assertJsonPath('variants.items.1.last_price_change', null)
            ->assertJsonPath('variants.items.2.id', $large->id)
            ->assertJsonPath('variants.items.2.last_price_change', null)
            ->assertJsonPath('variants.items.3.id', $removed->id)
            ->assertJsonPath('variants.items.3.removed_at', '2026-09-12T02:00:00.000000Z');

        $this->assertEquals([
            'purchase_old' => '0.95',
            'purchase_new' => '0.97',
            'pct' => 2.11,
            'at' => '2026-09-10T02:00:00.000000Z',
            'b2b_sync_run_id' => null,
        ], $response->json('variants.items.0.last_price_change'));
        // Puste atrybuty zostają obiektem JSON, nie listą.
        $this->assertStringContainsString('"attributes":{}', (string) $response->getContent());
    }

    public function test_mixed_currencies_do_not_produce_price_range(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('MIX-1', 0, 0);
        $this->variant($product, 'm1', 'A', [], 10.0, 0);
        $this->variant($product, 'm2', 'B', [], 3.0, 1, currency: 'EUR');

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('variants.active_count', 2)
            ->assertJsonPath('variants.min_price', null)
            ->assertJsonPath('variants.max_price', null)
            ->assertJsonPath('variants.currency', null);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.variants_count', 2)
            ->assertJsonPath('data.0.variants_min_price', null)
            ->assertJsonPath('data.0.variants_currency', null);
    }

    public function test_list_returns_variant_summary_without_query_per_product(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        for ($i = 1; $i <= 12; $i++) {
            $product = $this->product(sprintf('SIGN-%02d', $i), 0, 0);
            $this->variant($product, 'v'.$i.'a', 'A', [], 1.5 + $i, 0);
            $this->variant($product, 'v'.$i.'b', 'B', [], 0.5 + $i, 1);
            $this->variant($product, 'v'.$i.'c', 'C', [], 0.01, 2, removed: true);
        }
        $this->product('ZZ-PLAIN', 10, 5);

        /** @var list<string> $log */
        $log = [];
        $countQueries = function () use (&$log): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/products?per_page=50&sort=sku')->assertOk();
            $log = array_map(static fn (array $q): string => $q['query'], DB::getQueryLog());
            DB::disableQueryLog();

            return count($log);
        };

        // Pierwsze żądanie rozgrzewa pamięć podręczną kursów NBP — nie liczymy go.
        $countQueries();
        $withVariants = $countQueries();
        $withVariantsLog = $log;
        ProductVariant::query()->delete();
        $withoutVariants = $countQueries();

        $this->assertSame($withoutVariants, $withVariants, implode("\n", $withVariantsLog));
        $variantQueries = array_filter($withVariantsLog, static fn (string $q): bool => str_contains($q, 'product_variants'));
        $this->assertCount(1, $variantQueries, implode("\n", $withVariantsLog));

        $this->product('SIGN-00', 0, 0);
        $first = ProductVariant::query()->create([
            'product_id' => Product::query()->where('sku', 'SIGN-00')->value('id'),
            'source' => 'b2b:anro', 'remote_id' => 'x1', 'label' => 'X', 'purchase_price' => 4.2, 'currency' => 'PLN',
        ]);
        $this->assertNotNull($first->id);

        $this->getJson('/api/products?per_page=50&sort=sku')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'SIGN-00')
            ->assertJsonPath('data.0.variants_count', 1)
            ->assertJsonPath('data.0.variants_min_price', '4.20')
            ->assertJsonPath('data.0.variants_currency', 'PLN')
            ->assertJsonPath('data.1.variants_count', 0);
    }

    public function test_size_rows_are_listed_as_sizes_with_code_availability_and_account_label(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($admin);
        $account = B2bAccount::query()->create([
            'username' => 'mascot-konto', 'password' => 'sekret', 'sites' => ['b2b.mascot.example'], 'connector' => 'mascot',
            'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $product = $this->product('20079-230', 120, 89.90);
        $small = $this->sizeRow($product, $account, 'm-s', '20079-230-S', 'S', 89.90, 129.00, 'dostępny', 0);
        $large = $this->sizeRow($product, $account, 'm-4xl', '20079-230-4XL', '4XL', 99.90, null, null, 1);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('variants.kind', 'size')
            ->assertJsonPath('variants.count', 2)
            ->assertJsonPath('variants.active_count', 2)
            ->assertJsonPath('variants.min_price', '89.90')
            ->assertJsonPath('variants.max_price', '99.90')
            ->assertJsonPath('variants.source_label', 'B2B Mascot')
            ->assertJsonPath('variants.dimensions', [])
            ->assertJsonPath('variants.items.0.id', $small->id)
            ->assertJsonPath('variants.items.0.sku', '20079-230-S')
            ->assertJsonPath('variants.items.0.label', 'S')
            ->assertJsonPath('variants.items.0.availability', 'dostępny')
            ->assertJsonPath('variants.items.0.list_price_net', '129.00')
            ->assertJsonPath('variants.items.1.id', $large->id)
            ->assertJsonPath('variants.items.1.sku', '20079-230-4XL')
            ->assertJsonPath('variants.items.1.availability', null)
            ->assertJsonPath('variants.items.1.list_price_net', null);

        // Rozmiary nie zastępują ceny karty na liście („od X · n wersji” tylko dla wersji).
        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.sku', '20079-230')
            ->assertJsonPath('data.0.variants_count', 0)
            ->assertJsonPath('data.0.variants_min_price', null)
            ->assertJsonPath('data.0.variants_currency', null);

        // Konto skasowane — etykieta jak w porównaniu cen karty, nie klucz łącznika „#id”.
        ProductVariant::query()->update(['source' => 'b2b:999999']);
        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('variants.source_label', 'B2B (usunięte konto)');
    }

    public function test_card_with_versions_and_sizes_lists_only_versions(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($admin);
        $account = B2bAccount::query()->create([
            'username' => 'mascot-konto', 'password' => 'sekret', 'sites' => ['b2b.mascot.example'], 'connector' => 'mascot',
            'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $product = $this->product('BB014', 0, 0);
        $version = $this->variant($product, '7109', 'A', ['Format' => '10 x 14,8 cm'], 0.97, 0);
        $this->sizeRow($product, $account, 'm-s', 'BB014-S', 'S', 0.50, null, null, 1);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('variants.kind', 'version')
            ->assertJsonPath('variants.count', 1)
            ->assertJsonPath('variants.min_price', '0.97')
            ->assertJsonPath('variants.source_label', 'Anro B2B')
            ->assertJsonPath('variants.dimensions', ['Format'])
            ->assertJsonCount(1, 'variants.items')
            ->assertJsonPath('variants.items.0.id', $version->id)
            ->assertJsonPath('variants.items.0.sku', null);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.variants_count', 1)
            ->assertJsonPath('data.0.variants_min_price', '0.97');
    }

    public function test_variant_price_history_is_newest_first_with_previous_price(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('BB014', 0, 0);
        $variant = $this->variant($product, '7109', 'A', [], 0.97, 0);
        $this->history($variant, 0.95, '2026-09-01 02:00:00');
        $this->history($variant, 0.97, '2026-09-10 02:00:00');

        $this->getJson('/api/products/'.$product->id.'/variants/'.$variant->id.'/price-history')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [[
                'id', 'purchase_price', 'list_price_net', 'currency', 'source', 'source_label', 'b2b_sync_run_id',
                'created_at', 'purchase_old', 'purchase_pct',
            ]]])
            ->assertJsonPath('data.0.purchase_price', '0.97')
            ->assertJsonPath('data.0.purchase_old', '0.95')
            ->assertJsonPath('data.0.purchase_pct', 2.11)
            ->assertJsonPath('data.0.source_label', 'Anro B2B')
            ->assertJsonPath('data.0.currency', 'PLN')
            ->assertJsonPath('data.0.created_at', '2026-09-10T02:00:00.000000Z')
            ->assertJsonPath('data.1.purchase_old', null)
            ->assertJsonPath('data.1.purchase_pct', null);
    }

    public function test_variant_history_requires_matching_product_and_permission(): void
    {
        $product = $this->product('BB014', 0, 0);
        $other = $this->product('BB015', 0, 0);
        $variant = $this->variant($product, '7109', 'A', [], 0.97, 0);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->getJson('/api/products/'.$other->id.'/variants/'.$variant->id.'/price-history')->assertNotFound();
        $this->getJson('/api/products/'.$product->id.'/variants/999999/price-history')->assertNotFound();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/products/'.$product->id.'/variants/'.$variant->id.'/price-history')->assertForbidden();
    }

    public function test_presta_export_is_blocked_for_card_with_active_variants(): void
    {
        $presta = new FakePrestaExportGateway;
        $this->app->instance(PrestaExportGateway::class, $presta);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->product('BB014', 0, 0);
        $this->variant($product, '7109', 'A', [], 0.97, 0);

        $this->postJson('/api/products/'.$product->id.'/presta-export')
            ->assertStatus(422)
            ->assertJsonPath('message', PrestaProductExportService::VARIANTS_BLOCKED_MESSAGE);

        $this->postJson('/api/products/presta-export', ['product_ids' => [$product->id]])
            ->assertOk()
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('exported', 0)
            ->assertJsonPath('errors.0', 'BB014: '.PrestaProductExportService::VARIANTS_BLOCKED_MESSAGE);

        (new ExportProductToPrestaJob((int) $product->id))->handle(app(PrestaProductExportService::class));

        $this->assertSame([], $presta->created);
        $this->assertSame([], $presta->updated);

        // Tylko wycofane wersje — karta znów zwykła.
        ProductVariant::query()->update(['removed_at' => now()]);
        $plain = $this->product('PLAIN-9', 10, 5);
        $this->postJson('/api/products/'.$plain->id.'/presta-export')->assertOk()->assertJsonPath('action', 'created');
        $this->assertCount(1, $presta->created);
        $this->assertNull(app(PrestaProductExportService::class)->blockedReason($product));
    }

    private function product(string $sku, float $catalog, float $purchase): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Znak '.$sku,
            'manufacturer' => 'X',
            'catalog_price_net' => $catalog,
            'purchase_price' => $purchase,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function variant(
        Product $product,
        string $remoteId,
        string $label,
        array $attributes,
        ?float $price,
        int $sortOrder,
        bool $removed = false,
        string $currency = 'PLN',
    ): ProductVariant {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'source' => 'b2b:anro',
            'remote_id' => $remoteId,
            'label' => $label,
            'attributes' => $attributes,
            'purchase_price' => $price,
            'currency' => $currency,
            'vat_rate' => 23,
            'unit' => 'szt.',
            'source_url' => 'https://example.test/pl/products/znak-'.$remoteId,
            'sort_order' => $sortOrder,
            'price_checked_at' => Carbon::parse('2026-09-12 02:00:00'),
            'last_seen_at' => Carbon::parse('2026-09-12 02:00:00'),
            'removed_at' => $removed ? Carbon::parse('2026-09-12 02:00:00') : null,
        ]);
    }

    private function sizeRow(
        Product $product,
        B2bAccount $account,
        string $remoteId,
        string $sku,
        string $label,
        float $price,
        ?float $listPrice,
        ?string $availability,
        int $sortOrder,
    ): ProductVariant {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'kind' => ProductVariant::KIND_SIZE,
            'b2b_account_id' => $account->id,
            'source' => ProductSourcePrice::b2bKey((int) $account->id),
            'remote_id' => $remoteId,
            'sku' => $sku,
            'label' => $label,
            'purchase_price' => $price,
            'list_price_net' => $listPrice,
            'currency' => 'PLN',
            'availability' => $availability,
            'sort_order' => $sortOrder,
            'price_checked_at' => Carbon::parse('2026-09-12 02:00:00'),
            'last_seen_at' => Carbon::parse('2026-09-12 02:00:00'),
        ]);
    }

    private function history(ProductVariant $variant, float $price, string $at, ?int $syncRunId = null): void
    {
        $row = new ProductVariantPriceHistory;
        $row->forceFill([
            'product_variant_id' => $variant->id,
            'b2b_sync_run_id' => $syncRunId,
            'purchase_price' => $price,
            'currency' => 'PLN',
            'source' => 'b2b:anro',
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ])->save();
    }
}
