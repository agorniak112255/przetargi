<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * products:model-groups (etap 2, 08.10.2026): grupy po kluczu modelu, podsumowanie, podejrzane sklejenia (różne strony
 * po zrównaniu wersji językowych), plik CSV (brakujący katalog powstaje), werdykt członków na stronie modelu (--judge)
 * i zdjęcia z kolorem (--images) — niczego nie zmienia.
 */
final class ModelGroupsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://www.coba.com/product/orthomat-standard';

    private const IMAGE = 'https://www.coba.com/wp-content/uploads/AF060001_OrthomatStd_Black.jpg';

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            self::PAGE => Http::response($this->page('Orthomat Standard', ['AF060001', 'AF060002']), 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_out_creates_missing_directory_and_fails_when_the_file_cannot_be_written(): void
    {
        $this->cobaCards();
        // plan zapisuje do storage/app/reports/ — katalogu, którego jeszcze nie ma
        $root = $this->tempDirectory();
        $out = $root.DIRECTORY_SEPARATOR.'reports'.DIRECTORY_SEPARATOR.'coba'.DIRECTORY_SEPARATOR.'grupy.csv';
        $this->assertDirectoryDoesNotExist(dirname($out));

        $this->artisan('products:model-groups', ['--manufacturer' => 'Coba', '--out' => $out])
            ->expectsOutputToContain('Zapisano: '.$out)
            ->assertSuccessful();
        $this->assertFileExists($out);
        $this->assertCount(4, file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

        // „katalog” nadrzędny jest plikiem — bez „Zapisano”, kod wyjścia błędu
        $blocker = $root.DIRECTORY_SEPARATOR.'plik.txt';
        file_put_contents($blocker, 'x');
        $this->artisan('products:model-groups', ['--manufacturer' => 'Coba', '--out' => $blocker.DIRECTORY_SEPARATOR.'grupy.csv'])
            ->expectsOutputToContain('Nie da się zapisać pliku: '.$blocker.DIRECTORY_SEPARATOR.'grupy.csv')
            ->doesntExpectOutputToContain('Zapisano')
            ->assertFailed();
    }

    public function test_suspicious_different_source_pages_ignore_language_versions_of_the_same_page(): void
    {
        // ta sama strona modelu w trzech wersjach językowych — nie „różne strony”
        $this->card('Coba', 'AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)', 'https://www.coba.com/product/orthomat-standard');
        $this->card('Coba', 'AF060002', 'Orthomat Standard Szary 0.9m x 1.5m', 'https://www.coba.com/pl/produkt/orthomat-standard/');
        $this->card('Coba', 'AF060003', 'Orthomat Standard Czarny 0.6m x 0.9m', 'https://www.coba.com/de/produkte/orthomat-standard');
        $this->card('Coba', 'AF010001', 'Orthomat Standard Czarny 0.9m x 1.5m', 'https://www.coba.com/za/product/orthomat-standard');
        // naprawdę różne strony (cudza strona u jednego członka) — sygnał zostaje
        $this->card('Coba', 'SD010701', 'Deckplate Czarny/Żółte krawędzie 0.6m x 0.9m (15mm)', 'https://www.coba.com/pl/produkt/deckplate');
        $this->card('Coba', 'SD0107-6', 'Deckplate Czarny/Żółte krawędzie 0.6m x 18.3m (15mm)', 'https://www.coba.com/pl/produkt/solid-fatigue-step-2');
        $this->card('Coba', 'SD0107-7', 'Deckplate Czarny/Żółte krawędzie 0.9m x 18.3m (15mm)', 'https://www.coba.com/en-gb/product/deckplate');

        // jeden podciąg na linię wyjścia (atrapa dopasowuje zapis do pierwszego oczekiwania): wiersz tabeli grupy
        // Deckplate z powodami, osobno podsumowanie; wiersza Orthomata („| AF ”) w tabeli nie ma
        $this->artisan('products:model-groups', ['--manufacturer' => 'Coba', '--suspicious' => true])
            ->expectsOutputToContain('| Deckplate krawędzie | 3    | SD010701, SD0107-6, SD0107-7 | różne strony źródła członków (2); mieszanka końcówek SKU (C  |')
            ->doesntExpectOutputToContain('| AF ')
            ->expectsOutputToContain('największa grupa: 4 kart („Orthomat Standard”) · podejrzanych w tabeli: 1')
            ->assertSuccessful();
    }

    public function test_groups_cards_by_model_key_with_summary_and_csv(): void
    {
        $this->cobaCards();
        // marka bez profilu grupowania — karta = model, poza tabelą grup
        $this->card('MAPA', 'TITAN-385', 'Rękawice Titan 385 czerwone');
        $out = $this->tempFile('');

        $this->artisan('products:model-groups', ['--manufacturer' => 'Coba', '--out' => $out])
            ->expectsOutputToContain('Kart: 6 · modeli: 3 (grup z kluczem 3, kart bez klucza 0) · pojedynczych: 1 · największa grupa: 3 kart („Orthomat Standard”)')
            ->expectsOutputToContain('Szacunek wywołań modelu językowego przy pełnym pobraniu: 3 (liderzy grup + karty bez klucza) zamiast 6')
            ->expectsOutputToContain('Orthomat Standard')
            ->expectsOutputToContain('mieszanka końcówek SKU')
            ->assertSuccessful();

        $rows = array_map(static fn (string $line): array => str_getcsv($line, ';'), file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $this->assertSame(['Klucz', 'Marka', 'Rodzina', 'Rdzeń', 'Kart', 'Karty', 'SKU', 'Podejrzane', 'Strona', 'hard', 'soft', 'none', '% hard', 'Zdjęcia z kolorem'], array_map(static fn (string $h): string => trim($h, "\xEF\xBB\xBF"), $rows[0]));
        $this->assertCount(4, $rows);
        // największa grupa pierwsza; w kolumnie SKU wszystkie karty grupy
        $this->assertSame(['coba', 'AF', 'Orthomat Standard', '3'], array_slice($rows[1], 1, 4));
        $this->assertSame('AF060001 AF060002 AF060003', $rows[1][6]);
        $this->assertSame('', $rows[1][7]);
        $this->assertSame('Orthomat Premium', $rows[2][3]);
        $this->assertStringContainsString('mieszanka końcówek SKU', $rows[2][7]);

        $this->artisan('products:model-groups', ['--manufacturer' => 'MAPA'])
            ->expectsOutputToContain('Kart: 1 · modeli: 1 (grup z kluczem 0, kart bez klucza 1)')
            ->assertSuccessful();
    }

    public function test_suspicious_and_min_size_filter_the_table(): void
    {
        $this->cobaCards();

        $this->artisan('products:model-groups', ['--manufacturer' => 'Coba', '--suspicious' => true])
            ->expectsOutputToContain('Orthomat Premium')
            ->doesntExpectOutputToContain('Uchwyt typu C')
            ->expectsOutputToContain('podejrzanych w tabeli: 1')
            ->assertSuccessful();

        $this->artisan('products:model-groups', ['--manufacturer' => 'Coba', '--min-size' => 3])
            ->expectsOutputToContain('Orthomat Standard')
            ->doesntExpectOutputToContain('Orthomat Premium')
            ->assertSuccessful();
    }

    public function test_price_list_scope_and_required_filter(): void
    {
        $this->cobaCards();
        $list = PriceList::query()->create([
            'manufacturer' => 'Coba', 'version' => '2026', 'original_filename' => 'coba.xlsx', 'rows_total' => 1,
            'products_created' => 1, 'products_updated' => 0, 'rows_skipped' => 0,
            'product_ids' => Product::query()->where('sku', 'like', 'AF%')->pluck('id')->all(),
        ]);

        $this->artisan('products:model-groups', ['--price-list' => $list->id])
            ->expectsOutputToContain('Kart: 3 · modeli: 1')
            ->assertSuccessful();
        $this->artisan('products:model-groups')
            ->expectsOutputToContain('Podaj --price-list=<numer> albo --manufacturer=<nazwa>')
            ->assertFailed();
        $this->artisan('products:model-groups', ['--price-list' => 999])
            ->expectsOutputToContain('Nie ma cennika 999')
            ->assertFailed();
    }

    public function test_judge_counts_member_verdicts_on_the_most_frequent_source_page_and_lists_coloured_images(): void
    {
        $this->cobaCards();
        // dwie karty ze stroną modelu (kody na stronie), trzecia bez źródła — werdykt trzeciej na tej samej stronie
        foreach (['AF060001', 'AF060002'] as $sku) {
            Product::query()->where('sku', $sku)->update(['enrichment_payload' => json_encode([
                'primary_source_url' => self::PAGE, 'identity' => ['verdict' => 'hard', 'reason' => 'SKU w tabeli części'],
            ])]);
        }

        // AF060003 nie ma kodu na stronie — werdykt bez kodu (reguły werdyktu: SourceIdentityTest), czyli kandydat do przeglądu
        $this->artisan('products:model-groups', ['--manufacturer' => 'Coba', '--judge' => true, '--images' => true, '--limit' => 1])
            ->expectsOutputToContain('Orthomat Standard (3 kart) → '.self::PAGE.': hard 2 · soft 0 · none 1 · zdjęć z kolorem 1')
            ->expectsOutputToContain('| 2/0/1          |')
            ->expectsOutputToContain('Werdykt członków na stronie modelu (1 grup z pobraną stroną, 3 kart): hard 2 (66,7%), soft 0, none 1 — do przeglądu trafiłoby 1 kart.')
            ->assertSuccessful();
    }

    private function cobaCards(): void
    {
        $this->card('Coba', 'AF060001', 'Orthomat Standard Szary 0.6m x 0.9m (9.5mm)');
        $this->card('Coba', 'AF060002', 'Orthomat Standard Szary 0.9m x 1.5m');
        $this->card('Coba', 'AF060003', 'Orthomat Standard Czarny 0.6m x 0.9m');
        // postać sprzedaży na metry („C”) i rolka — ten sam model, mieszanka końcówek do przejrzenia
        $this->card('Coba', 'FF010003C', 'Orthomat Premium Czarny 0.9m x mb.');
        $this->card('Coba', 'FF010003', 'Orthomat Premium Czarny 0.9m x 18.3m');
        $this->card('Coba', 'CCLIP25', 'Akcesoria Krata GRP - Uchwyt typu C - 25mm');
    }

    private function card(string $manufacturer, string $sku, string $name, ?string $sourceUrl = null): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            'enrichment_payload' => $sourceUrl !== null ? ['primary_source_url' => $sourceUrl, 'identity' => ['verdict' => 'hard']] : null,
        ]);
    }

    /** @param  list<string>  $codes */
    private function page(string $name, array $codes): string
    {
        $rows = implode('', array_map(static fn (string $c): string => '<tr><td>'.$c.'</td><td><a data-part="'.$c.'">Zapytaj o cenę</a></td></tr>', $codes));

        return '<html><head><title>'.$name.' - COBA</title><meta property="og:image" content="'.self::IMAGE.'"></head><body><h1>'.$name.'</h1>'
            .'<div class="product-description"><p>'.$name.' to mata antyzmęczeniowa COBA do suchych pomieszczeń przemysłowych.</p>'
            .str_repeat('<p>Izoluje pracowników od zimnej betonowej podłogi i zmniejsza zmęczenie przy pracy stojącej.</p>', 10)
            .'</div><table>'.$rows.'</table></body></html>';
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'groups');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    /** Pusty katalog pod storage/framework/testing (sprzątany w tearDown). */
    private function tempDirectory(): string
    {
        $path = storage_path('framework/testing/model-groups-'.uniqid());
        File::makeDirectory($path, 0755, true);
        $this->directories[] = $path;

        return $path;
    }
}
