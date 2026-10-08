<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductImage;
use App\Models\ProductSourceDocument;
use App\Services\Enrichment\DescriptionVersionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * products:compare-cards (etap 2, 08.10.2026): karty z pomiaru „przed” a stan obecny — źródło (ten sam/inny), werdykt,
 * powód przeglądu, wersja, zdjęcie i zgodność zbioru kolorów z nazwą, liczba źródeł; podsumowanie per grupa; plik CSV
 * (brakujący katalog powstaje) — niczego nie zmienia.
 */
final class CompareCardsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OWN = 'https://www.coba.com/product/orthomat-standard';

    private const FOREIGN = 'https://www.coba.com/pl/produkt/solid-fatigue-step-2';

    private const GREY_IMAGE = 'https://www.coba.com/wp-content/uploads/AF060001_OrthomatStd_Grey.jpg';

    private const BLACK_IMAGE = 'https://www.coba.com/pl/wp-content/uploads/sites/6/2020/02/af-orthomat-standard-workplace-matting-black-1.jpg';

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
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

    public function test_compares_source_verdict_review_version_image_colour_and_sources_per_card_and_group(): void
    {
        $store = app(DescriptionVersionStore::class);
        // 1. opis modelu z własnej strony, zdjęcie w kolorze karty, dwa zapisane źródła
        $same = $this->card('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m', ['primary_source_url' => self::OWN, 'identity' => ['verdict' => 'hard']]);
        $sameVersion = $store->record($same, ProductDescriptionVersion::STATUS_PUBLISHED, ProductDescriptionVersion::ORIGIN_MODEL_SHARED, [
            'description' => (string) $same->description, 'primary_source_url' => self::OWN, 'identity_verdict' => 'hard',
        ]);
        $this->image($same, self::GREY_IMAGE);
        $this->source($same, self::OWN);
        $this->source($same, 'https://www.coba.com/pl/produkt/orthomat-standard');
        // 2. źródło zmienione względem pomiaru, bez wersji — werdykt z payloadu, powód przeglądu, zdjęcie bez koloru
        $changed = $this->card('SD0107-6', 'Deckplate Czarny/Żółte krawędzie 0.6m x 18.3m (15mm)', ['primary_source_url' => 'https://www.coba.com/pl/produkt/deckplate', 'identity' => ['verdict' => 'none']], ['review_reason' => Product::REVIEW_IDENTITY_NONE]);
        $this->image($changed, 'https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/Solid-Fatigue-Step-Leisure_07.jpg');
        // 3. zdjęcie w kolorze sprzecznym z nazwą, karta bez źródła opisu i bez zapisanych źródeł
        $conflict = $this->card('AF060003', 'Orthomat Standard Czarny 0.9m x 1.5m', null);
        $this->image($conflict, self::GREY_IMAGE);
        $csv = $this->tempFile("\xEF\xBB\xBFGrupa;Karta;SKU;Nazwa;Adres źródła opisu;Zdjęcie;Data opisu;Co jest nie tak\n"
            ."2. Zdjęcie;{$same->id};AF060001;Orthomat Standard Szary 0.6m x 0.9m;".self::OWN.';'.self::BLACK_IMAGE.";2026-09-13;zdjęcie czarne\n"
            ."1. Cudza strona — do ponowienia;{$changed->id};SD0107-6;Deckplate Czarny/Żółte krawędzie 0.6m x 18.3m (15mm);".self::FOREIGN.";https://www.coba.com/pl/wp-content/uploads/sites/6/2022/10/Solid-Fatigue-Step-Leisure_07.jpg;2026-09-13;strona maty Solid Fatigue-Step\n"
            ."2. Zdjęcie;{$conflict->id};AF060003;Orthomat Standard Czarny 0.9m x 1.5m;".self::OWN.';'.self::GREY_IMAGE.";2026-09-13;zdjęcie szare\n"
            ."3. Bez źródeł;999999;XX-1;Karta usunięta;;;;\n");
        $out = $this->tempFile('');

        $this->artisan('products:compare-cards', ['--csv' => $csv, '--out' => $out])
            ->expectsOutputToContain("{$same->id} AF060001 → źródło ten sam · werdykt hard · przegląd — · wersja model_shared #{$sameVersion->id} · zdjęcie inne (kolor zgodny) · źródeł 2")
            ->expectsOutputToContain("{$changed->id} SD0107-6 → źródło inny · werdykt none · przegląd identity_none · wersja — · zdjęcie to samo (kolor —) · źródeł 0")
            ->expectsOutputToContain("{$conflict->id} AF060003 → źródło brak · werdykt — · przegląd — · wersja — · zdjęcie to samo (kolor sprzeczny) · źródeł 0")
            ->expectsOutputToContain('999999 XX-1 → źródło karta nie istnieje')
            ->assertSuccessful();

        $rows = array_map(static fn (string $line): array => str_getcsv($line, ';'), file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $this->assertCount(5, $rows);
        $this->assertSame(['Grupa', 'Karta', 'SKU', 'Nazwa', 'Adres źródła przed', 'Adres źródła teraz', 'Adres', 'Werdykt', 'Powód przeglądu',
            'Wersja', 'Zdjęcie przed', 'Zdjęcie teraz', 'Zdjęcie', 'Kolor zdjęcia', 'Kolor w nazwie', 'Kolor', 'Źródeł'], array_map(static fn (string $h): string => trim($h, "\xEF\xBB\xBF"), $rows[0]));
        $this->assertSame(['2. Zdjęcie', (string) $same->id, 'AF060001', 'Orthomat Standard Szary 0.6m x 0.9m', self::OWN, self::OWN, 'ten sam', 'hard', '',
            'model_shared #'.$sameVersion->id, self::BLACK_IMAGE, self::GREY_IMAGE, 'inne', 'grey', 'grey', 'zgodny', '2'], $rows[1]);
        $this->assertSame(['inny', 'none', 'identity_none'], array_slice($rows[2], 6, 3));
        $this->assertSame(['grey', 'black', 'sprzeczny', '0'], array_slice($rows[3], 13, 4));
        $this->assertSame('karta nie istnieje', $rows[4][6]);
        // niczego nie zmienia
        $this->assertSame(Product::REVIEW_IDENTITY_NONE, $changed->fresh()->review_reason);
        $this->assertSame(1, ProductDescriptionVersion::query()->count());
    }

    public function test_colour_match_compares_colour_sets_of_two_colour_cards(): void
    {
        // karta dwubarwna ze zdjęciem jednobarwnym: pierwsze słowo mówiło „zgodny” — zbiór mówi „sprzeczny”
        $wash = $this->card('LM010201', 'COBAwash Czarny/Niebieski 0.6m x 0.85m', null);
        $this->image($wash, 'https://www.coba.com/wp-content/uploads/2020/02/cobawash-black.jpg');
        // równy zbiór w innej kolejności — zgodny
        $tape = $this->card('TP010502', 'COBAtape Biało/Czerwona 50mm x 18.3m', null);
        $this->image($tape, 'https://www.coba.com/wp-content/uploads/2020/02/cobatape-red-white.jpg');
        // karta jednobarwna ze zdjęciem dwubarwnym — sprzeczny
        $white = $this->card('TP010002', 'COBAtape Biała 50mm x 18.3m', null);
        $this->image($white, 'https://www.coba.com/wp-content/uploads/2020/02/cobatape-white-red.jpg');
        $csv = $this->tempFile("Grupa;Karta;SKU;Nazwa;Adres źródła opisu;Zdjęcie\n"
            ."2. Zdjęcie;{$wash->id};LM010201;COBAwash Czarny/Niebieski 0.6m x 0.85m;;\n"
            ."2. Zdjęcie;{$tape->id};TP010502;COBAtape Biało/Czerwona 50mm x 18.3m;;\n"
            ."2. Zdjęcie;{$white->id};TP010002;COBAtape Biała 50mm x 18.3m;;\n");
        $out = $this->tempFile('');

        $this->artisan('products:compare-cards', ['--csv' => $csv, '--out' => $out])
            ->expectsOutputToContain("{$wash->id} LM010201 → źródło — · werdykt — · przegląd — · wersja — · zdjęcie nowe (kolor sprzeczny) · źródeł 0")
            ->expectsOutputToContain("{$tape->id} TP010502 → źródło — · werdykt — · przegląd — · wersja — · zdjęcie nowe (kolor zgodny) · źródeł 0")
            ->expectsOutputToContain("{$white->id} TP010002 → źródło — · werdykt — · przegląd — · wersja — · zdjęcie nowe (kolor sprzeczny) · źródeł 0")
            ->assertSuccessful();

        $rows = array_map(static fn (string $line): array => str_getcsv($line, ';'), file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $this->assertSame(['black', 'black/blue', 'sprzeczny'], array_slice($rows[1], 13, 3));
        $this->assertSame(['red/white', 'white/red', 'zgodny'], array_slice($rows[2], 13, 3));
        $this->assertSame(['white/red', 'white', 'sprzeczny'], array_slice($rows[3], 13, 3));
    }

    public function test_out_creates_missing_directory_and_fails_when_the_file_cannot_be_written(): void
    {
        $card = $this->card('AF060001', 'Orthomat Standard Szary 0.6m x 0.9m', null);
        $csv = $this->tempFile("Grupa;Karta;SKU;Nazwa;Adres źródła opisu;Zdjęcie\n2. Zdjęcie;{$card->id};AF060001;Orthomat Standard Szary 0.6m x 0.9m;;\n");
        // plan zapisuje do storage/app/reports/ — katalogu, którego jeszcze nie ma
        $root = $this->tempDirectory();
        $out = $root.DIRECTORY_SEPARATOR.'reports'.DIRECTORY_SEPARATOR.'porownanie.csv';
        $this->assertDirectoryDoesNotExist(dirname($out));

        $this->artisan('products:compare-cards', ['--csv' => $csv, '--out' => $out])
            ->expectsOutputToContain('Zapisano: '.$out)
            ->assertSuccessful();
        $this->assertFileExists($out);
        $this->assertCount(2, file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

        // „katalog” nadrzędny jest plikiem — bez „Zapisano”, kod wyjścia błędu
        $blocker = $root.DIRECTORY_SEPARATOR.'plik.txt';
        file_put_contents($blocker, 'x');
        $this->artisan('products:compare-cards', ['--csv' => $csv, '--out' => $blocker.DIRECTORY_SEPARATOR.'porownanie.csv'])
            ->expectsOutputToContain('Nie da się zapisać pliku: '.$blocker.DIRECTORY_SEPARATOR.'porownanie.csv')
            ->doesntExpectOutputToContain('Zapisano')
            ->assertFailed();
    }

    public function test_requires_readable_csv_with_known_columns(): void
    {
        $this->artisan('products:compare-cards')->expectsOutputToContain('Podaj --csv=')->assertFailed();
        $this->artisan('products:compare-cards', ['--csv' => $this->tempFile("Grupa;Karta;Nazwa\nA;1;x\n")])
            ->expectsOutputToContain('Brak kolumny „SKU”')
            ->assertFailed();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $attributes
     */
    private function card(string $sku, string $name, ?array $payload, array $attributes = []): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'manufacturer' => 'Coba',
            'description' => 'Mata antyzmęczeniowa '.$name.' do suchych pomieszczeń przemysłowych, pianka PVC.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enrichment_payload' => $payload,
            'catalog_price_net' => 10,
            'purchase_price' => 8,
            'stock' => 0,
            ...$attributes,
        ]);
    }

    private function image(Product $card, string $url): void
    {
        ProductImage::query()->create([
            'product_id' => $card->id, 'path' => 'products/'.$card->id.'/'.basename($url), 'source_url' => $url,
            'checksum' => sha1($url), 'sort_order' => 0, 'is_primary' => true,
        ]);
    }

    private function source(Product $card, string $url): void
    {
        ProductSourceDocument::query()->create([
            'product_id' => $card->id, 'url' => $url, 'url_hash' => ProductSourceDocument::urlHash($url),
            'host' => (string) parse_url($url, PHP_URL_HOST), 'sha256' => hash('sha256', $url), 'chars' => 1200,
            'fetched_at' => now(), 'identity_verdict' => 'hard', 'roles' => ProductSourceDocument::ROLE_DESCRIPTION,
        ]);
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'compare');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    /** Pusty katalog pod storage/framework/testing (sprzątany w tearDown). */
    private function tempDirectory(): string
    {
        $path = storage_path('framework/testing/compare-cards-'.uniqid());
        File::makeDirectory($path, 0755, true);
        $this->directories[] = $path;

        return $path;
    }
}
