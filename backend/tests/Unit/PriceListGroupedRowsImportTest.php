<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class PriceListGroupedRowsImportTest extends TestCase
{
    public function test_kod_is_model_reference_not_article_number(): void
    {
        $path = $this->makeGroupedSpreadsheet();
        try {
            $service = app(PriceListImportService::class);
            $preview = $service->previewFromMapping($path, $this->dupontMapping(), 20);
            $bySku = [];
            foreach ($preview['products'] as $p) {
                $bySku[$p['sku']] = $p;
            }

            // Kod = model Reference, nie Article D1468…
            $this->assertArrayHasKey('TD 0125 S WH 00', $bySku);
            $this->assertArrayNotHasKey('D14681380', $bySku);
            $this->assertArrayNotHasKey('D14681379', $bySku);
            $this->assertSame('NEW! TYVEK Dual Combi', $bySku['TD 0125 S WH 00']['name']);
            $this->assertNotNull($bySku['TD 0125 S WH 00']['description'] ?? null);
            $this->assertNull($bySku['TD 0125 S WH 00']['packaging']);

            $this->assertArrayHasKey('TK GEVJ T YL 00', $bySku);
            $this->assertEqualsWithDelta(1074.89, (float) $bySku['TK GEVJ T YL 00']['catalog_price_net'], 0.001);
            $this->assertStringContainsString('SCBA', $bySku['TK GEVJ T YL 00']['name']);
        } finally {
            @unlink($path);
        }
    }

    public function test_keeps_variants_when_price_differs_by_size(): void
    {
        $path = $this->makePriceDiffSpreadsheet();
        try {
            $service = app(PriceListImportService::class);
            $preview = $service->previewFromMapping($path, $this->dupontMapping(), 20);
            $skus = array_column($preview['products'], 'sku');

            $this->assertContains('TF CHA5 T GY 00-S', $skus);
            $this->assertContains('TF CHA5 T GY 00-M', $skus);
            $this->assertContains('TF CHA5 T GY 00-L', $skus);
            $this->assertSame(3, $preview['products_found']);
        } finally {
            @unlink($path);
        }
    }

    public function test_ansell_same_price_sizes_collapse_to_one_product(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ansell').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['sku', 'nazwa', 'cena'],
            ['37695VP070', 'AlphaTec 37695VP Size 7.0', 2.85],
            ['37695VP080', 'AlphaTec 37695VP Size 8.0', 2.85],
            ['37695VP100', 'AlphaTec 37695VP Size 10.0', 2.85],
            ['37900VP100', 'AlphaTec 37900VP Size 10.0', 3.96],
        ]);
        (new Xlsx($spreadsheet))->save($path);

        try {
            $service = app(PriceListImportService::class);
            $preview = $service->previewFromMapping($path, $this->flatMapping($spreadsheet->getActiveSheet()->getTitle()), 20);
            $skus = array_column($preview['products'], 'sku');

            $this->assertContains('37695VP', $skus);
            $this->assertNotContains('37695VP070', $skus);
            $this->assertContains('37900VP', $skus);
            $this->assertSame(2, $preview['products_found']);
            $names = array_column($preview['products'], 'name');
            $this->assertContains('AlphaTec 37695VP', $names);
        } finally {
            @unlink($path);
        }
    }

    public function test_ansell_different_price_keeps_each_size(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ansellp').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['sku', 'nazwa', 'cena'],
            ['37695VP070', 'AlphaTec 37695VP Size 7.0', 2.85],
            ['37695VP100', 'AlphaTec 37695VP Size 10.0', 4.10],
        ]);
        (new Xlsx($spreadsheet))->save($path);

        try {
            $service = app(PriceListImportService::class);
            $preview = $service->previewFromMapping($path, $this->flatMapping($spreadsheet->getActiveSheet()->getTitle()), 10);
            $skus = array_column($preview['products'], 'sku');

            $this->assertContains('37695VP-7', $skus);
            $this->assertContains('37695VP-10', $skus);
            $this->assertSame(2, $preview['products_found']);
        } finally {
            @unlink($path);
        }
    }

    public function test_rostaing_tail_size_skus_collapse_by_price(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rostsz').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['sku', 'nazwa', 'cena'],
            ['ALUWELD-DRT07', '1 RIGHT HAND GLOVE T7 WELDER LEATHER BACK ALUMINIUM', 23.51],
            ['ALUWELD-DRT08', '1 RIGHT HAND GLOVE SIZE 8 WELDER LEATHER BACK ALUMINIUM', 23.51],
            ['ALUWELD-DRT11', '1 RIGHT HAND GLOVE T11 WELDER LEATHER ALUMINIUM BACK', 23.51],
            ['ALUWELD-GAT07', '1 LEFT HAND GLOVE T7 WELDER LEATHER ALUMINIUM BACK', 23.51],
            ['ALUWELD-GAT08', '1 LEFT GLOVE T8 WELDER LEATHER ALUMINIUM BACK', 23.51],
            ['ATTACK6PEOM-BT09', 'T9 FIREFIGHTING GLOVES - LEATHER - T LONG TEXTILE', 40.0],
            ['ATTACK6PEOM-BT12', 'T12 FIREFIGHTING GLOVES LEATHER - TEXTILE LONG', 40.0],
            ['ATTACK6PEOMTEX-BSCT06', 'T06 FIREFIGHTING GLOVES TEXTILE LONG', 33.0],
            ['ATTACK6PEOMTEX-BSCT12', 'GLOVES T12 FIREFIGHTING TEXTILE LONG', 33.0],
            ['BLACKSTICK+-SCT06', 'T6 PU CUT RESISTANCE GLOVES WITH LEATHER REINFORCEMENT', 12.0],
            ['BLACKSTICK+-SCT11', 'T11 GLOVES, F CUT RESISTANCE, PU, LEATHER REINFORCEMENT, SC', 12.0],
            ['BLACKSTICK+T', 'T6 GLOVES, F CUT, PU, LEATHER REINFORCEMENT', 12.0],
            ['BLACKTACTIL/0T06', 'T6 GLOVES, CUT RESISTANCE E, BLACK KNITTED', 9.0],
            ['BLACKTACTIL/0T07', 'T7 GLOVES, CUT RESISTANCE E, BLACK KNITTED', 9.0],
        ]);
        (new Xlsx($spreadsheet))->save($path);

        try {
            $preview = app(PriceListImportService::class)->previewFromMapping(
                $path,
                $this->flatMapping($spreadsheet->getActiveSheet()->getTitle()),
                30,
            );
            $skus = array_column($preview['products'], 'sku');

            $this->assertContains('ALUWELD-DRT', $skus);
            $this->assertContains('ALUWELD-GAT', $skus);
            $this->assertContains('ATTACK6PEOM-BT', $skus);
            $this->assertContains('ATTACK6PEOMTEX-BSCT', $skus);
            $this->assertContains('BLACKSTICK+-SCT', $skus);
            $this->assertContains('BLACKTACTIL/0T', $skus);
            $this->assertContains('BLACKSTICK+T', $skus);
            $this->assertNotContains('ALUWELD-DRT07', $skus);
            $this->assertNotContains('BLACKSTICK+-SCT06', $skus);
            $this->assertSame(7, $preview['products_found']);
        } finally {
            @unlink($path);
        }
    }

    public function test_flat_price_list_still_imports(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'flat').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['sku', 'nazwa', 'cena'],
            ['ABC-1', 'Rękawica test', 12.5],
            ['ABC-2', 'Okulary test', 8],
        ]);
        (new Xlsx($spreadsheet))->save($path);

        try {
            $service = app(PriceListImportService::class);
            $mapping = [
                'currency' => 'PLN',
                'sheets' => [
                    [
                        'sheet' => $spreadsheet->getActiveSheet()->getTitle(),
                        'include' => true,
                        'header_excel_row' => 1,
                        'columns' => [
                            'sku' => 0,
                            'name' => 1,
                            'catalog_price' => 2,
                            'discount' => null,
                            'purchase' => null,
                            'pack_qty' => null,
                            'packaging' => null,
                            'currency' => null,
                            'ean' => null,
                            'category' => null,
                        ],
                        'repeating_headers' => false,
                        'confidence' => 1.0,
                    ],
                ],
            ];
            $preview = $service->previewFromMapping($path, $mapping, 10);
            $this->assertSame(2, $preview['products_found']);
            $this->assertSame('ABC-1', $preview['products'][0]['sku']);
            $this->assertSame('Rękawica test', $preview['products'][0]['name']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function flatMapping(string $sheet): array
    {
        return [
            'currency' => 'PLN',
            'sheets' => [
                [
                    'sheet' => $sheet,
                    'include' => true,
                    'header_excel_row' => 1,
                    'columns' => [
                        'sku' => 0,
                        'name' => 1,
                        'catalog_price' => 2,
                        'discount' => null,
                        'purchase' => null,
                        'pack_qty' => null,
                        'packaging' => null,
                        'currency' => null,
                        'ean' => null,
                        'category' => null,
                    ],
                    'repeating_headers' => false,
                    'confidence' => 1.0,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dupontMapping(): array
    {
        return [
            'currency' => 'EUR',
            'sheets' => [
                [
                    'sheet' => 'Industrial (2)',
                    'include' => true,
                    'header_excel_row' => 2,
                    'columns' => [
                        'sku' => 5,
                        'model_key' => 2,
                        'name' => 4,
                        'catalog_price' => 9,
                        'discount' => null,
                        'purchase' => null,
                        'pack_qty' => 7,
                        'packaging' => 6,
                        'currency' => null,
                        'ean' => null,
                        'category' => 1,
                    ],
                    'repeating_headers' => false,
                    'confidence' => 1.0,
                ],
            ],
        ];
    }

    private function makeGroupedSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Industrial (2)');
        $sheet->fromArray([
            [null, 'Pricelist 2018', null, null, 'Core', null, null, null, null, '10/12/2017'],
            [null, 'Category/Type', 'Reference', 'Product Image', 'Model Name and Description', 'Article Number', 'Size', 'Quantity per box', 'Minimum Order Quantity', 'Price(€/pc.) for Min. of 5000€'],
            [null, 'TYVEK®', null, null, null, null, null, null, null, null],
            ['TD 0125 S WH 00', 'Cat.III', 'TD 0125 S WH 00', null, 'NEW! TYVEK Dual Combi', 'D14681379', 'S', '25', '1600', '2.68'],
            ['TD 0125 S WH 00', null, null, null, 'Collared coverall combining Tyvek with a light polypropylene back panel. Elasticated wrists, waist and ankles. Zipper flap. Thumbhole on sleeve.', 'D14681380', 'M', '25', '1600', '2.68'],
            ['TD 0125 S WH 00', null, null, null, null, 'D14681398', 'L', '25', '1600', '2.68'],
            ['TK GEVJ T YL 00', null, null, null, 'For use with SCBA. Attached (detachable) overboots. Attached double gloves (removable). Wide panoramic visor. Boot size available up to size 46.', 'D13495380*', 'M*', '1', 'N/A', '1074.89'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'grouped').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function makePriceDiffSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Industrial (2)');
        $sheet->fromArray([
            [null, 'Pricelist 2018', null, null, 'Core', null, null, null, null, '10/12/2017'],
            [null, 'Category/Type', 'Reference', 'Product Image', 'Model Name and Description', 'Article Number', 'Size', 'Quantity per box', 'Minimum Order Quantity', 'Price(€/pc.) for Min. of 5000€'],
            ['TF CHA5 T GY 00', 'Cat.III', 'TF CHA5 T GY 00', null, 'TYCHEM F Standard - grey', 'D100S', 'S', '25', '400', '15.00'],
            ['TF CHA5 T GY 00', null, null, null, null, 'D100M', 'M', '25', '400', '15.78'],
            ['TF CHA5 T GY 00', null, null, null, null, 'D100L', 'L', '25', '400', '16.50'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pricediff').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
