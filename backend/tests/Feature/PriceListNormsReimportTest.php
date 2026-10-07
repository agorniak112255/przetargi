<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CardRedirect;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CardRedirectStore;
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
 * Kolumna products.norms należy do opisu (wzbogacanie, opis B2B) — cennik z pliku jej nie podaje. Ponowny import
 * tego samego cennika zerował ją (pozycja z 'norms' => null szła wprost do update karty): normy znikały z karty,
 * z wyszukiwarki i z wektora, a każdy import zmieniał blob karty, więc reindeks zlecany po imporcie liczył wektor
 * od nowa (B1, plan 07.10.2026).
 */
final class PriceListNormsReimportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
    }

    public function test_spreadsheet_reimport_keeps_norms_from_enrichment(): void
    {
        $items = [['BAXCSP', 18.55, '3660740007768'], ['TRACPSF', 17.45, '3660740004835']];
        $this->importSheet($items, '2026-01');
        $card = Product::query()->where('sku', 'BAXCSP')->sole();
        $this->enrich($card, ['EN 166', 'EN 170']);
        $blob = (string) $card->refresh()->search_blob;

        $this->importSheet($items, '2026-02');

        $card->refresh();
        $this->assertSame('EN 166, EN 170', $card->norms);
        // import tego samego pliku nie zmienia indeksu tekstowego karty — a z nim dokumentu wektora (reindeks
        // zlecany po imporcie bez wymuszenia nie ma czego przeliczać)
        $this->assertSame($blob, (string) $card->search_blob);
    }

    public function test_pdf_reimport_keeps_norms_from_enrichment(): void
    {
        $rows = [['sku' => 'R-100', 'name' => 'Rękawica powlekana nitrylem', 'catalog_price_net' => 12.0, 'purchase_price' => 10.0, 'currency' => 'PLN']];
        $this->importPdf($rows, 'v1');
        $card = Product::query()->where('sku', 'R-100')->sole();
        $this->enrich($card, ['EN 388:2016', 'EN ISO 21420']);

        $rows[0]['catalog_price_net'] = 13.0;
        $this->importPdf($rows, 'v2');

        $this->assertSame('EN 388:2016, EN ISO 21420', $card->refresh()->norms);
    }

    public function test_reimport_through_card_redirect_map_keeps_norms_from_enrichment(): void
    {
        $list = PriceList::query()->create([
            'manufacturer' => 'Bolle', 'manufacturer_key' => PriceList::manufacturerKey('Bolle'), 'version' => '2026-01',
            'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
        ]);
        $card = Product::query()->create([
            // marka pliku: karta innej marki dostaje z pliku tylko puste pola (onlyEmptyCardFields), a tu chodzi o
            // tryb nadpisywania pól karty
            'sku' => 'BOLLE-BAX', 'name' => 'Okulary Bolle BAXTER', 'manufacturer' => 'Bolle',
            'ean' => '1111111111111', 'catalog_price_net' => 9.00, 'purchase_price' => 9.00, 'currency' => 'EUR',
        ]);
        foreach (['BAX-S', 'BAX-M'] as $position) {
            CardRedirect::query()->create([
                'source_key' => 'file:'.$list->id, 'position_key' => $position, 'price_list_id' => $list->id,
                'product_id' => $card->id, 'reason' => CardRedirect::REASON_SIZE_MERGE, 'target_snapshot' => CardRedirectStore::snapshot($card),
            ]);
        }
        $this->enrich($card, ['EN 166', 'EN 172']);

        $result = $this->importSheet([
            ['BAX-S', 18.55, '3660740007768'],
            ['BAX-M', 18.55, '3660740007751'],
        ], '2026-02');

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, Product::query()->count());
        // karta przeszła ścieżką mapy (applyRedirectGroup) w trybie nadpisywania — EAN z pliku zastąpił stary
        $this->assertSame(['BOLLE-BAX'], array_column($result['updated_products'], 'sku'));
        $card->refresh();
        $this->assertSame('3660740007768', $card->ean);
        $this->assertSame('EN 166, EN 172', $card->norms);
    }

    public function test_new_card_from_file_has_no_norms(): void
    {
        $this->importSheet([['BAXCSP', 18.55, '3660740007768']], '2026-01');

        $this->assertNull(Product::query()->where('sku', 'BAXCSP')->value('norms'));
    }

    /**
     * Stan karty po wzbogacaniu: lista norm w enrichment_payload i kolumna jak w writeNormsColumn.
     *
     * @param  list<string>  $norms
     */
    private function enrich(Product $card, array $norms): void
    {
        $card->update([
            'description' => 'Opis wyrobu ze strony producenta.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => ['norms' => $norms],
            'norms' => implode(', ', $norms),
        ]);
    }

    /**
     * @param  list<array{0: string, 1: float, 2: string}>  $items  kod, cena, EAN
     * @return array<string, mixed>
     */
    private function importSheet(array $items, string $version): array
    {
        $rows = [['SKU', 'Opis produktu', 'Cena EUR', 'EAN unit']];
        foreach ($items as [$sku, $price, $ean]) {
            $rows[] = [$sku, 'Okulary ochronne '.$sku.' soczewki PC powłoka PLATINUM', $price, $ean];
        }
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('INDUSTRIAL');
        $sheet->fromArray($rows, null, 'A1', true);
        foreach (array_keys($rows) as $i) {
            $sheet->setCellValueExplicit('D'.($i + 1), (string) $rows[$i][3], DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'norms').'.xlsx';
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
                        'columns' => ['sku' => 0, 'name' => 1, 'catalog_price' => 2, 'ean' => 3],
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

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importPdf(array $rows, string $version): void
    {
        $path = tempnam(sys_get_temp_dir(), 'norms').'.pdf';
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
        } finally {
            @unlink($path);
        }
    }
}
