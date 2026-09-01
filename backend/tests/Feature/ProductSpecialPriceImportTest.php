<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSpecialPrice;
use App\Models\User;
use App\Services\PriceListImportService;
use App\Services\ProductSpecialPriceImporter;
use App\Services\SpreadsheetColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class ProductSpecialPriceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_3m_new_prices_and_client_contracts(): void
    {
        $path = $this->makeThreeMLikeSpreadsheet();
        $file = new UploadedFile($path, '3m-supon.xlsx', null, null, true);

        try {
            $result = app(PriceListImportService::class)->importWithMapping(
                $file,
                '3M',
                '2026-05',
                User::factory()->create(),
                app(SpreadsheetColumnMapper::class)->refineMapping($path, [
                    'currency' => 'EUR',
                    'notes' => 'test',
                    'sheets' => [
                        [
                            'sheet' => 'StandardPrice plPL',
                            'include' => true,
                            'header_excel_row' => 1,
                            'columns' => [
                                'sku' => 4,
                                'name' => 6,
                                'catalog_price' => 11,
                                'purchase' => 0,
                                'discount' => 13,
                            ],
                            'repeating_headers' => false,
                            'confidence' => 1.0,
                        ],
                    ],
                ]),
            );

            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));
            $this->assertGreaterThanOrEqual(1, $result['created']);

            $product = Product::query()->where('sku', '532100')->first();
            $this->assertNotNull($product);
            $this->assertEqualsWithDelta(39.85, (float) $product->catalog_price_net, 0.01);
            $this->assertEqualsWithDelta(19.925, (float) $product->purchase_price, 0.01);
            $this->assertEqualsWithDelta(50.0, (float) $product->discount_percent, 0.5);
            $this->assertSame('EUR', $product->currency);

            $special = ProductSpecialPrice::query()
                ->where('product_id', $product->id)
                ->where('client_name', 'ARCELORMITTAL POLAND S.A.')
                ->first();
            $this->assertNotNull($special);
            $this->assertEqualsWithDelta(7.67, (float) $special->price, 0.01);
        } finally {
            @unlink($path);
        }
    }

    private function makeThreeMLikeSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet;
        $standard = $spreadsheet->getActiveSheet();
        $standard->setTitle('StandardPrice plPL');
        $standard->fromArray([
            [
                'Wskaźnik zakupów w ciągu ostatnich 12 miesięcy',
                'Dywizja',
                'Profit Center',
                '3M numer magazynowy',
                'Numer katalogowy produktu',
                'Numer katalogowy Klienta',
                'Nazwa produktu',
                'Kategoria produktu Poziom 1',
                'Kategoria produktu Poziom 2',
                'Kategoria produktu Poziom 3',
                'Waluta',
                'Cena aktualna na dzień',
                'Aktualna cena cennikowa',
                'Aktualna cena po upustach',
                'Data obowiązywania nowej ceny',
                'Nowa cena cennikowa',
                'Nowa cena po upustach',
                'Procentowa zmiana ceny',
                'Cena za',
                'Kod EAN',
            ],
            [
                '',
                'Personal Safety Division',
                '4310',
                '7010043778',
                '532100',
                '',
                'Przednia osłona 3M Speedglas 532100',
                'Środki ochrony indywidualnej',
                'Ochrona spawacza',
                'Akcesoria',
                'EUR',
                '21/04/2026',
                '37,59',
                '18,795',
                '01/05/2026',
                '39,85',
                '19,925',
                '6.01%',
                '1',
                '04046719891924',
            ],
        ]);

        $special = $spreadsheet->createSheet();
        $special->setTitle('Special Pricing plPL');
        $special->fromArray([
            [
                'Numer kontraktu oferty specjalnej',
                'Numer klienta',
                'Nazwa klienta',
                'Adres wysyłki',
                'Nazwa kontraktu oferty specjalnej',
                'Typ',
                'Data rozpoczęcia',
                'Umowa dystrybucyjna',
                '3M numer magazynowy',
                '3M numer magazynowy (stary)',
                'Kod EAN',
                'Nazwa produktu',
                'Waluta',
                'Cena kontraktowa',
            ],
            [
                'C001418824',
                '40997499',
                'ARCELORMITTAL POLAND S.A.',
                '',
                'SUPON + ARCELORMITTAL',
                'Channel Partner',
                '01/05/2026',
                'Safety Accounts',
                '7010043778',
                '532100',
                '04046719891924',
                'Przednia osłona 3M Speedglas 532100',
                'EUR',
                '7,67',
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), '3m-map').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_special_price_importer_ignores_pdf(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($path, "%PDF-1.4\n");
        try {
            $this->assertSame(
                0,
                app(ProductSpecialPriceImporter::class)->importFromPath($path, 'Cofra')
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_pdf_import_from_products_skips_spreadsheet_reader(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cofra').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $file = new UploadedFile($path, 'Cofra akcesoria cennik.pdf', 'application/pdf', null, true);

        try {
            $result = app(PriceListImportService::class)->importFromProducts(
                $file,
                'Cofra',
                '2026-09',
                User::factory()->create(),
                [[
                    'sku' => 'COF-ACC-1',
                    'name' => 'Wkladka Cofra',
                    'catalog_price_net' => 10,
                    'discount_percent' => 17,
                ]],
                null,
                [
                    'groups' => [],
                    'default_discount' => 17.0,
                    'ungrouped_group' => null,
                    'product_assignments' => [],
                ],
            );

            $this->assertNotNull($result['price_list'], implode('; ', $result['errors'] ?? []));
            $this->assertSame(1, $result['created']);
            $this->assertSame(0, $result['special_prices']);
            $product = Product::query()->where('sku', 'COF-ACC-1')->first();
            $this->assertNotNull($product);
            $this->assertSame('Cofra', $product->manufacturer);
            $this->assertEqualsWithDelta(17.0, (float) $product->discount_percent, 0.01);
        } finally {
            @unlink($path);
        }
    }
}
