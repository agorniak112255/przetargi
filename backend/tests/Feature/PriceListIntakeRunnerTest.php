<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\MapPriceListSourcesJob;
use App\Models\AssortmentGroup;
use App\Models\PriceList;
use App\Models\PriceListFile;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductSourcePin;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\PriceLists\Importers\PriceListFormatChanged;
use App\Services\PriceLists\IntakeBusy;
use App\Services\PriceLists\IntakeNotReady;
use App\Services\PriceLists\PriceListIntakeRunner;
use App\Services\PriceLists\ReadOnlyViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakePriceListImporter;
use Tests\TestCase;

/**
 * Cennik z importerem: podgląd importu (PreviewView, zero zapisów) i import pliku (karty, status pliku, zadanie mapy).
 */
final class PriceListIntakeRunnerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        FakePriceListImporter::install();
        $this->user = User::factory()->create();
    }

    public function test_preview_returns_plan_and_map_and_writes_nothing(): void
    {
        Queue::fake();
        $list = $this->list();
        $this->card('A-1', 'Kask ochronny', 10.00, $list);
        $human = $this->card('E-5', 'Okulary', 20.00, $list);
        $human->update(['shop_source_url' => 'https://sklep.test/okulary-e5']);
        FakePriceListImporter::$pins = ['B-2' => 'https://maker.test/p/b-2'];
        FakePriceListImporter::$needsFetch = ['D-4' => true];
        $file = $this->file($list, [
            ['A-1', 'Kask ochronny', 12.00],
            ['B-2', 'Rękawica nitrylowa', 3.50],
            ['C-3', 'Nauszniki', 30.00],
            ['D-4', 'Półmaska', 40.00],
            ['E-5', 'Okulary', 20.00],
            ['', 'wiersz bez kodu', 1.00],
        ]);

        $files = Storage::disk('local')->allFiles();
        $counts = [Product::query()->count(), ProductSourcePrice::query()->count(), ProductSourcePin::query()->count(), DB::table('price_list_imports')->count()];
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql) === 1) {
                $writes++;
            }
        });

        $view = app(PriceListIntakeRunner::class)->preview($list, $file);

        $this->assertSame(0, $writes);
        Queue::assertNothingPushed();
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame($counts, [Product::query()->count(), ProductSourcePrice::query()->count(), ProductSourcePin::query()->count(), DB::table('price_list_imports')->count()]);
        $this->assertEquals(10.00, (float) ProductSourcePrice::query()->where('product_id', Product::query()->where('sku', 'A-1')->value('id'))->value('catalog_price_net'));
        $this->assertSame(PriceListFile::STATUS_NEW, $file->fresh()?->status);

        $this->assertSame(['key' => FakePriceListImporter::KEY, 'version' => 1], $view['importer']);
        $this->assertSame(6, $view['rows_total']);
        $this->assertSame(['create' => 3, 'update' => 2, 'skip' => 0, 'blocked' => 0], $view['rows']);
        $this->assertSame([['ref' => 'wiersz 7', 'sku' => null, 'reason' => 'brak kodu albo ceny']], $view['skipped']);
        $this->assertSame([['sku' => 'A-1', 'name' => 'Kask ochronny', 'old' => 10.0, 'new' => 12.0]], $view['price_changes']);
        $this->assertSame(['manufacturer' => 1, 'supplier' => 0, 'shop' => 0, 'unresolved' => 2, 'human_url' => 1, 'b2b_description' => 0, 'not_checked' => 1], $view['sources']);
        $this->assertSame(['A-1', 'C-3'], array_column($view['unresolved'], 'sku'));
        $this->assertSame('https://maker.test/p/b-2', collect($view['samples'])->firstWhere('sku', 'B-2')['url']);
        $this->assertSame('create', collect($view['samples'])->firstWhere('sku', 'B-2')['action']);
        $this->assertContains('uwaga importera testowego', $view['notes']);
        // karta z adresem człowieka nie idzie do importera; nowe karty idą jako niezapisane, bez id
        $this->assertNotContains('E-5', FakePriceListImporter::$mapped);
    }

    public function test_preview_limit_maps_only_first_cards(): void
    {
        $list = $this->list();
        $file = $this->file($list, [['A-1', 'Kask', 1.0], ['B-2', 'Kask 2', 2.0], ['C-3', 'Kask 3', 3.0]]);

        $view = app(PriceListIntakeRunner::class)->preview($list, $file, 2);

        $this->assertSame(['A-1', 'B-2'], FakePriceListImporter::$mapped);
        $this->assertStringContainsString('2 z 3 kart', implode("\n", $view['not_in_preview']));
    }

    public function test_preview_fails_with_read_only_violation_when_importer_writes_and_rolls_back(): void
    {
        $list = $this->list();
        $file = $this->file($list, [['A-1', 'Kask', 1.0]]);
        FakePriceListImporter::$writeOnMap = true;

        try {
            app(PriceListIntakeRunner::class)->preview($list, $file);
            $this->fail('Brak ReadOnlyViolation');
        } catch (ReadOnlyViolation $e) {
            $this->assertStringContainsString('price_lists', $e->getMessage());
        }

        $this->assertNull($list->fresh()?->importer_notes);
        // po podglądzie zwykłe zapisy działają (kolejka i pamięć podręczna przywrócone)
        $this->assertSame('sync', config('queue.default'));
        $list->update(['importer_notes' => 'po podglądzie']);
        $this->assertSame('po podglądzie', $list->fresh()?->importer_notes);
    }

    public function test_preview_without_importer_or_with_missing_class_is_not_ready(): void
    {
        $list = $this->list(['importer_key' => null]);
        $file = $this->file($list, [['A-1', 'Kask', 1.0]]);
        try {
            app(PriceListIntakeRunner::class)->preview($list, $file);
            $this->fail('Brak IntakeNotReady');
        } catch (IntakeNotReady $e) {
            $this->assertStringContainsString('nie ma jeszcze importera', $e->getMessage());
        }

        $list->update(['importer_key' => 'nie-ma-takiego']);
        $this->expectException(IntakeNotReady::class);
        app(PriceListIntakeRunner::class)->preview($list, $file);
    }

    public function test_format_change_fails_preview_and_marks_file_failed_on_import(): void
    {
        Queue::fake();
        $list = $this->list();
        $file = $this->rawFile($list, "zly;naglowek\nA-1;Kask;1\n");

        try {
            app(PriceListIntakeRunner::class)->preview($list, $file);
            $this->fail('Brak PriceListFormatChanged');
        } catch (PriceListFormatChanged) {
            $this->assertSame(PriceListFile::STATUS_NEW, $file->fresh()?->status);
        }

        try {
            app(PriceListIntakeRunner::class)->import($list, $file, $this->user, false);
            $this->fail('Brak PriceListFormatChanged');
        } catch (PriceListFormatChanged) {
        }
        $file->refresh();
        $this->assertSame(PriceListFile::STATUS_FAILED, $file->status);
        $this->assertStringContainsString('Format pliku się zmienił', (string) $file->error);
        $this->assertSame(0, Product::query()->count());
        Queue::assertNotPushed(MapPriceListSourcesJob::class);
    }

    public function test_import_creates_cards_marks_file_imported_and_queues_map_job(): void
    {
        Queue::fake();
        $list = $this->list();
        $file = $this->file($list, [
            ['R-100-S', 'Rękawica R-100 rozmiar S', 5.00, '5901111111111', 'S'],
            ['R-100-M', 'Rękawica R-100 rozmiar M', 5.00, '5901111111112', 'M'],
        ]);

        $result = app(PriceListIntakeRunner::class)->import($list, $file, $this->user, true);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame('queued', $result['map_job']);
        // bez scalania rozmiarów: karta na wiersz, do wiersza cennika z przyjęcia (bez drugiego wpisu price_lists)
        $this->assertSame(['R-100-M', 'R-100-S'], Product::query()->orderBy('sku')->pluck('sku')->all());
        $this->assertSame(1, PriceList::query()->count());
        $this->assertSame(2, ProductSourcePrice::query()->where('price_list_id', $list->id)->count());
        $file->refresh();
        $this->assertSame(PriceListFile::STATUS_IMPORTED, $file->status);
        $this->assertNotNull($file->price_list_import_id);
        $this->assertNotNull($file->imported_at);
        $this->assertSame(FakePriceListImporter::KEY, $file->importer_key);
        $this->assertSame(1, $file->importer_version);
        $this->assertSame('2026-10', $list->fresh()?->version);
        Queue::assertPushed(MapPriceListSourcesJob::class, fn (MapPriceListSourcesJob $job): bool => $job->priceListId === (int) $list->id
            && $job->describe === true && $job->productIds === null && $job->userId === (int) $this->user->id);
    }

    public function test_import_does_not_clear_card_fields_missing_in_the_file(): void
    {
        Queue::fake();
        $list = $this->list();
        $card = $this->card('M-1', 'Rękawica MAPA', 10.00, $list);
        $card->update([
            'packaging' => '6-9', 'ean' => '3000000000001', 'category' => 'Rękawice', 'category_source' => Product::CATEGORY_SOURCE_IMPORT,
            'price_list_attributes' => ['Rozmiar' => '6-9'],
        ]);
        $file = $this->file($list, [['M-1', 'Rękawica MAPA', 11.00]]);

        $result = app(PriceListIntakeRunner::class)->import($list, $file, $this->user, false);

        $this->assertSame(1, $result['updated']);
        $card->refresh();
        $this->assertSame('6-9', $card->packaging);
        $this->assertSame('3000000000001', $card->ean);
        $this->assertSame(['Rozmiar' => '6-9'], $card->price_list_attributes);
        $this->assertEquals(11.00, (float) ProductSourcePrice::query()->where('product_id', $card->id)->value('catalog_price_net'));
    }

    public function test_import_applies_global_discount_and_passes_it_to_the_importer(): void
    {
        Queue::fake();
        $list = $this->list();
        AssortmentGroup::query()->create(['manufacturer' => 'Anro', 'name' => AssortmentGroup::GLOBAL_NAME, 'discount_percent' => 20, 'is_global' => true]);
        $file = $this->file($list, [['G-1', 'Kask', 100.00]]);

        app(PriceListIntakeRunner::class)->import($list, $file, $this->user, false);

        $this->assertSame(20.0, FakePriceListImporter::$lastGlobalDiscount);
        $slot = ProductSourcePrice::query()->where('product_id', Product::query()->where('sku', 'G-1')->value('id'))->sole();
        $this->assertEquals(100.00, (float) $slot->catalog_price_net);
        $this->assertEquals(80.00, (float) $slot->purchase_price);
        $this->assertEquals(20.00, (float) $slot->discount_percent);
    }

    public function test_import_of_the_same_list_twice_at_once_is_busy(): void
    {
        Queue::fake();
        $list = $this->list();
        $file = $this->file($list, [['A-1', 'Kask', 10.0]]);
        $lock = Cache::lock('price-list-intake:'.$list->id, 60);
        $this->assertTrue($lock->get());

        try {
            app(PriceListIntakeRunner::class)->import($list, $file, $this->user, false);
            $this->fail('Brak IntakeBusy');
        } catch (IntakeBusy $e) {
            $this->assertSame('Import tego cennika już trwa.', $e->getMessage());
        }
        $this->assertSame(PriceListFile::STATUS_NEW, $file->fresh()?->status);
        $this->assertSame(0, Product::query()->count());

        $lock->release();
        app(PriceListIntakeRunner::class)->import($list, $file, $this->user, false);
        $this->assertSame(1, Product::query()->count());
        // blokada zwolniona po imporcie
        $this->assertTrue(Cache::lock('price-list-intake:'.$list->id, 60)->get());
    }

    public function test_error_after_saved_import_marks_file_imported_with_note_and_queues_map(): void
    {
        Queue::fake();
        $list = $this->list();
        $file = $this->file($list, [['A-1', 'Kask', 10.0]]);
        // persistImport po zatwierdzeniu transakcji wczytuje importującego (load('importer')) — tu pada
        User::retrieved(static function (): void {
            throw new RuntimeException('awaria po zapisie');
        });

        try {
            app(PriceListIntakeRunner::class)->import($list, $file, $this->user, true);
            $this->fail('Brak wyjątku');
        } catch (RuntimeException $e) {
            $this->assertSame('awaria po zapisie', $e->getMessage());
        }

        $this->assertSame(1, Product::query()->count());
        $file->refresh();
        $this->assertSame(PriceListFile::STATUS_IMPORTED, $file->status);
        $this->assertSame('import zapisany, błąd po zapisie: awaria po zapisie', $file->error);
        $this->assertSame((int) PriceListImport::query()->where('price_list_id', $list->id)->value('id'), (int) $file->price_list_import_id);
        Queue::assertPushed(MapPriceListSourcesJob::class, static fn (MapPriceListSourcesJob $job): bool => $job->describe);
    }

    public function test_list_with_assortment_group_discounts_is_not_ready_for_preview_or_import(): void
    {
        Queue::fake();
        $list = $this->list();
        AssortmentGroup::query()->create(['manufacturer' => 'Anro', 'name' => 'Rękawice', 'discount_percent' => 30, 'is_global' => false]);
        $file = $this->file($list, [['A-1', 'Kask', 10.0]]);

        foreach (['preview', 'import'] as $action) {
            try {
                $action === 'preview'
                    ? app(PriceListIntakeRunner::class)->preview($list, $file)
                    : app(PriceListIntakeRunner::class)->import($list, $file, $this->user, false);
                $this->fail('Brak IntakeNotReady przy '.$action);
            } catch (IntakeNotReady $e) {
                $this->assertStringContainsString('grupach asortymentowych', $e->getMessage());
            }
        }
        $this->assertSame(PriceListFile::STATUS_NEW, $file->fresh()?->status);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_preview_reports_totals_before_lists_are_cut(): void
    {
        $list = $this->list();
        $rows = [];
        for ($i = 1; $i <= 205; $i++) {
            $rows[] = ['', 'bez kodu '.$i, 1.0];
        }
        for ($i = 1; $i <= 105; $i++) {
            $this->card('P-'.$i, 'Karta '.$i, 10.0, $list);
            $rows[] = ['P-'.$i, 'Karta '.$i, 11.0];
        }
        $file = $this->file($list, $rows);

        $view = app(PriceListIntakeRunner::class)->preview($list, $file, 500);

        $this->assertSame(205, $view['skipped_total']);
        $this->assertCount(200, $view['skipped']);
        $this->assertSame(105, $view['price_changes_total']);
        $this->assertCount(100, $view['price_changes']);
        $this->assertSame(105, $view['unresolved_total']);
        $this->assertCount(100, $view['unresolved']);
    }

    public function test_missing_file_on_disk_gives_readable_error(): void
    {
        $list = $this->list();
        $file = $this->file($list, [['A-1', 'Kask', 10.0]]);
        Storage::disk('local')->delete($file->path);

        try {
            app(PriceListIntakeRunner::class)->preview($list, $file);
            $this->fail('Brak wyjątku');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Brak pliku cennika na dysku', $e->getMessage());
            $this->assertStringContainsString('cennik.csv', $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function list(array $overrides = []): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => 'Anro', 'manufacturer_key' => PriceList::manufacturerKey('Anro'), 'version' => '2026-10',
            'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
            'source_policy' => PriceList::POLICY_MAP_ONLY, 'importer_key' => FakePriceListImporter::KEY,
            ...$overrides,
        ]);
    }

    private function card(string $sku, string $name, float $price, PriceList $list): Product
    {
        $card = Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => 'Anro',
            'catalog_price_net' => $price, 'purchase_price' => $price, 'currency' => 'PLN',
        ]);
        ProductSourcePrice::query()->create([
            'product_id' => $card->id, 'source_key' => ProductSourcePrice::SOURCE_FILE, 'price_list_id' => $list->id,
            'catalog_price_net' => $price, 'purchase_price' => $price, 'currency' => 'PLN',
        ]);

        return $card;
    }

    /** @param  list<array{0: string, 1: string, 2: float|string, 3?: string, 4?: string}>  $rows */
    private function file(PriceList $list, array $rows): PriceListFile
    {
        return $this->rawFile($list, FakePriceListImporter::csv($rows));
    }

    private function rawFile(PriceList $list, string $content): PriceListFile
    {
        $sha = hash('sha256', $content);
        $path = 'price-list-files/'.$sha.'.csv';
        Storage::disk('local')->put($path, $content);

        return PriceListFile::query()->create([
            'price_list_id' => $list->id, 'sha256' => $sha, 'disk' => 'local', 'path' => $path,
            'original_name' => 'cennik.csv', 'size' => strlen($content), 'mime' => 'text/csv', 'status' => PriceListFile::STATUS_NEW,
            'uploaded_by' => $this->user->id,
        ]);
    }
}
