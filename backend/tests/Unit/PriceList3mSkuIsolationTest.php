<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class PriceList3mSkuIsolationTest extends TestCase
{
    public function test_empty_catalog_uses_warehouse_and_does_not_steal_previous_sku(): void
    {
        $path = $this->write3m([
            ['StandardPrice plPL', [
                ['3M numer magazynowy', 'Numer katalogowy produktu', 'Nazwa produktu', 'Waluta', 'Nowa cena cennikowa', 'Nowa cena po upustach', 'Jednostka sprzedaży'],
                ['7100341171', '50079', 'Dysk Trizact Hookit 466LA', 'EUR', '0,985', '0,6403', 'EA'],
                ['7100349999', '', 'Uniwersalna taśma naprawcza 3M™ 2903, Silver, 1390 mm x 2300 m', 'EUR', '9567,18', '6218,67', 'RO'],
                ['7000029590', '558911', '3M™ Bumpon™ Nakładki ochronne SJ5023 biały, 1000 per case', 'EUR', '0,2396', '0,1557', 'EA'],
                ['', 'N/A', 'Śmieć bez kodu', 'EUR', '12,00', '8,00', 'EA'],
                ['', '.', 'Kolejny śmieć', 'EUR', '9,00', '6,00', 'EA'],
            ]],
            ['ConfigurableMaterial plPL', [
                ['3M numer magazynowy', 'Numer katalogowy produktu', 'Nazwa produktu', 'Waluta', 'Nowa cena cennikowa', 'Nowa cena po upustach', 'Jednostka sprzedaży'],
                ['7100000068', '', 'Taśma 3M™ VHB™ 4941, Config', 'EUR', '182,97', '118,93', 'RO'],
            ]],
        ]);

        try {
            $preview = app(PriceListImportService::class)->previewFromMapping($path, $this->mapping([
                'StandardPrice plPL',
                'ConfigurableMaterial plPL',
            ]), 20);
            $bySku = [];
            foreach ($preview['products'] as $product) {
                $bySku[(string) $product['sku']] = $product;
            }

            $this->assertArrayHasKey('50079', $bySku);
            $this->assertEqualsWithDelta(0.985, (float) $bySku['50079']['catalog_price_net'], 0.001);
            $this->assertStringContainsString('Trizact', (string) $bySku['50079']['name']);

            $this->assertArrayHasKey('7100349999', $bySku);
            $this->assertEqualsWithDelta(9567.18, (float) $bySku['7100349999']['catalog_price_net'], 0.01);

            $this->assertArrayHasKey('558911', $bySku);
            $this->assertArrayNotHasKey('558911-EA', $bySku);
            $this->assertEqualsWithDelta(0.2396, (float) $bySku['558911']['catalog_price_net'], 0.0001);

            $this->assertArrayNotHasKey('N/A', $bySku);
            $this->assertArrayNotHasKey('.', $bySku);
            $this->assertArrayNotHasKey('.-EA', $bySku);
            $this->assertArrayNotHasKey('7100000068', $bySku);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<string>  $sheetNames
     * @return array{sheets: list<array<string, mixed>>}
     */
    private function mapping(array $sheetNames): array
    {
        $sheets = [];
        foreach ($sheetNames as $name) {
            $sheets[] = [
                'sheet' => $name,
                'include' => true,
                'role' => 'catalog',
                'header_excel_row' => 1,
                'columns' => [
                    'sku_alt' => 0,
                    'sku' => 1,
                    'name' => 2,
                    'currency' => 3,
                    'catalog_price' => 4,
                    'purchase' => 5,
                    'packaging' => 6,
                ],
            ];
        }

        return ['sheets' => $sheets, 'currency' => 'EUR'];
    }

    /**
     * @param  list<array{0: string, 1: list<list<string>>}>  $sheets
     */
    private function write3m(array $sheets): string
    {
        $spreadsheet = new Spreadsheet;
        foreach ($sheets as $i => [$title, $rows]) {
            $sheet = $i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($rows);
        }
        $path = tempnam(sys_get_temp_dir(), '3msku').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
