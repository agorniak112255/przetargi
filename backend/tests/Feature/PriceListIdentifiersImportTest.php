<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\User;
use App\Services\Catalog\ProductIdentifierStore;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Identyfikatory wierszy cennika z pliku (product_identifiers, source_key „file:{cennik}”): kod, EAN i kod modelu
 * każdego wiersza dosłownie, także rozmiarów zwiniętych w jedną kartę; ponowny import bez duplikatów, wiersz, którego
 * nowy plik nie ma, oznaczony (nie skasowany).
 */
final class PriceListIdentifiersImportTest extends TestCase
{
    use RefreshDatabase;

    private const ROWS = [
        ['BAX-S', 'Okulary BAXTER rozmiar S', 18.55, '3660740007768', 'BAX', 'S'],
        ['BAX-M', 'Okulary BAXTER rozmiar M', 18.55, '3660740007751', 'BAX', 'M'],
        ['TRACPSF', 'Okulary TRACKER', 17.45, '3660740004835', 'TRAC', ''],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_every_row_keeps_its_code_and_ean_also_when_sizes_collapse_into_one_card(): void
    {
        $this->import(self::ROWS, '2026-01');

        $list = PriceList::query()->sole();
        $import = PriceListImport::query()->sole();
        $bax = Product::query()->where('sku', 'BAX')->sole();
        $this->assertSame(2, Product::query()->count());

        $rows = ProductIdentifier::query()->where('product_id', $bax->id)->orderBy('position_key')->orderBy('type')->get();
        $this->assertSame(
            [
                ['BAX-M', 'ean', '3660740007751', 'EAN unit', 'M'],
                ['BAX-M', 'model_code', 'BAX', 'Model', 'M'],
                ['BAX-M', 'source_code', 'BAX-M', 'Article', 'M'],
                ['BAX-S', 'ean', '3660740007768', 'EAN unit', 'S'],
                ['BAX-S', 'model_code', 'BAX', 'Model', 'S'],
                ['BAX-S', 'source_code', 'BAX-S', 'Article', 'S'],
            ],
            $rows->map(static fn (ProductIdentifier $r): array => [$r->position_key, $r->type, $r->value, $r->source_field, $r->variant_label])->all(),
        );
        $ean = $rows->firstWhere('value', '3660740007768');
        $this->assertSame('file:'.$list->id, $ean->source_key);
        $this->assertSame((int) $list->id, (int) $ean->price_list_id);
        $this->assertSame((int) $import->id, (int) $ean->price_list_import_id);
        $this->assertNull($ean->b2b_account_id);
        $this->assertSame('Bolle', $ean->manufacturer);
        $this->assertSame('bolle', $ean->brand_key);
        $this->assertSame('03660740007768', $ean->normalized);

        $tracker = Product::query()->where('sku', 'TRAC')->sole();
        $this->assertSame(3, ProductIdentifier::query()->where('product_id', $tracker->id)->count());
        $this->assertNull(ProductIdentifier::query()->where('product_id', $tracker->id)->value('variant_label'));
    }

    public function test_reimport_adds_no_duplicates_and_row_missing_from_new_file_is_marked_not_deleted(): void
    {
        $this->import(self::ROWS, '2026-01');
        $count = ProductIdentifier::query()->count();
        $firstSeen = ProductIdentifier::query()->where('value', 'BAX-S')->value('first_seen_at');

        $this->travel(1)->hours();
        $this->import(self::ROWS, '2026-02');

        $this->assertSame($count, ProductIdentifier::query()->count());
        $this->assertSame(0, ProductIdentifier::query()->whereNotNull('removed_at')->count());
        $row = ProductIdentifier::query()->where('value', 'BAX-S')->sole();
        $this->assertEquals($firstSeen, $row->first_seen_at);
        $this->assertSame((int) PriceListImport::query()->latest('id')->value('id'), (int) $row->price_list_import_id);

        // rozmiar M zniknął z cennika — jego kody zostają w bazie, oznaczone
        $this->import([self::ROWS[0], self::ROWS[2]], '2026-03');

        $this->assertSame($count, ProductIdentifier::query()->count());
        $this->assertSame(3, ProductIdentifier::query()->where('position_key', 'BAX-M')->whereNotNull('removed_at')->count());
        $this->assertSame(0, ProductIdentifier::query()->where('position_key', '!=', 'BAX-M')->whereNotNull('removed_at')->count());
    }

    public function test_pdf_rows_give_code_and_ean(): void
    {
        $file = UploadedFile::fake()->create('cennik.pdf', 10, 'application/pdf');

        $result = app(PriceListImportService::class)->importFromProducts($file, 'JSP', '2026-01', User::factory()->create(), [
            ['sku' => 'ASA940-061-300', 'name' => 'Kask JSP EVO3', 'catalog_price_net' => 42.0, 'ean' => '5023255012345'],
            ['sku' => 'FAR0701', 'name' => 'Półmaska JSP', 'catalog_price_net' => 12.0],
        ]);

        $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));
        $this->assertSame(
            [
                ['ASA940-061-300', 'ean', '5023255012345', 'ean'],
                ['ASA940-061-300', 'source_code', 'ASA940-061-300', 'sku'],
                ['FAR0701', 'source_code', 'FAR0701', 'sku'],
            ],
            ProductIdentifier::query()->orderBy('position_key')->orderBy('type')->get()
                ->map(static fn (ProductIdentifier $r): array => [$r->position_key, $r->type, $r->value, $r->source_field])->all(),
        );
    }

    public function test_deleting_price_list_removes_its_identifiers(): void
    {
        $this->import(self::ROWS, '2026-01');

        PriceList::query()->sole()->delete();

        $this->assertSame(0, ProductIdentifier::query()->count());
    }

    public function test_collapsing_price_lists_moves_identifiers_to_the_kept_entry(): void
    {
        $this->import(self::ROWS, '2026-01');
        $old = PriceList::query()->sole();
        // drugi wpis tego producenta sprzed jednego wpisu na producenta — z tym samym i z własnym identyfikatorem
        $kept = PriceList::query()->create([
            'manufacturer' => 'Bolle', 'manufacturer_key' => 'bolle-2', 'version' => '2026-02', 'rows_total' => 1,
            'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
        ]);
        $card = Product::query()->where('sku', 'TRAC')->sole();
        foreach ([['TRACPSF', 'source_code', 'TRACPSF'], ['TRACPSF', 'ean', '3660740009999']] as [$position, $type, $value]) {
            ProductIdentifier::query()->create([
                'product_id' => $card->id, 'source_key' => 'file:'.$kept->id, 'price_list_id' => $kept->id,
                'position_key' => $position, 'type' => $type, 'value' => $value,
            ]);
        }

        (new ProductIdentifierStore)->moveFileSource((int) $old->id, (int) $kept->id);
        $old->delete();

        // 9 z importu (TRACPSF source_code był też we wpisie docelowym — powtórzenie znika ze starym wpisem)
        // + 2 własne wpisu docelowego
        $this->assertSame(10, ProductIdentifier::query()->count());
        $this->assertSame(0, ProductIdentifier::query()->where('source_key', 'file:'.$old->id)->count());
        $this->assertSame(10, ProductIdentifier::query()->where('source_key', 'file:'.$kept->id)->where('price_list_id', $kept->id)->count());
    }

    /**
     * @param  list<array{0: string, 1: string, 2: float, 3: string, 4: string, 5: string}>  $items  kod, nazwa, cena, EAN, model, rozmiar
     */
    private function import(array $items, string $version): void
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
        $path = tempnam(sys_get_temp_dir(), 'ids').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $result = app(PriceListImportService::class)->importWithMapping(
                new UploadedFile($path, 'bolle.xlsx', null, null, true),
                'Bolle',
                $version,
                User::factory()->create(),
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
        } finally {
            @unlink($path);
        }
    }
}
