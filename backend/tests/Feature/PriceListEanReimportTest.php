<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * EAN karty z cennika: kolejny cennik bez kolumny EAN (albo z pustą komórką) nie kasuje kodu zapisanego wcześniej —
 * brak danych w pliku to brak danych, nie wiadomość, że wyrób EAN stracił. Nowy EAN z pliku nadal nadpisuje stary.
 */
final class PriceListEanReimportTest extends TestCase
{
    use RefreshDatabase;

    public function test_reimport_without_ean_column_keeps_saved_ean(): void
    {
        $this->import([['BAXCSP', 18.55, '3660740007768'], ['TRACPSF', 17.45, '3660740004835']], true, '2026-01');
        $this->assertSame('3660740007768', Product::query()->where('sku', 'BAXCSP')->value('ean'));

        $this->import([['BAXCSP', 19.10, ''], ['TRACPSF', 17.95, '']], false, '2026-02');

        $this->assertSame('3660740007768', Product::query()->where('sku', 'BAXCSP')->value('ean'));
        $this->assertSame('3660740004835', Product::query()->where('sku', 'TRACPSF')->value('ean'));
        $this->assertSame(2, Product::query()->count());
    }

    public function test_reimport_with_empty_ean_cell_keeps_saved_ean_and_new_ean_replaces_old(): void
    {
        $this->import([['BAXCSP', 18.55, '3660740007768'], ['TRACPSF', 17.45, '3660740004835']], true, '2026-01');

        $this->import([['BAXCSP', 19.10, ''], ['TRACPSF', 17.95, '3660740009999']], true, '2026-02');

        $this->assertSame('3660740007768', Product::query()->where('sku', 'BAXCSP')->value('ean'));
        $this->assertSame('3660740009999', Product::query()->where('sku', 'TRACPSF')->value('ean'));
    }

    /**
     * @param  list<array{0: string, 1: float, 2: string}>  $items  kod, cena, EAN
     */
    private function import(array $items, bool $withEanColumn, string $version): void
    {
        $rows = [['SKU', 'Opis produktu', 'Cena EUR', 'EAN unit']];
        foreach ($items as [$sku, $price, $ean]) {
            $rows[] = [$sku, 'Okulary ochronne '.$sku.' soczewki PC powłoka PLATINUM', $price, $ean];
        }
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('INDUSTRIAL');
        // EAN jako tekst — liczba 13-cyfrowa w arkuszu traci zera i zapis
        $sheet->fromArray($rows, null, 'A1', true);
        foreach (array_keys($rows) as $i) {
            $sheet->setCellValueExplicit('D'.($i + 1), (string) $rows[$i][3], DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'ean').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $columns = ['sku' => 0, 'name' => 1, 'catalog_price' => 2];
        if ($withEanColumn) {
            $columns['ean'] = 3;
        }
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
                        'columns' => $columns,
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
