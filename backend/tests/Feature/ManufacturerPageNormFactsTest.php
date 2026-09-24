<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Support\BhpAttributeNormalizer;
use App\Support\ManufacturerNormFacts;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * MAPA Butoflex 650 (karta #112, 23.09.2026): opis podawał „EN 388: 1.1.2.2” i „EN 374 (A.B.C.I.K.L)” z cas-technik.eu,
 * a karta producenta mówi „EN 388 → 1121X” i „EN 374-1 → Type A ABCILMNOS”. Trzy przyczyny naraz:
 * - polska karta mapa-pro.pl odpadała z indeksu, bo w adresie nie ma „rękawic” (typ sprawdzany w adresie u producenta),
 * - z karty MAPA do modelu szło ~600 znaków — bez rozwijanych sekcji zalet i zastosowań, bez połowy tabeli parametrów,
 *   za to z kafelkami sąsiednich rękawic,
 * - ramka norm szła płasko („EN 388, 1121X, EN 374-1, Type A, …”), a nic nie dawało producentowi pierwszeństwa.
 */
final class ManufacturerPageNormFactsTest extends TestCase
{
    use RefreshDatabase;

    private const MAPA = 'https://www.mapa-pro.pl/produkty/chemioodporne/strona-produktu/butoflex-650';

    private const SHOP = 'https://cas-technik.eu/hand-protect/chemical-protection-gloves/mapa-butoflex-650-butyl-chemical-protective-gloves/mp-650-7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_mapa_card_text_carries_sections_parameters_and_paired_norms(): void
    {
        Http::fake([self::MAPA => Http::response($this->mapaPage(), 200), '*' => Http::response('', 404)]);

        $fetched = app(ProductPageFetcher::class)->bypassCache()->fetch(
            [['url' => self::MAPA, 'title' => '', 'snippet' => '']],
            '34650008',
            1,
            ['mapa-pro.pl', 'www.mapa-pro.pl'],
            $this->glove(),
        );

        $this->assertCount(1, $fetched['pages'], 'karta producenta przyjęta — treść niesie „rękawice butylowe”');
        $text = (string) $fetched['pages'][0]['text'];
        $this->assertStringContainsString('Grubość (mm) 1.45', $text, 'krótki wiersz tabeli parametrów nie odpada');
        $this->assertStringContainsString('Materiał Butyl', $text);
        $this->assertStringContainsString('rękawice butylowe z wkładem tekstylnym', $text, 'sekcja zalet');
        $this->assertStringContainsString('Pobieranie próbek chemikaliów', $text, 'krótki punkt listy zastosowań');
        $this->assertStringContainsString('EN 388: 1121X', $text);
        $this->assertStringContainsString('EN 374-1: Type A ABCILMNOS', $text);
        $this->assertStringNotContainsString('Ultranitril 485', $text, 'kafelek sąsiedniej rękawicy to nie treść karty');

        $this->assertContains(['label' => 'EN 388', 'value' => '1121X'], $fetched['pages'][0]['norm_facts']);
        $this->assertContains(['label' => 'EN 374-1', 'value' => 'Type A ABCILMNOS'], $fetched['pages'][0]['norm_facts']);
        $this->assertContains(['label' => 'EN 374-5'], $fetched['pages'][0]['norm_facts']);
    }

    public function test_manufacturer_url_without_type_word_passes_when_type_is_checked_on_the_page(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $hay = mb_strtolower(self::MAPA);

        $this->assertFalse($identity->hayMentionsProduct($hay, $this->glove()), 'poza witryną producenta typ nadal w adresie');
        $this->assertTrue($identity->hayMentionsProduct($hay, $this->glove(), true));
        $this->assertFalse(
            $identity->hayMentionsProduct(mb_strtolower('https://www.mapa-pro.pl/produkty/chemioodporne/strona-produktu/butoflex-652'), $this->glove(), true),
            'bez typu w adresie nadal trzeba trafić w model'
        );
    }

    public function test_enrichment_takes_norms_from_the_manufacturer_card_over_the_shop(): void
    {
        $product = $this->enrichWithModelFollowingTheShop();

        $column = $product->manufacturer_norms;
        $this->assertSame(ManufacturerNormFacts::WEB_PAGE_CONNECTOR, $column['source']['connector'] ?? null);
        $this->assertSame(self::MAPA, $column['source']['url'] ?? null);
        $this->assertSame('1121X', $column['en388'] ?? null);

        $norms = (array) ($product->enrichment_payload['norms'] ?? []);
        $this->assertContains('EN 388 1121X', $norms);
        $this->assertContains('EN 374-1 Type A ABCILMNOS', $norms);
        $this->assertContains('EN 374-5', $norms);
        $joined = implode(' | ', $norms).' | '.$product->norms;
        $this->assertStringNotContainsString('1.1.2.2', $joined, 'stary kod EN 388 ze sklepu');
        $this->assertStringNotContainsString('A.B.C.I.K.L', $joined, 'EN 374 ze sklepu przeczy EN 374-1 producenta');

        $this->assertStringContainsString('EN 388 (1121X)', (string) $product->description, 'kod w opisie podmieniony na kod producenta');
        $this->assertStringNotContainsString('1.1.2.2', (string) $product->description);

        $shown = app(BhpAttributeNormalizer::class)->forDisplay($product);
        $this->assertSame('1121X', $shown['attributes']['poziomy_en388'] ?? null, 'karta w panelu pokazuje poziomy producenta');
        $this->assertStringNotContainsString('1.1.2.2', implode(' | ', $shown['norms']));
    }

    public function test_model_is_told_to_prefer_the_manufacturer_norms(): void
    {
        $prompts = new \ArrayObject;
        $this->enrichWithModelFollowingTheShop($prompts);

        $extraction = collect($prompts->getArrayCopy())->first(fn (string $p): bool => str_contains($p, 'Normy z karty producenta'));
        $this->assertNotNull($extraction, 'wiadomość do modelu z normami producenta');
        $this->assertStringContainsString('- EN 388: 1121X', $extraction);
        $this->assertStringContainsString('- EN 374-1: Type A ABCILMNOS', $extraction);
        $this->assertStringContainsString('pierwszeństwo', $extraction);
    }

    public function test_connector_norms_are_not_replaced_by_the_page_frame(): void
    {
        $b2b = ManufacturerNormFacts::build([['label' => 'EN 388', 'value' => '2121X']], 'atg', 'MAPA', 'https://b2b.example/karta');
        $product = $this->enrichWithModelFollowingTheShop(null, $b2b);

        $this->assertSame('atg', $product->manufacturer_norms['source']['connector'] ?? null);
        $this->assertSame('2121X', $product->manufacturer_norms['en388'] ?? null);
    }

    public function test_page_norms_come_only_from_the_card_chosen_as_description_source(): void
    {
        // MAPA jest na liście „tylko producent” (sklep nie wchodzi do puli) — mechanizm sprawdzamy dla marki spoza listy
        config(['enrichment.manufacturer_only_sources' => []]);
        $product = $this->enrichWithModelFollowingTheShop(null, null, [self::SHOP]);

        $this->assertNull($product->manufacturer_norms, 'opis powstał ze sklepu — ramki norm strony producenta z puli nie bierzemy');
    }

    public function test_with_manufacturer_only_rule_the_shop_cannot_become_the_description_source(): void
    {
        $prompts = new \ArrayObject;
        $product = $this->enrichWithModelFollowingTheShop($prompts, null, [self::SHOP]);

        $this->assertSame([self::MAPA], $product->enrichment_payload['source_urls'] ?? null);
        $this->assertSame('manufacturer', $product->enrichment_payload['primary_source_kind'] ?? null);
        $this->assertSame('1121X', $product->manufacturer_norms['en388'] ?? null, 'źródłem jest karta MAPA — jej ramka norm');
        $extraction = collect($prompts->getArrayCopy())->first(fn (string $p): bool => str_contains($p, 'Strony (po filtrze AI)'));
        $this->assertStringNotContainsString('cas-technik', (string) $extraction, 'sklep nie idzie do modelu');
    }

    public function test_description_code_of_another_edition_is_not_replaced(): void
    {
        $product = $this->glove();
        $product->manufacturer_norms = ManufacturerNormFacts::build(
            [['label' => 'EN 388:2016 + A1:2018', 'value' => '4X42C']],
            'atg',
            'MAPA',
            'https://b2b.example/karta',
        );
        $service = app(ProductEnrichmentService::class);
        $align = new \ReflectionMethod($service, 'alignEn388WithManufacturer');

        $description = (string) $align->invoke(
            $service,
            'Rękawice spełniają EN 388:2003 (4542) oraz EN 388:2016 (4131X), a według innego sklepu EN 388 (3131X).',
            $product,
        );

        $this->assertStringContainsString('EN 388:2003 (4542)', $description, 'inne wydanie to inna, prawdziwa wartość');
        $this->assertStringContainsString('EN 388:2016 (4X42C)', $description, 'to samo wydanie — kod producenta');
        $this->assertStringContainsString('EN 388 (4X42C)', $description, 'rok niepodany — rozstrzyga producent');
    }

    private function enrichWithModelFollowingTheShop(?\ArrayObject $prompts = null, ?array $storedNorms = null, ?array $sourceUrls = null): Product
    {
        Queue::fake();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = $this->glove();
        $product->manufacturer_norms = $storedNorms;
        $product->save();
        $this->fakeSearch();
        $this->fakeModel($prompts ?? new \ArrayObject, $sourceUrls ?? [self::MAPA, self::SHOP]);
        Http::fake([
            self::MAPA => Http::response($this->mapaPage(), 200),
            self::SHOP => Http::response($this->shopPage(), 200),
            '*' => Http::response('', 404),
        ]);

        $this->postJson("/api/products/{$product->id}/enrich", ['force' => true])->assertOk();

        return $product->fresh();
    }

    private function glove(): Product
    {
        return Product::query()->firstOrCreate(['sku' => '34650008'], [
            'name' => 'BUTOFLEX 650',
            'manufacturer' => 'MAPA',
            'category' => 'Sklep - kategorie / Rękawice ochronne / Rękawice montażowe / Rękawice do olejów i cieczy',
            'catalog_price_net' => 40,
            'purchase_price' => 30,
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
            'results' => [
                ['url' => self::SHOP, 'title' => 'Mapa Butoflex 650 Butyl chemical protective gloves', 'snippet' => 'Mapa Butoflex 650'],
                ['url' => self::MAPA, 'title' => 'Butoflex 650', 'snippet' => 'Mapa Butoflex 650'],
            ],
            'errors' => [],
        ]);
        $this->app->instance(HybridWebSearchService::class, $search);
    }

    /** Model powtarza zapis ze sklepu — to ma naprawić kod, nie posłuszeństwo modelu. */
    /** @param  list<string>  $sourceUrls */
    private function fakeModel(\ArrayObject $prompts, array $sourceUrls): void
    {
        $handler = static function (array $messages) use ($prompts, $sourceUrls): array {
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
                'description' => 'Rękawice chemiczne Mapa Butoflex 650 wykonane z butylu z wklejoną dzianiną bawełnianą. '
                    .'Zapewniają bardzo wysoką odporność na silnie żrące kwasy, ketony, estry oraz pochodne amin. '
                    .'Normy EN 388 (1.1.2.2) oraz EN 374 (A.B.C.I.K.L) potwierdzają ochronę mechaniczną i chemiczną. '
                    .'Długość rękawicy wynosi 35 cm, a grubość 1,45 mm. Przeznaczone do przemysłu chemicznego i laboratoriów.',
                'features' => ['wklejona dzianina bawełniana'],
                'specs' => ['Długość: 35 cm', 'Grubość: 1,45 mm'],
                'norms' => ['EN 388 (1.1.2.2)', 'EN 374 (A.B.C.I.K.L)', 'EN 374-5'],
                'certificates' => ['Kategoria 3'],
                'materials' => ['butyl'],
                'use_cases' => ['przemysł chemiczny'],
                'image_urls' => [],
                'source_urls' => $sourceUrls,
                'confidence' => 0.9,
            ];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->andReturn(['candidates' => []]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    private function mapaPage(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/enrichment/mapa/butoflex-650-pl.html'));
    }

    private function shopPage(): string
    {
        return '<!doctype html><html><head><title>Mapa Butoflex 650 Butyl chemical protective gloves</title></head><body>'
            .'<h1>Mapa Butoflex 650 Butyl chemical protective gloves</h1>'
            .'<div class="product-detail-description"><p>The Mapa Butoflex 650 has an extraordinary wearing comfort. The first '
            .'butyl glove with glued in inner knit. Highly resistant to highly corrosive acids, ketones, esters and amine '
            .'derivatives. Standard: EN 388 (1.1.2.2), EN 374 (A.B.C.I.K.L). Protection class: Cat. III. Length: approx. 35 cm.</p></div>'
            .'</body></html>';
    }
}
