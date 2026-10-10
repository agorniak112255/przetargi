<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\MapPriceListSourcesJob;
use App\Models\PriceList;
use App\Models\PriceListFile;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductSourcePin;
use App\Models\ProductSourcePrice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePriceListImporter;
use Tests\TestCase;

/** Polecenia przyjęcia cenników z importerem: pending, bind, preview, map, import. */
final class PriceListIntakeCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        FakePriceListImporter::install();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create()->assignRole('admin');
    }

    public function test_pending_lists_intake_price_lists_with_status_and_suggested_importer(): void
    {
        $this->list(null);
        PriceList::query()->create(['manufacturer' => 'Stary', 'manufacturer_key' => 'stary', 'version' => '1', 'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0]);

        $this->assertSame(0, Artisan::call('price-lists:pending'));
        $output = Artisan::output();
        $this->assertStringContainsString('awaiting_file', $output);
        $this->assertStringContainsString(FakePriceListImporter::KEY, $output);
        $this->assertStringNotContainsString('Stary', $output);
    }

    public function test_bind_by_manufacturer_key_unknown_key_and_unbind(): void
    {
        $list = $this->list(null);

        $this->artisan('price-lists:bind', ['lista' => 'anro', 'klucz' => 'nie-ma'])->assertFailed();
        $this->assertNull($list->fresh()?->importer_key);

        $this->artisan('price-lists:bind', ['lista' => 'anro', 'klucz' => FakePriceListImporter::KEY])->assertSuccessful();
        $this->assertSame(FakePriceListImporter::KEY, $list->fresh()?->importer_key);

        $this->artisan('price-lists:bind', ['lista' => (string) $list->id, '--unbind' => true])->assertSuccessful();
        $this->assertNull($list->fresh()?->importer_key);
    }

    public function test_preview_json_returns_preview_view_without_writes(): void
    {
        $list = $this->list();
        $file = $this->file($list, [['A-1', 'Kask', 10.0]]);

        $code = Artisan::call('price-lists:preview', ['lista' => 'anro', '--json' => true, '--file' => (string) $file->id]);
        $view = json_decode(Artisan::output(), true);

        $this->assertSame(0, $code);
        $this->assertSame(FakePriceListImporter::KEY, $view['importer']['key']);
        $this->assertSame(1, $view['rows']['create']);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_map_preview_writes_nothing_and_apply_writes_pins(): void
    {
        $list = $this->list();
        $card = Product::query()->create(['sku' => 'A-1', 'name' => 'Kask', 'manufacturer' => 'Anro', 'catalog_price_net' => 1, 'purchase_price' => 1, 'currency' => 'PLN']);
        ProductSourcePrice::query()->create(['product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id, 'catalog_price_net' => 1, 'purchase_price' => 1, 'currency' => 'PLN']);
        FakePriceListImporter::$pins = ['A-1' => 'https://maker.test/a-1'];

        $this->artisan('price-lists:map', ['lista' => 'anro'])->expectsOutputToContain('Podgląd')->assertSuccessful();
        $this->assertSame(0, ProductSourcePin::query()->count());

        $this->artisan('price-lists:map', ['lista' => 'anro', '--apply' => true, '--id' => [(string) $card->id]])->assertSuccessful();
        $this->assertSame('https://maker.test/a-1', ProductSourcePin::query()->sole()->url);

        $this->artisan('price-lists:map', ['lista' => 'anro', '--queue' => true])->assertSuccessful();
        Queue::assertPushed(MapPriceListSourcesJob::class);
    }

    public function test_import_without_apply_is_preview_and_with_apply_imports(): void
    {
        $list = $this->list();
        $file = $this->file($list, [['A-1', 'Kask', 10.0]]);

        $this->artisan('price-lists:import', ['lista' => 'anro'])->expectsOutputToContain('PODGLĄD')->assertSuccessful();
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(PriceListFile::STATUS_NEW, $file->fresh()?->status);

        $this->artisan('price-lists:import', ['lista' => 'anro', '--apply' => true, '--describe' => true])->assertSuccessful();
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(PriceListFile::STATUS_IMPORTED, $file->fresh()?->status);
        Queue::assertPushed(MapPriceListSourcesJob::class, static fn (MapPriceListSourcesJob $job): bool => $job->describe);
    }

    public function test_list_with_importer_but_old_way_is_not_ready_for_preview_import_or_map(): void
    {
        $list = $this->list();
        $list->update(['source_policy' => null]);
        $this->file($list, [['A-1', 'Kask', 10.0]]);
        $card = Product::query()->create(['sku' => 'A-1', 'name' => 'Kask', 'manufacturer' => 'Anro', 'catalog_price_net' => 1, 'purchase_price' => 1, 'currency' => 'PLN']);
        ProductSourcePrice::query()->create(['product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id, 'catalog_price_net' => 1, 'purchase_price' => 1, 'currency' => 'PLN']);

        $this->artisan('price-lists:preview', ['lista' => 'anro'])->expectsOutputToContain('dawnym sposobem')->assertFailed();
        $this->artisan('price-lists:import', ['lista' => 'anro', '--apply' => true])->expectsOutputToContain('dawnym sposobem')->assertFailed();
        $this->assertSame(1, Product::query()->count());

        $result = (new MapPriceListSourcesJob((int) $list->id, true))->run(true);

        $this->assertSame(0, $result['cards']);
        $this->assertSame(0, ProductSourcePin::query()->count());
        $this->assertNull($card->fresh()?->review_reason);
        $this->assertSame(0, ProductEnrichmentBatch::query()->count());
    }

    public function test_preview_of_list_without_importer_fails(): void
    {
        $list = $this->list(null);
        $this->file($list, [['A-1', 'Kask', 10.0]]);

        $this->artisan('price-lists:preview', ['lista' => 'anro'])->expectsOutputToContain('nie ma jeszcze importera')->assertFailed();
    }

    private function list(?string $importer = FakePriceListImporter::KEY): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => 'Anro', 'manufacturer_key' => 'anro', 'version' => '2026',
            'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
            'source_policy' => PriceList::POLICY_MAP_ONLY, 'importer_key' => $importer,
        ]);
    }

    /** @param  list<array{0: string, 1: string, 2: float}>  $rows */
    private function file(PriceList $list, array $rows): PriceListFile
    {
        $content = FakePriceListImporter::csv($rows);
        $sha = hash('sha256', $content);
        Storage::disk('local')->put('price-list-files/'.$sha.'.csv', $content);

        return PriceListFile::query()->create([
            'price_list_id' => $list->id, 'sha256' => $sha, 'disk' => 'local', 'path' => 'price-list-files/'.$sha.'.csv',
            'original_name' => 'cennik.csv', 'size' => strlen($content), 'status' => PriceListFile::STATUS_NEW,
        ]);
    }
}
