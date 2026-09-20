<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ProductEnrichmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Karta AJ GROUP 202 (20.09.2026): producent daje przy wyrobie „Pobierz kartę produktu w pliku PDF”. PDF podaje
 * kolory „w białe paski”, rozmiary do 120/75, tabelę wymiarów i normy; opis z samej strony HTML tego nie niósł,
 * a pliki pobierano dopiero po napisaniu opisu. Treść karty ma być źródłem opisu — z własnym wpisem w źródłach —
 * ale tylko karty tego wyrobu, z hosta producenta.
 */
final class ManufacturerPdfCardSourceTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://pros.pl/pl/fartuchy-wodoochronne/211-fartuch-model-202.html';

    private const CARD = 'https://pros.pl/modules/x13producttopdf/pdf.php?id_product=211&id_product_attribute=3759';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_pdf_card_feeds_the_description_and_is_recorded_as_a_source(): void
    {
        Queue::fake();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->apron();
        $this->fakeSearch();
        $prompts = $this->fakeModel();
        Http::fake([
            self::PAGE => Http::response($this->page(), 200),
            'pros.pl/modules/*' => Http::response($this->pdf('202', '202-00005-75/75'), 200, ['Content-Type' => 'application/pdf']),
            '*' => Http::response('', 404),
        ]);

        $this->postJson("/api/products/{$product->id}/enrich", ['force' => true])->assertOk();

        $seenByModel = implode("\n", $prompts->getArrayCopy());
        $this->assertStringContainsString('Bordowy w białe paski', $seenByModel, 'model dostał treść karty PDF');
        $this->assertStringContainsString('Rozmiar 120 cm 75 cm', $seenByModel, 'tabela rozmiarów przetrwała filtr stron');
        $this->assertStringNotContainsString('rozmiar-75_75', $seenByModel, 'bez adresu wskazującego jeden wariant');

        $payload = (array) $product->fresh()->enrichment_payload;
        $this->assertContains(self::CARD, (array) ($payload['source_urls'] ?? []), 'karta PDF zapisana w źródłach');
        $this->assertSame(self::PAGE, ($payload['source_urls'] ?? [])[0] ?? null, 'pierwszym źródłem zostaje strona wyrobu');

        $documents = ProductDocument::query()->where('product_id', $product->id)->get();
        $this->assertCount(1, $documents);
        $this->assertSame(ProductDocument::KIND_DATASHEET, $documents[0]->kind);
        $this->assertStringContainsString('EN 343', (string) $documents[0]->text);
        $cardRequests = Http::recorded(static fn ($request): bool => str_contains($request->url(), 'x13producttopdf'));
        $this->assertCount(1, $cardRequests, 'plik pobrany raz, choć służy i za źródło opisu, i za dokument');
    }

    public function test_pdf_card_of_another_variant_is_not_a_source(): void
    {
        $product = $this->apron();
        Http::fake(['*' => Http::response($this->pdf('202/A', '202/A-00005-75/75'), 200, ['Content-Type' => 'application/pdf'])]);

        $this->assertSame([], $this->cardPages($product, [self::CARD]));
    }

    public function test_long_pdf_is_a_catalogue_not_a_product_card(): void
    {
        $product = $this->apron();
        $filler = str_repeat('<p>Fartuch model 202 — opis serii wyrobów wodoochronnych dla przetwórstwa spożywczego i gastronomii.</p>', 90);
        Http::fake(['*' => Http::response($this->pdf('202', '202-00005-75/75', $filler), 200, ['Content-Type' => 'application/pdf'])]);

        $this->assertSame([], $this->cardPages($product, [self::CARD]));
    }

    public function test_pdf_card_from_a_shop_is_never_requested(): void
    {
        $product = $this->apron();
        Http::fake(['*' => Http::response($this->pdf('202', '202-00005-75/75'), 200, ['Content-Type' => 'application/pdf'])]);

        $this->assertSame([], $this->cardPages($product, ['https://bogarobhp.pl/modules/x13producttopdf/pdf.php?id_product=9']));
        Http::assertNothingSent();
    }

    public function test_failing_generator_leaves_the_card_without_this_source(): void
    {
        $product = $this->apron();
        Http::fake(['*' => Http::response('Błąd serwera', 500)]);

        $this->assertSame([], $this->cardPages($product, [self::CARD]));
    }

    /**
     * @param  list<string>  $documentUrls
     * @return list<array<string, string>>
     */
    private function cardPages(Product $product, array $documentUrls): array
    {
        $service = app(ProductEnrichmentService::class);

        return (new ReflectionMethod($service, 'manufacturerPdfCardPages'))->invoke($service, $product, $documentUrls);
    }

    private function apron(): Product
    {
        return Product::query()->create([
            'sku' => '202',
            'name' => 'Fartuch wodoochronny 120/75 PU Poliester',
            'manufacturer' => 'AJ GROUP',
            'catalog_price_net' => 48,
            'purchase_price' => 33.6,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);
    }

    private function fakeSearch(): void
    {
        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('dropListingResults')->andReturnUsing(static fn (array $results): array => $results);
        $search->shouldReceive('moreCatalogHits')->andReturn([]);
        $search->shouldReceive('searchMappedRetailers')->andReturn([]);
        $search->shouldReceive('searchWebWithoutLocalIndex')->andReturn(['results' => [], 'images' => [], 'errors' => []]);
        $search->shouldReceive('forgetProductCache');
        $search->shouldReceive('searchBothPhases')->andReturn([
            'results' => [['url' => self::PAGE, 'title' => 'Fartuch model 202', 'snippet' => 'PROS fartuch model 202']],
            'errors' => [],
        ]);
        $this->app->instance(HybridWebSearchService::class, $search);
    }

    /** Atrapa modelu: filtr stron oddaje strony bez zmian, ekstrakcja zwraca gotową kartę; wszystkie prompty zapisane. */
    private function fakeModel(): \ArrayObject
    {
        $prompts = new \ArrayObject;
        $handler = static function (array $messages) use ($prompts): array {
            $user = (string) ($messages[1]['content'] ?? '');
            $prompts->append($user);
            if (str_contains((string) ($messages[0]['content'] ?? ''), 'filtrem treści')) {
                $pages = [];
                foreach ((array) (json_decode($user, true)['pages'] ?? []) as $page) {
                    $pages[] = ['url' => $page['url'] ?? '', 'text' => $page['text'] ?? ''];
                }

                return ['pages' => $pages];
            }

            return [
                'description' => 'Lekki fartuch przedni model 202 z poliestru powlekanego poliuretanem, w kolorach w białe paski. '
                    .'Przeznaczony dla pracowników barów i restauracji, z regulacją paska szyjnego. Zachowuje właściwości w niskich '
                    .'temperaturach. Dostępny w rozmiarach od 75/75 do 120/75.',
                'features' => ['regulowany pasek szyjny'],
                'specs' => ['Rozmiary: 75/75, 100/75, 110/75, 120/75'],
                'norms' => ['EN ISO 13688', 'EN 343'],
                'certificates' => [],
                'materials' => ['poliester powlekany poliuretanem'],
                'use_cases' => ['gastronomia'],
                'image_urls' => [],
                'source_urls' => [self::PAGE],
                'confidence' => 0.9,
            ];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->andReturn(['candidates' => []]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        return $prompts;
    }

    private function page(): string
    {
        return '<!doctype html><html lang="pl"><head><title>Fartuch model 202 - PROS</title></head><body>'
            .'<h1>Fartuch model 202</h1><p>SKU: 202-00005-75/75</p><p>PROS</p>'
            .'<div class="x13producttopdf"><a href="'.htmlspecialchars(self::CARD).'">Pobierz kartę produktu w pliku PDF</a></div>'
            .'<div class="product-description"><p>Elegancki i bardzo lekki fartuch przedni model 202 w ciekawym designie. '
            .'Wykonany z lekkiego, zapewniającego wygodę użytkowania, materiału powleczonego poliuretanem. Przeznaczony '
            .'w szczególności dla pracowników barów i restauracji. Praktyczna regulacja paska szyjnego. Produkt zachowuje '
            .'swoje naturalne właściwości również w niskich temperaturach.</p></div>'
            .str_repeat('<p>Fartuch model 202 PROS — odzież wodoochronna dla gastronomii i przetwórstwa spożywczego.</p>', 12)
            .'</body></html>';
    }

    /** Karta w układzie generatora pros.pl; $extra pozwala zrobić z niej „katalog”. */
    private function pdf(string $model, string $reference, string $extra = ''): string
    {
        return Pdf::loadHTML('<html><head><meta charset="utf-8"><style>body{font-family:"DejaVu Sans";font-size:11px}</style></head><body>'
            .'<p>AJ Group Sp. z o.o. - PROS.pl</p><h2>Fartuch model '.$model.'</h2>'
            .'<p>link do produktu:</p><p>https://pros.pl/pl/fartuchy-wodoochronne/211-3759-fartuch.html#/kolor-czerwony_w_biale_paski/rozmiar-75_75</p>'
            .'<p>Cechy specyficzne:</p><p>- regulowany pasek szyjny</p><p>- odporność na niskie temperatury</p>'
            .'<p>Nr referencyjny: '.$reference.'</p>'
            .'<p>Kolor: Niebieski w białe paski, Granatowy w białe paski, Czerwony w białe paski, Bordowy w białe paski</p>'
            .'<p>Rozmiar: 75/75, 100/75, 110/75, 120/75</p><h3>Opis produktu</h3>'
            .'<p>Elegancki i bardzo lekki fartuch przedni w ciekawym designie. Wykonany z lekkiego materiału powleczonego '
            .'poliuretanem. Przeznaczony w szczególności dla pracowników barów i restauracji.</p>'
            .'<h3>Normy</h3><p>EN ISO 13688</p><p>EN 343</p><p>-50ºC</p>'
            .'<h3>Tabela rozmiarów</h3><p>Rozmiar 75 cm 75 cm</p><p>Rozmiar 100 cm 75 cm</p><p>Rozmiar 120 cm 75 cm</p>'
            .$extra.'</body></html>')->output();
    }
}
