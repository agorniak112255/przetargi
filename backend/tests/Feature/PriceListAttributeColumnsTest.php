<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PriceListImportService;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Cennik dostawcy wypisuje klasę ochrony, normy i rozmiar we własnych kolumnach. To dokument producenta
 * z datą obowiązywania, więc ma pierwszeństwo przed tym, co model wyczyta ze stron sklepów — dotąd import
 * te kolumny wyrzucał i ta sama informacja była potem zgadywana.
 */
final class PriceListAttributeColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_keeps_attribute_columns_from_the_price_list(): void
    {
        $user = User::factory()->create();
        $path = $this->artraLikeSheet();

        $result = app(PriceListImportService::class)->importWithMapping(
            new UploadedFile($path, 'artra.xlsx', null, null, true),
            'ARTRA',
            'test',
            $user,
            $this->mapping(),
            'obuwie',
        );

        $this->assertSame(1, $result['created']);

        $product = Product::query()->where('sku', 'ARYEL 320 671460 S3L')->firstOrFail();
        $this->assertSame(
            ['klasa_ochrony' => 'S3L', 'rozmiar' => '35-48'],
            $product->price_list_attributes,
        );

        @unlink($path);
    }

    public function test_class_from_the_price_list_wins_over_the_one_read_from_pages(): void
    {
        $product = new Product([
            'sku' => 'ARYEL 320 671460 S3L',
            'name' => 'ARYEL 320 671460 S3L',
            'manufacturer' => 'ARTRA',
            'category' => 'obuwie',
            'description' => 'Obuwie ochronne klasy O1 do lekkich prac.',
            'price_list_attributes' => ['klasa_ochrony' => 'S3L'],
            'enrichment_payload' => ['attributes' => ['klasa_ochrony' => 'O1']],
        ]);

        $attributes = (new BhpAttributeNormalizer)->forProduct($product);

        $this->assertSame('S3L', $attributes['klasa_ochrony']);
    }

    public function test_card_without_price_list_columns_keeps_reading_pages(): void
    {
        $product = new Product([
            'sku' => 'ARYEL 320 671460 S3L',
            'name' => 'ARYEL 320 671460 S3L',
            'manufacturer' => 'ARTRA',
            'category' => 'obuwie',
            'description' => 'Trzewiki ochronne S3L.',
            'enrichment_payload' => ['attributes' => ['klasa_ochrony' => 'S3']],
        ]);

        $attributes = (new BhpAttributeNormalizer)->forProduct($product);

        $this->assertSame('S3L', $attributes['klasa_ochrony']);
    }

    public function test_type_column_is_not_taken_for_the_product_name(): void
    {
        $user = User::factory()->create();
        $path = $this->artraLikeSheet(12);

        // mapowanie jak z analizy: nazwa wskazana na kolumnę rodzaju wyrobu („typ”)
        $mapping = $this->mapping();
        $mapping['sheets'][0]['columns']['name'] = 1;

        app(PriceListImportService::class)->importWithMapping(
            new UploadedFile($path, 'artra.xlsx', null, null, true),
            'ARTRA',
            'test',
            $user,
            $mapping,
            'obuwie',
        );

        // gdyby kolumna „typ” została nazwą, każda karta nazywalaby sie „trzewiki”
        $names = Product::query()->pluck('name')->all();
        $this->assertNotContains('trzewiki', $names);
        $this->assertContains('ARYEL 320 671460 S3L', $names);

        @unlink($path);
    }

    public function test_purchase_price_above_the_catalog_price_is_not_used(): void
    {
        $user = User::factory()->create();
        $path = $this->artraLikeSheet(12, withRetailColumn: true);

        $mapping = $this->mapping();
        // jak w cenniku ARTRY: „zakup” wskazany na cene detaliczna brutto w zlotych obok ceny w euro
        $mapping['sheets'][0]['columns']['purchase'] = 6;

        app(PriceListImportService::class)->importWithMapping(
            new UploadedFile($path, 'artra.xlsx', null, null, true),
            'ARTRA',
            'test',
            $user,
            $mapping,
            'obuwie',
        );

        $product = Product::query()->where('sku', 'ARYEL 320 671460 S3L')->firstOrFail();
        $this->assertEquals(259.0, (float) $product->catalog_price_net);
        $this->assertEquals(259.0, (float) $product->purchase_price);

        @unlink($path);
    }

    public function test_catalogue_index_is_not_taken_for_the_assortment_group(): void
    {
        $user = User::factory()->create();
        $path = $this->artraLikeSheet(12, withCatalogueIndex: true);

        // mapowanie jak z analizy: kategoria wskazana na kolumnę „kat.” — numer pozycji w katalogu
        $mapping = $this->mapping();
        $mapping['sheets'][0]['columns']['category'] = 7;

        app(PriceListImportService::class)->importWithMapping(
            new UploadedFile($path, 'artra.xlsx', null, null, true),
            'ARTRA',
            'test',
            $user,
            $mapping,
            'Obuwie',
        );

        // numer katalogowy nie zostaje grupą; obowiązuje kategoria domyślna podana przy imporcie
        $this->assertSame(['Obuwie'], Product::query()->pluck('category')->unique()->values()->all());

        @unlink($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(): array
    {
        return [
            'manufacturer_detected' => 'ARTRA',
            'currency' => 'PLN',
            'notes' => 'test',
            'sheets' => [
                [
                    'sheet' => 'PL',
                    'include' => true,
                    'header_excel_row' => 1,
                    'columns' => [
                        'sku' => 0,
                        'name' => 0,
                        'catalog_price' => 5,
                        'attr_klasa_ochrony' => 2,
                        'attr_rozmiar' => 4,
                        'attr_kolor' => 3,
                    ],
                    'repeating_headers' => false,
                    'confidence' => 1.0,
                ],
            ],
        ];
    }

    private function artraLikeSheet(int $rows = 1, bool $withRetailColumn = false, bool $withCatalogueIndex = false): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PL');
        $data = [['artykuł', 'typ', 'ochrony', 'kolor', 'rozm.', 'bez VAT', '* NCD z VAT', 'kat.']];
        $data[] = ['ARYEL 320 671460 S3L', 'trzewiki', 'S3L', '', '35-48', 259, 1290, 223];
        // statystyki kolumn potrzebują kilku wierszy, żeby odróżnić nazwę od rodzaju wyrobu
        for ($i = 2; $i <= $rows; $i++) {
            $data[] = ['ARYEL 320 67146'.$i.' S3L', 'trzewiki', 'S3L', '', '35-48', 259 + $i, 1290 + $i, 223 + $i];
        }
        if (! $withCatalogueIndex) {
            $data = array_map(static fn (array $row): array => array_slice($row, 0, 7), $data);
        }
        if (! $withRetailColumn && ! $withCatalogueIndex) {
            $data = array_map(static fn (array $row): array => array_slice($row, 0, 6), $data);
        }
        $sheet->fromArray($data, null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'cennik').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
