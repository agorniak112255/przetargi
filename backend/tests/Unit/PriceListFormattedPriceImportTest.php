<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Models\User;
use App\Services\PriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class PriceListFormattedPriceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_simple_import_finds_header_below_title_and_parses_zl_prices(): void
    {
        $path = $this->makeBoxmetLikeSpreadsheet();
        $file = new UploadedFile($path, 'cennik-ratownictwo.xlsx', null, null, true);

        try {
            $result = app(PriceListImportService::class)->import(
                $file,
                'BOXMET',
                '2019',
                User::factory()->create(),
            );

            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));
            $this->assertSame(3, $result['created']);

            $first = Product::query()->where('sku', 'ABW164')->first();
            $this->assertNotNull($first);
            $this->assertSame('Apteczka Biurowa', $first->name);
            $this->assertEqualsWithDelta(110.0, (float) $first->catalog_price_net, 0.001);
            $this->assertEqualsWithDelta(20.0, (float) $first->discount_percent, 0.001);
            $this->assertEqualsWithDelta(88.0, (float) $first->purchase_price, 0.001);

            $second = Product::query()->where('sku', 'ABW157')->first();
            $this->assertNotNull($second);
            $this->assertEqualsWithDelta(137.0, (float) $second->catalog_price_net, 0.001);
            $this->assertEqualsWithDelta(109.6, (float) $second->purchase_price, 0.001);

            $thousands = Product::query()->where('sku', 'PPP-S-1950/50/450')->first();
            $this->assertNotNull($thousands);
            $this->assertEqualsWithDelta(5948.75, (float) $thousands->catalog_price_net, 0.001);
            $this->assertEqualsWithDelta(4759.0, (float) $thousands->purchase_price, 0.001);
        } finally {
            @unlink($path);
        }
    }

    public function test_mapping_preview_parses_formatted_zl_prices(): void
    {
        $path = $this->makeBoxmetLikeSpreadsheet();
        try {
            $preview = app(PriceListImportService::class)->previewFromMapping($path, [
                'currency' => 'PLN',
                'notes' => 'test',
                'sheets' => [
                    [
                        'sheet' => 'Arkusz1',
                        'include' => true,
                        'header_excel_row' => 4,
                        'columns' => [
                            'sku' => 0,
                            'name' => 1,
                            'catalog_price' => 4,
                            'discount' => 5,
                            'purchase' => 6,
                        ],
                        'repeating_headers' => false,
                        'confidence' => 1.0,
                    ],
                ],
            ], 8);

            $this->assertSame(3, $preview['products_found']);
            $bySku = [];
            foreach ($preview['products'] as $row) {
                $bySku[$row['sku']] = $row;
            }
            $this->assertEqualsWithDelta(110.0, (float) $bySku['ABW164']['catalog_price_net'], 0.001);
            $this->assertEqualsWithDelta(88.0, (float) $bySku['ABW164']['purchase_price'], 0.001);
        } finally {
            @unlink($path);
        }
    }

    private function makeBoxmetLikeSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Arkusz1');
        $sheet->fromArray([
            ['CENNIK dla DYSTRYBUTORÓW  ważny od 01.04.2019 r.'],
            ['SPRZĘT RATOWNICTWA MEDYCZNEGO'],
            [],
            ['KOD TOWARU', 'NAZWA TOWARU', 'OPIS', 'JM', 'CENA KATALOGOWA NETTO', 'UPUST', 'CENA NETTO PO UPUŚCIE', 'VAT'],
            ['APTECZKI DIN'],
            ['ABW164', 'Apteczka Biurowa', 'walizka ABS', 'kpl.', '  110.00 zł ', '20%', '88.00 zł', '8%'],
            ['ABW157', 'Apteczka przemysłowa', 'walizka', 'kpl.', '137.00 zł', '20%', '109.60 zł', '8%'],
            ['PUNKTY PIERWSZEJ POMOCY'],
            ['PPP-S-1950/50/450', 'SZKOŁA', '', 'kpl.', '  5,948.75 zł ', '20%', '4,759.00 zł', '8%'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'cennik-zl').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
