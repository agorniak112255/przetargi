<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SourceIdentityProbeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OWN = 'https://www.coba.com/product/orthomat-standard';

    private const FOREIGN = 'https://www.coba.com/pl/produkt/bubblemat';

    private const SHOP = 'https://sklep.example/maty/mata-sklepowa.html';

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            self::OWN => Http::response($this->page('Orthomat Standard', ['AF060001', 'AF060002']), 200, ['Content-Type' => 'text/html']),
            self::FOREIGN => Http::response($this->page('Bubblemat', ['BF010001', 'BF010002']), 200, ['Content-Type' => 'text/html']),
            // sklep: kod karty tylko w tekście i w przycisku data-part — u producenta to byłoby potwierdzenie, w sklepie nie
            self::SHOP => Http::response($this->page('Mata sklepowa', ['AF060002']), 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_csv_measurement_reports_verdicts_per_group_and_writes_out_file(): void
    {
        $csv = $this->tempFile("\xEF\xBB\xBFGrupa;Karta;SKU;Nazwa;Adres źródła opisu\n"
            .'1. Cudza strona;11055;CCLIP25;Akcesoria Krata GRP - Uchwyt typu C - 25mm;'.self::FOREIGN."\n"
            .'OK;10396;AF060001;Orthomat Standard Szary 0.6m x 0.9m (9.5mm);'.self::OWN."\n"
            .'Sklep;10397;AF060002;Orthomat Standard Szary 0.9m x 1.5m;'.self::SHOP."\n"
            ."OK;10491;FF010003C;Orthomat Premium Czarny 0.9m x mb.;\n");
        $out = $this->tempFile('');

        $this->artisan('products:source-identity-probe', ['--csv' => $csv, '--manufacturer' => 'Coba', '--out' => $out])
            ->expectsOutputToContain('10396 AF060001 → hard')
            ->expectsOutputToContain('11055 CCLIP25 → none')
            ->expectsOutputToContain('10491 FF010003C → null (karta bez źródła opisu)')
            ->expectsOutputToContain('10397 AF060002 → ')
            ->doesntExpectOutputToContain('10397 AF060002 → hard')
            ->assertSuccessful();

        $rows = array_map(static fn (string $line): array => str_getcsv($line, ';'), file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $this->assertCount(5, $rows);
        $this->assertSame(['10396', 'hard', 'sku', 'AF060001', 'markup', 'coba'], [$rows[2][1], $rows[2][5], $rows[2][7], $rows[2][8], $rows[2][9], $rows[2][10]]);
        $this->assertSame(0, Product::query()->count(), 'karty z pliku powstają tylko w pamięci');
    }

    public function test_csv_without_manufacturer_needs_option(): void
    {
        $csv = $this->tempFile("Grupa;Karta;SKU;Nazwa;Adres źródła opisu\nOK;1;AF060001;Orthomat;".self::OWN."\n");

        $this->artisan('products:source-identity-probe', ['--csv' => $csv])
            ->expectsOutputToContain('podaj --manufacturer=')
            ->assertFailed();
    }

    public function test_price_list_cards_are_judged_by_their_primary_source_without_changes(): void
    {
        $own = Product::query()->create([
            'sku' => 'AF060002', 'name' => 'Orthomat Standard Szary 0.9m x 1.5m', 'manufacturer' => 'Coba',
            'enrichment_payload' => ['primary_source_url' => self::OWN, 'primary_source_kind' => 'manufacturer'],
        ]);
        $foreign = Product::query()->create([
            'sku' => 'LCLIP-38', 'name' => 'Akcesoria Krata GRP - Uchwyt typu L - 38mm', 'manufacturer' => 'Coba',
            'enrichment_payload' => ['source_urls' => [self::FOREIGN]],
        ]);
        $list = PriceList::query()->create([
            'original_filename' => 'coba.xlsx', 'manufacturer' => 'Coba', 'version' => '2026',
            'rows_total' => 2, 'products_created' => 2, 'product_ids' => [$own->id, $foreign->id],
        ]);
        $before = Product::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->artisan('products:source-identity-probe', ['--price-list' => $list->id])
            ->expectsOutputToContain($own->id.' AF060002 → hard')
            ->expectsOutputToContain($foreign->id.' LCLIP-38 → none')
            ->assertSuccessful();

        $this->assertSame($before, Product::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    /** @param  list<string>  $codes */
    private function page(string $name, array $codes): string
    {
        $rows = implode('', array_map(static fn (string $c): string => '<tr><td>'.$c.'</td><td><a data-part="'.$c.'">Zapytaj o cenę</a></td></tr>', $codes));

        return '<html><head><title>'.$name.' - COBA</title></head><body><h1>'.$name.'</h1>'
            .'<div class="product-description"><p>'.$name.' to mata antyzmęczeniowa do suchych pomieszczeń przemysłowych.</p>'
            .str_repeat('<p>Izoluje pracowników od zimnej betonowej podłogi i zmniejsza zmęczenie przy pracy stojącej.</p>', 10)
            .'</div><table>'.$rows.'</table></body></html>';
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'probe');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }
}
