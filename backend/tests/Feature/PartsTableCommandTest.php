<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogPage;
use App\Models\ManufacturerPart;
use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Services\Enrichment\DescriptionVersionStore;
use App\Services\Enrichment\PartsTable\CobaPartsTable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * products:parts-table (Coba, 09.10.2026): podgląd bez zapisu z CSV, --apply ze zdjęciem z tabeli i kopią zapasową
 * (drugi przebieg bez zmian), --refresh z catalog_pages na kopiach stron coba.com (strona bez odpowiedzi zachowuje
 * wiersze, ponad 20% stron bez tabeli przerywa bez zapisu).
 */
final class PartsTableCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = 'https://www.coba.com/pl/produkt/';

    /** plik zdjęcia stylu „czarny/żółty” Orthomatu po zdjęciu „-750x750” (ProductImageDownloader::preferFullSizeUrl) */
    private const STYLE_FILE = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2020/02/af-orthomat-standard-workplace-matting-style-safety-2.jpg';

    private string $storage;

    /** @var array<string, string|null> slug => HTML strony (null = brak odpowiedzi) — atrapa coba.com czyta bieżący stan */
    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'parts-table-test-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/app');
        $this->app->useStoragePath($this->storage);
        config(['norms.host_delay_ms' => 0]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_preview_writes_csv_without_touching_the_database(): void
    {
        Http::fake();
        $this->seedParts('orthomat', 'hygimat', 'hygimat-2');
        $exact = $this->card('AF010706', 'Orthomat Standard Czarny/Żółte krawędzie 1.2m x 18.3m (9.5mm)');
        $short = $this->card('AF0107', 'Orthomat Standard Czarny/Żółte krawędzie 1.2m x 18.3m (9.5mm)');
        $family = $this->card('HR0100', 'HR Matting Czarny 0.9m x 1.5m');
        $rejected = $this->card('HYS010001', 'Hygimat 0.6m x 0.9m (17mm) Czarny');
        $this->card('AF010001', 'Inna marka', 'Notrax');
        ProductDescriptionVersion::query()->forceCreate([
            'product_id' => $rejected->id, 'status' => ProductDescriptionVersion::STATUS_REJECTED,
            'origin' => ProductDescriptionVersion::ORIGIN_ENRICHMENT, 'description' => 'Opis z odrzuconej strony.',
            'primary_source_url' => 'https://coba.com/pl/produkt/hygimat/',
            'enrichment_payload' => [DescriptionVersionStore::META_KEY => ['url_blocked' => true]],
        ]);
        $image = ProductImage::query()->create([
            'product_id' => $exact->id, 'path' => 'products/x/icd.jpg', 'source_url' => 'https://icd.pl/orthomat.jpg',
            'is_primary' => true, 'sort_order' => 0, 'checksum' => str_repeat('b', 64),
        ]);
        $partsBefore = ManufacturerPart::query()->count();

        $this->artisan('products:parts-table', ['--brand' => 'coba'])
            ->expectsOutputToContain('Przypięte: 2 z 4 kart')
            ->assertSuccessful();

        $this->assertSame($partsBefore, ManufacturerPart::query()->count());
        $this->assertNotNull($image->fresh());
        $this->assertSame(0, ProductImageRejection::query()->count());
        $csv = $this->csv('coba-*[0-9].csv');
        $this->assertSame('id', ltrim($csv[0][0], "\u{FEFF}"));
        $rows = array_column(array_slice($csv, 1), null, 0);
        $this->assertSame('dokładny', $rows[(string) $exact->id][3]);
        $this->assertSame(self::PREFIX.'orthomat', $rows[(string) $exact->id][4]);
        $this->assertSame('style', $rows[(string) $exact->id][10]);
        $this->assertSame(['1', '1', '0', '1', '0'], array_slice($rows[(string) $exact->id], 13, 5), 'web 1, spoza coba 1, baner 0, do usunięcia 1, B2B 0');
        $this->assertSame('skrót→AF010706', $rows[(string) $short->id][3]);
        $this->assertSame('nierozwiązany: '.CobaPartsTable::REASON_NO_FAMILY, $rows[(string) $family->id][3]);
        $this->assertSame('nierozwiązany: '.CobaPartsTable::REASON_REJECTED_PAGE, $rows[(string) $rejected->id][3]);
        $unresolved = array_column(array_slice($this->csv('coba-*-nierozwiazane.csv'), 1), 3, 0);
        $this->assertSame([(string) $family->id => CobaPartsTable::REASON_NO_FAMILY, (string) $rejected->id => CobaPartsTable::REASON_REJECTED_PAGE], $unresolved);
    }

    public function test_apply_sets_table_image_with_backup_and_is_idempotent(): void
    {
        Http::fake([self::STYLE_FILE => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
        $this->seedParts('orthomat');
        $card = $this->card('AF0107', 'Orthomat Standard Czarny/Żółte krawędzie 1.2m x 18.3m (9.5mm)');
        $untouched = $this->card('HR0100', 'HR Matting Czarny 0.9m x 1.5m');
        foreach ([$card, $untouched] as $product) {
            ProductImage::query()->create([
                'product_id' => $product->id, 'path' => 'products/x/icd-'.$product->id.'.jpg', 'source_url' => 'https://icd.pl/'.$product->id.'.jpg',
                'is_primary' => true, 'sort_order' => 0, 'checksum' => hash('sha256', (string) $product->id),
            ]);
        }

        $this->artisan('products:parts-table', ['--brand' => 'coba', '--apply' => true])
            ->expectsOutputToContain('Zdjęcia: 1 kart ze zdjęciem z tabeli jako głównym')
            ->assertSuccessful();

        $images = ProductImage::query()->where('product_id', $card->id)->get();
        $this->assertCount(1, $images);
        $this->assertSame(self::STYLE_FILE, $images[0]->source_url);
        $this->assertTrue((bool) $images[0]->is_primary);
        $this->assertSame(1, ProductImageRejection::query()->where('product_id', $card->id)->where('reason', ProductImageRejection::REASON_PARTS_TABLE)->count());
        $this->assertSame(1, ProductImage::query()->where('product_id', $untouched->id)->count(), 'karta nierozwiązana bez zmian');
        $backups = glob(storage_path('app/repair-backups/parts-table-images-*.json')) ?: [];
        $this->assertCount(1, $backups);
        $backup = json_decode((string) file_get_contents($backups[0]), true);
        $this->assertSame([(int) $card->id], $backup['product_ids']);
        $this->assertSame('https://icd.pl/'.$card->id.'.jpg', $backup['images'][(string) $card->id][0]['source_url']);

        $this->artisan('products:parts-table', ['--brand' => 'coba', '--apply' => true])
            ->expectsOutputToContain('usunięto 0 zdjęć')
            ->assertSuccessful();
        $this->assertSame([(int) $images[0]->id], ProductImage::query()->where('product_id', $card->id)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, ProductImageRejection::query()->count());
        Http::assertSentCount(1);
    }

    public function test_queue_requires_apply(): void
    {
        $this->artisan('products:parts-table', ['--brand' => 'coba', '--queue' => true])
            ->expectsOutputToContain('--queue działa tylko z --apply')
            ->assertFailed();
    }

    public function test_refresh_stores_rows_from_catalog_pages_and_keeps_rows_of_unanswered_page(): void
    {
        $slugs = ['orthomat', 'hygimat', 'hygimat-2', 'deckstep', 'cablepro-gp'];
        foreach ($slugs as $slug) {
            $this->catalogPage(self::PREFIX.$slug.'/');
        }
        $this->catalogPage('https://www.coba.com/pl/kategoria/maty');
        $this->pages = array_combine($slugs, array_map(fn (string $s): string => $this->fixture($s), $slugs));
        Http::fake(fn ($request) => $this->pageResponse((string) $request->url()));

        $this->artisan('products:parts-table', ['--brand' => 'coba', '--refresh' => true])
            ->expectsOutputToContain('Zapisano 62 wierszy tabeli części z 5 stron')
            ->assertSuccessful();

        $orthomat = ManufacturerPart::query()->where('page_url', self::PREFIX.'orthomat')->get();
        $this->assertCount(18, $orthomat);
        $row = $orthomat->firstWhere('part_code', 'AF010706');
        $this->assertSame('Orthomat® Standard', $row->page_title);
        $this->assertSame('1,2 m x 18,3 m', $row->size_label);
        $this->assertSame(70.0, $row->weight_kg);
        $this->assertTrue($row->has_styles);
        $this->assertSame(ManufacturerPart::hashFor(self::PREFIX.'orthomat'), $row->page_url_hash);
        $this->assertSame(0, ManufacturerPart::query()->where('page_url', 'like', '%kategoria%')->count());

        // druga runda: orthomat bez odpowiedzi (1 z 5 = 20%, nie ponad) — jego wiersze zostają
        $ids = $orthomat->pluck('id')->all();
        $this->pages['orthomat'] = null;
        $this->artisan('products:parts-table', ['--brand' => 'coba', '--refresh' => true])
            ->expectsOutputToContain('bez odpowiedzi 1')
            ->assertSuccessful();
        $this->assertSame($ids, ManufacturerPart::query()->where('page_url', self::PREFIX.'orthomat')->pluck('id')->all());
        $this->assertSame(62, ManufacturerPart::query()->count());
    }

    public function test_refresh_aborts_without_writing_when_over_20_percent_pages_have_no_table(): void
    {
        $slugs = ['orthomat', 'hygimat', 'hygimat-2', 'deckstep', 'cablepro-gp'];
        foreach ($slugs as $slug) {
            $this->catalogPage(self::PREFIX.$slug);
        }
        $this->seedParts('orthomat');
        $before = ManufacturerPart::query()->toBase()->orderBy('id')->pluck('updated_at', 'id')->all();
        $this->pages = array_combine($slugs, array_map(fn (string $s): string => $this->fixture($s), $slugs));
        $this->pages['deckstep'] = '<!doctype html><html><body><h1>DeckStep</h1><p>Strona w przebudowie</p></body></html>';
        $this->pages['cablepro-gp'] = $this->pages['deckstep'];
        Http::fake(fn ($request) => $this->pageResponse((string) $request->url()));

        $this->artisan('products:parts-table', ['--brand' => 'coba', '--refresh' => true])
            ->expectsOutputToContain('przerwane bez zapisu')
            ->assertFailed();

        $this->assertSame($before, ManufacturerPart::query()->toBase()->orderBy('id')->pluck('updated_at', 'id')->all());
    }

    /** Strona z $this->pages; null albo spoza listy = brak odpowiedzi (500). */
    private function pageResponse(string $url): PromiseInterface
    {
        $slug = trim((string) substr($url, strlen(self::PREFIX)), '/');
        $html = $this->pages[$slug] ?? null;

        return $html === null
            ? Http::response('błąd', 500)
            : Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function catalogPage(string $url): void
    {
        CatalogPage::query()->create([
            'host' => 'www.coba.com', 'manufacturer' => 'Coba', 'url_hash' => CatalogPage::hashFor($url), 'url' => $url,
            'title' => null, 'haystack' => $url, 'last_seen_at' => now(),
        ]);
    }

    private function seedParts(string ...$slugs): void
    {
        $parser = app(CobaPartsTable::class);
        foreach ($slugs as $slug) {
            $url = self::PREFIX.$slug;
            $html = $this->fixture($slug);
            $page = $parser->parse($html, $url);
            foreach ($page['rows'] as $row) {
                ManufacturerPart::query()->create([
                    'brand_key' => 'coba', 'page_url' => $url, 'page_url_hash' => ManufacturerPart::hashFor($url),
                    'page_title' => $page['title'], 'part_code' => ManufacturerPart::codeKey($row['part']), 'part_label' => $row['label'],
                    'size_label' => $row['size'], 'colour_label' => $row['colour'], 'weight_kg' => $row['weight_kg'],
                    'model_image_url' => $row['model_image'], 'style_image_url' => $row['style_image'],
                    'has_styles' => $page['has_styles'], 'page_sha' => sha1($html), 'fetched_at' => now(),
                ]);
            }
        }
    }

    private function card(string $sku, string $name, string $manufacturer = 'Coba'): Product
    {
        return Product::query()->create([
            'sku' => $sku, 'name' => $name, 'manufacturer' => $manufacturer,
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 1,
        ]);
    }

    private function fixture(string $slug): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/pages/coba-parts/'.$slug.'.html'));
    }

    /** @return list<list<string>> */
    private function csv(string $pattern): array
    {
        $files = glob(storage_path('app/parts-table/'.$pattern)) ?: [];
        $this->assertCount(1, $files, 'jeden plik '.$pattern);
        $rows = [];
        $handle = fopen($files[0], 'rb');
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $rows[] = array_map('strval', $row);
        }
        fclose($handle);

        return $rows;
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(400, 400);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 30, 30));
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
