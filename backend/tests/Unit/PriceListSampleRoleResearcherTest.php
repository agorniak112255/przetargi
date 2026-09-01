<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListSampleRoleResearcher;
use App\Services\PriceListStructureSampler;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class PriceListSampleRoleResearcherTest extends TestCase
{
    public function test_research_detects_model_name_and_article_from_headers(): void
    {
        $path = $this->makeDupontLike();
        try {
            $sample = app(PriceListStructureSampler::class)->sample($path);
            $research = app(PriceListSampleRoleResearcher::class)->research($sample, 'DuPont');

            $this->assertNotNull($research);
            $this->assertSame(2, $research['roles']['model_key']['column']);
            $this->assertSame(4, $research['roles']['name']['column']);
            $this->assertSame(5, $research['roles']['sku']['column']);
            $this->assertSame(6, $research['roles']['packaging']['column']);
            $this->assertSame(9, $research['roles']['catalog_price']['column']);
            $this->assertSame('TD 0125 S WH 00', $research['roles']['model_key']['value']);
            $this->assertSame('NEW! TYVEK Dual Combi', $research['roles']['name']['value']);
            $this->assertSame('D14681379', $research['roles']['sku']['value']);
        } finally {
            @unlink($path);
        }
    }

    public function test_apply_to_mapping_sets_model_key_column(): void
    {
        $researcher = app(PriceListSampleRoleResearcher::class);
        $mapping = [
            'notes' => '',
            'sheets' => [
                [
                    'sheet' => 'Industrial (2)',
                    'include' => true,
                    'header_excel_row' => 2,
                    'columns' => [
                        'sku' => 2,
                        'name' => 4,
                        'catalog_price' => 9,
                        'model_key' => null,
                    ],
                    'confidence' => 0.4,
                ],
            ],
        ];
        $research = [
            'sheet' => 'Industrial (2)',
            'header_excel_row' => 2,
            'sample_excel_row' => 4,
            'confidence' => 0.9,
            'source' => 'heuristic-headers',
            'web_notes' => 'model vs article',
            'roles' => [
                'model_key' => ['column' => 2, 'value' => 'TD 0125 S WH 00', 'meaning' => 'model'],
                'name' => ['column' => 4, 'value' => 'NEW! TYVEK Dual Combi', 'meaning' => 'name'],
                'sku' => ['column' => 5, 'value' => 'D14681379', 'meaning' => 'article'],
                'packaging' => ['column' => 6, 'value' => 'S', 'meaning' => 'size'],
                'catalog_price' => ['column' => 9, 'value' => '2.68', 'meaning' => 'price'],
            ],
        ];

        $out = $researcher->applyToMapping($mapping, $research);
        $this->assertSame(5, $out['sheets'][0]['columns']['sku']);
        $this->assertSame(2, $out['sheets'][0]['columns']['model_key']);
        $this->assertSame(4, $out['sheets'][0]['columns']['name']);
        $this->assertArrayHasKey('sample_role_research', $out);
        $this->assertStringNotContainsString('collaps', (string) ($out['notes'] ?? ''));

        $spam = $researcher->applyToMapping($mapping, [
            ...$research,
            'web_notes' => 'Outlook: https://outlook.com/owa/UrlBlockedError.aspx',
        ]);
        $this->assertStringNotContainsString('outlook', mb_strtolower((string) ($spam['notes'] ?? '')));
    }

    private function makeDupontLike(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Industrial (2)');
        $sheet->fromArray([
            [null, 'Pricelist 2018', null, null, 'Core', null, null, null, null, '10/12/2017'],
            [null, 'Category/Type', 'Reference', 'Product Image', 'Model Name and Description', 'Article Number', 'Size', 'Quantity per box', 'Minimum Order Quantity', 'Price(€/pc.) for Min. of 5000€'],
            [null, 'TYVEK®', null, null, null, null, null, null, null, null],
            ['TD 0125 S WH 00', 'Cat.III', 'TD 0125 S WH 00', null, 'NEW! TYVEK Dual Combi', 'D14681379', 'S', '25', '1600', '2.68'],
            ['TD 0125 S WH 00', null, null, null, 'Long description text for coverall with elasticated wrists and zipper flap for comfort.', 'D14681380', 'M', '25', '1600', '2.68'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'roles').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
