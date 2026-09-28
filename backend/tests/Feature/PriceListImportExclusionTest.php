<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductImportExclusion;
use App\Models\User;
use App\Services\Catalog\ProductImportExclusions;
use App\Services\PriceListDeletionService;
use App\Services\PriceListImportService;
use App\Services\ProductDeletionService;
use App\Services\SpreadsheetColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * „Usuń i pomijaj przy imporcie” w imporcie cennika z pliku: pozycja usuniętej karty (kod wiersza, a karta sprzed zapisu
 * kodów — SKU) nie zakłada ani nie aktualizuje karty przy kolejnych importach tego producenta, dopóki człowiek jej nie
 * przywróci. Pominięcia poza rows_skipped i skipped_details — jedna linia uwag i licznik trafień blokady.
 */
final class PriceListImportExclusionTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = 'Pominięto pozycji usuniętych z pominięciem: ';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
    }

    public function test_card_deleted_with_skip_is_not_recreated_by_reimport(): void
    {
        $this->importProducts('v1', [
            $this->row('EX-100', 'Rękawica powlekana nitrylem', 12.00, 10.00),
            $this->row('EX-200', 'Kask ochronny biały', 30.00, 25.00),
        ]);
        $deleted = Product::query()->where('sku', 'EX-100')->sole();
        $kept = Product::query()->where('sku', 'EX-200')->sole();

        $this->deleteWithSkip($deleted);

        $exclusion = ProductImportExclusion::query()->sole();
        $this->assertSame(ProductImportExclusion::KIND_POSITION, $exclusion->match_kind);
        $this->assertSame('EX-100', $exclusion->position_key);

        $result = $this->importProducts('v2', [
            $this->row('EX-100', 'Rękawica powlekana nitrylem', 13.00, 11.00),
            $this->row('EX-200', 'Kask ochronny biały', 31.00, 26.00),
        ]);

        $this->assertSame(0, Product::query()->where('sku', 'EX-100')->count());
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(1, $result['suppressed']);
        $this->assertSame([(int) $kept->id], $result['product_ids']);
        $this->assertEquals(31.00, (float) $kept->fresh()?->catalog_price_net);
        // jedna linia uwag, na początku; pominięcie nie trafia do skipped_details
        $this->assertSame(self::NOTE.'1 (Cenniki → Usunięte z pominięciem).', $result['errors'][0]);
        $this->assertCount(1, array_filter($result['errors'], static fn (string $e): bool => str_contains($e, 'EX-100') || str_starts_with($e, self::NOTE)));
        $this->assertSame([], $result['skipped_details']);
        $import = PriceListImport::query()->latest('id')->firstOrFail();
        $this->assertSame(0, (int) $import->rows_skipped);
        $this->assertSame(self::NOTE.'1 (Cenniki → Usunięte z pominięciem).', $import->errors[0]);
        // kod wiersza pominiętej pozycji nie ląduje przy żadnej karcie
        $this->assertSame(0, ProductIdentifier::query()->where('position_key', 'EX-100')->count());

        $exclusion->refresh();
        $this->assertSame(1, $exclusion->hits);
        $this->assertNotNull($exclusion->last_hit_at);
        $this->assertNull($exclusion->restored_at);

        // kolejny import — kolejne trafienie
        $this->importProducts('v3', [$this->row('EX-100', 'Rękawica powlekana nitrylem', 13.00, 11.00)]);
        $this->assertSame(2, $exclusion->fresh()?->hits);
        $this->assertSame(0, Product::query()->where('sku', 'EX-100')->count());
    }

    public function test_block_survives_deleting_the_price_list_entry(): void
    {
        $first = $this->importProducts('v1', [
            $this->row('EX-100', 'Rękawica powlekana nitrylem', 12.00, 10.00),
            $this->row('EX-200', 'Kask ochronny biały', 30.00, 25.00),
        ]);
        $oldListId = (int) $first['price_list']->id;
        $this->deleteWithSkip(Product::query()->where('sku', 'EX-100')->sole());

        app(PriceListDeletionService::class)->delete(PriceList::query()->findOrFail($oldListId), $this->user);
        $this->assertSame(0, PriceList::query()->count());
        $exclusion = ProductImportExclusion::query()->sole();
        $this->assertNull($exclusion->price_list_id);

        $result = $this->importProducts('v2', [
            $this->row('EX-100', 'Rękawica powlekana nitrylem', 13.00, 11.00),
            $this->row('EX-200', 'Kask ochronny biały', 31.00, 26.00),
        ]);

        $this->assertNotSame($oldListId, (int) $result['price_list']->id);
        $this->assertSame(0, Product::query()->where('sku', 'EX-100')->count());
        $this->assertSame(1, Product::query()->where('sku', 'EX-200')->count());
        $this->assertSame(1, $result['suppressed']);
        $this->assertSame(1, $exclusion->fresh()?->hits);
    }

    public function test_restored_position_is_created_again(): void
    {
        $this->importProducts('v1', [$this->row('EX-100', 'Rękawica powlekana nitrylem', 12.00, 10.00)]);
        $this->deleteWithSkip(Product::query()->where('sku', 'EX-100')->sole());
        $exclusion = ProductImportExclusion::query()->sole();

        $this->assertSame(1, app(ProductImportExclusions::class)->restore([(int) $exclusion->id], $this->user));
        $result = $this->importProducts('v2', [$this->row('EX-100', 'Rękawica powlekana nitrylem', 13.00, 11.00)]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['suppressed']);
        $this->assertFalse(collect($result['errors'])->contains(static fn (string $e): bool => str_starts_with($e, self::NOTE)));
        $card = Product::query()->where('sku', 'EX-100')->sole();
        $this->assertSame(1, ProductIdentifier::query()->where('product_id', $card->id)->where('position_key', 'EX-100')->count());
        $this->assertSame(0, $exclusion->fresh()?->hits);
    }

    public function test_card_without_row_codes_is_blocked_by_its_sku(): void
    {
        $this->importProducts('v1', [
            $this->row('EX-100', 'Rękawica powlekana nitrylem', 12.00, 10.00),
            $this->row('EX-200', 'Kask ochronny biały', 30.00, 25.00),
        ]);
        $deleted = Product::query()->where('sku', 'EX-100')->sole();
        // karta z importu sprzed zapisu kodów wierszy (23.09.2026)
        ProductIdentifier::query()->where('product_id', $deleted->id)->delete();

        $this->deleteWithSkip($deleted);

        $exclusion = ProductImportExclusion::query()->sole();
        $this->assertSame(ProductImportExclusion::KIND_SKU, $exclusion->match_kind);
        $this->assertSame('EX-100', $exclusion->position_key);

        $result = $this->importProducts('v2', [
            $this->row('ex-100', 'Rękawica powlekana nitrylem', 13.00, 11.00),
            $this->row('EX-200', 'Kask ochronny biały', 31.00, 26.00),
        ]);

        $this->assertSame(0, Product::query()->whereRaw('lower(sku) = ?', ['ex-100'])->count());
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['suppressed']);
        $this->assertSame(1, $exclusion->fresh()?->hits);
    }

    public function test_blocked_size_of_a_collapsed_row_is_dropped_and_other_sizes_go_on(): void
    {
        // rozmiary w różnych cenach — osobne wiersze i karty
        $this->importBolle([
            ['BAX-S', 'Okulary BAXTER rozmiar S', 18.55, '3660740007768', 'BAX', 'S'],
            ['BAX-M', 'Okulary BAXTER rozmiar M', 19.90, '3660740007751', 'BAX', 'M'],
        ], '2026-01');
        $small = ProductIdentifier::query()->where('position_key', 'BAX-S')->firstOrFail()->product_id;
        $medium = ProductIdentifier::query()->where('position_key', 'BAX-M')->firstOrFail()->product_id;
        $this->assertNotSame((int) $small, (int) $medium, 'rozmiary w różnych cenach mają być osobnymi kartami');
        $this->deleteWithSkip(Product::query()->findOrFail($small));
        $exclusion = ProductImportExclusion::query()->where('position_key', 'BAX-S')->sole();

        // ta sama cena — rozmiary zwinięte w jeden wiersz
        $result = $this->importBolle([
            ['BAX-S', 'Okulary BAXTER rozmiar S', 18.55, '3660740007768', 'BAX', 'S'],
            ['BAX-M', 'Okulary BAXTER rozmiar M', 18.55, '3660740007751', 'BAX', 'M'],
        ], '2026-02');

        $this->assertSame(1, $result['suppressed']);
        $this->assertSame(1, $exclusion->fresh()?->hits);
        $this->assertSame(0, ProductIdentifier::query()->where('position_key', 'BAX-S')->count());
        $this->assertSame(3, ProductIdentifier::query()->where('position_key', 'BAX-M')->whereNull('removed_at')->count());
        $this->assertSame(self::NOTE.'1 (Cenniki → Usunięte z pominięciem).', $result['errors'][0]);
        $this->assertSame(1, $result['created'] + $result['updated']);
    }

    public function test_new_size_collapsed_with_blocked_sizes_does_not_recreate_the_deleted_card(): void
    {
        $this->importBolle([
            ['BAX-S', 'Okulary BAXTER rozmiar S', 18.55, '3660740007768', 'BAX', 'S'],
            ['BAX-M', 'Okulary BAXTER rozmiar M', 18.55, '3660740007751', 'BAX', 'M'],
            ['TRACPSF', 'Okulary TRACKER', 17.45, '3660740004835', 'TRAC', ''],
        ], '2026-01');
        $this->deleteWithSkip(Product::query()->where('sku', 'BAX')->sole());
        $this->assertSame(2, ProductImportExclusion::query()->count());

        // nowy rozmiar L w tej samej cenie — zwinięty z usuniętymi S i M w wiersz o SKU usuniętej karty
        $result = $this->importBolle([
            ['BAX-S', 'Okulary BAXTER rozmiar S', 18.55, '3660740007768', 'BAX', 'S'],
            ['BAX-M', 'Okulary BAXTER rozmiar M', 18.55, '3660740007751', 'BAX', 'M'],
            ['BAX-L', 'Okulary BAXTER rozmiar L', 18.55, '3660740007744', 'BAX', 'L'],
            ['TRACPSF', 'Okulary TRACKER', 17.45, '3660740004835', 'TRAC', ''],
        ], '2026-02');

        $this->assertSame(0, Product::query()->where('sku', 'BAX')->count());
        $this->assertSame(0, ProductIdentifier::query()->where('position_key', 'like', 'BAX-%')->count());
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(3, $result['suppressed']);
        $this->assertSame(
            self::NOTE.'3 (Cenniki → Usunięte z pominięciem); w tym nowych kodów bez blokady: 1 — ich wiersz założyłby od nowa usuniętą kartę (to samo SKU).',
            $result['errors'][0],
        );
        $this->assertSame([1, 1], ProductImportExclusion::query()->orderBy('id')->pluck('hits')->map(static fn ($h): int => (int) $h)->all());
    }

    public function test_position_with_active_identifier_on_another_card_is_not_blocked(): void
    {
        $this->importProducts('v1', [
            $this->row('EX-100', 'Rękawica powlekana nitrylem', 12.00, 10.00),
            $this->row('EX-200', 'Kask ochronny biały', 30.00, 25.00),
        ]);
        $list = PriceList::query()->sole();
        $deleted = Product::query()->where('sku', 'EX-100')->sole();
        $other = Product::query()->where('sku', 'EX-200')->sole();
        $foreign = ProductIdentifier::query()->create([
            'product_id' => $other->id,
            'source_key' => 'file:'.$list->id,
            'price_list_id' => $list->id,
            'position_key' => 'EX-100',
            'type' => ProductIdentifier::TYPE_EAN,
            'value' => '5901234567890',
            'normalized' => '05901234567890',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $service = app(ProductImportExclusions::class);
        $this->assertSame([], $service->positionsOf([(int) $deleted->id]));

        // identyfikator zniknięty z pliku na innej karcie pozycji nie zabiera
        $foreign->forceFill(['removed_at' => now()])->save();
        $positions = $service->positionsOf([(int) $deleted->id]);
        $this->assertSame(['EX-100'], array_column($positions[(int) $deleted->id] ?? [], 'position_key'));
    }

    private function deleteWithSkip(Product $product): void
    {
        $result = app(ProductDeletionService::class)->deleteMany([(int) $product->id], $this->user, true);
        $this->assertSame(1, $result['deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $sku, string $name, float $catalog, float $purchase): array
    {
        return ['sku' => $sku, 'name' => $name, 'catalog_price_net' => $catalog, 'purchase_price' => $purchase, 'currency' => 'PLN'];
    }

    /**
     * Cennik PDF/AI (importFromProducts) — ta sama ścieżka zapisu co arkusz.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function importProducts(string $version, array $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'excl').'.pdf';
        file_put_contents($path, "%PDF-1.4\n");

        try {
            $result = app(PriceListImportService::class)->importFromProducts(
                new UploadedFile($path, 'cennik.pdf', 'application/pdf', null, true),
                'Anro',
                $version,
                $this->user,
                $rows,
            );
            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

            return $result;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: float, 3: string, 4: string, 5: string}>  $items  kod, nazwa, cena, EAN, model, rozmiar
     * @return array<string, mixed>
     */
    private function importBolle(array $items, string $version): array
    {
        $rows = [['Article', 'Opis produktu', 'Cena EUR', 'EAN unit', 'Model', 'Rozmiar']];
        foreach ($items as $item) {
            $rows[] = $item;
        }
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('INDUSTRIAL');
        $sheet->fromArray($rows, null, 'A1', true);
        foreach (array_keys($rows) as $i) {
            $sheet->setCellValueExplicit('D'.($i + 1), (string) $rows[$i][3], DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'excl').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $result = app(PriceListImportService::class)->importWithMapping(
                new UploadedFile($path, 'bolle.xlsx', null, null, true),
                'Bolle',
                $version,
                $this->user,
                app(SpreadsheetColumnMapper::class)->refineMapping($path, [
                    'currency' => 'EUR',
                    'sheets' => [[
                        'sheet' => 'INDUSTRIAL',
                        'include' => true,
                        'header_excel_row' => 1,
                        'columns' => ['sku' => 0, 'name' => 1, 'catalog_price' => 2, 'ean' => 3, 'model_key' => 4, 'packaging' => 5],
                        'repeating_headers' => false,
                        'confidence' => 1.0,
                    ]],
                ]),
            );
            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));

            return $result;
        } finally {
            @unlink($path);
        }
    }
}
