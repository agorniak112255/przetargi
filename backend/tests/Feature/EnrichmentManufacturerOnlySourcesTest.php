<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Support\BhpAttributeNormalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Zgłoszenie testera (cennik AJ GROUP / PROS, 24.09.2026), to samo życzenie co wcześniej przy MAPA: „dobrze by było,
 * jakby brał info tylko ze strony PROS, w przeciwnym razie mieszają się dane”.
 * - 906 PŁASZCZ MĘSKI: rozmiarówka pomieszana z ogólną listą sklepu behapownia.pl (34…74 i XXS…6XL obok XS/48…4XL/62
 *   producenta), a normy „EN 471 klasa 3, EN 533 indeks 1”, których nie podaje żadna ze stron — zostały w kolumnie
 *   norm po pobraniu z 10.09, bo ponowne pobranie pisało kolumnę tylko wtedy, gdy była pusta.
 * - 106 R: zdjęcie z outlet.pros.pl (pojedyncza sztuka z odzysku na podłodze), nie zdjęcie katalogowe.
 */
final class EnrichmentManufacturerOnlySourcesTest extends TestCase
{
    use RefreshDatabase;

    private const MFR = 'https://bemoregreen.eu/pl/plaszcz/2-plaszcz-meski-906.html';

    private const SHOP = 'https://behapownia.pl/meski-plaszcz-przeciwdeszczowy-bemoregreen-906';

    private const STALE_NORMS = 'EN 471 klasa 3, EN 533 indeks 1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_description_sizes_and_sources_come_only_from_the_manufacturer_card(): void
    {
        $prompts = new \ArrayObject;
        $product = $this->enrich($prompts);

        $extraction = $this->extractionPrompt($prompts);
        $this->assertStringContainsString(self::MFR, $extraction, 'karta producenta idzie do modelu');
        $this->assertStringNotContainsString('behapownia', $extraction, 'strona sklepu nie idzie do modelu');
        $this->assertStringNotContainsString('6XL', $extraction, 'lista rozmiarów sklepu nie trafia do opisu');

        $payload = (array) $product->enrichment_payload;
        $this->assertSame([self::MFR], $payload['source_urls'] ?? null, 'model wskazał też sklep — źródłem zostaje producent');
        $this->assertSame('manufacturer', $payload['primary_source_kind'] ?? null);

        $sizes = mb_strtolower((string) ($payload['attributes']['rozmiar'] ?? ''));
        $this->assertStringNotContainsString('74', $sizes, 'rozmiar tylko z listy sklepu');
        $this->assertStringNotContainsString('6xl', $sizes, 'rozmiar tylko z listy sklepu');
        $this->assertStringNotContainsString('xxs', $sizes, 'rozmiar tylko z listy sklepu');

        $this->assertStringContainsString('tylko strony producenta', json_encode($product->enrichment_trace, JSON_UNESCAPED_UNICODE) ?: '');
        Http::assertNotSent(static fn ($request): bool => str_contains((string) $request->url(), 'outlet.pros.pl'));
    }

    public function test_stale_norms_column_does_not_survive_re_enrichment(): void
    {
        $product = $this->enrich();

        $this->assertNull($product->norms, 'model nie podał norm — kolumna nie trzyma norm z poprzedniego pobrania');
        $normy = implode(' | ', (array) ($product->enrichment_payload['attributes']['normy_en'] ?? []));
        $this->assertStringNotContainsString('471', $normy);
        $this->assertStringNotContainsString('533', $normy);
        $shown = implode(' | ', app(BhpAttributeNormalizer::class)->forDisplay($product)['norms']);
        $this->assertStringNotContainsString('471', $shown, 'karta w panelu nie pokazuje starych norm');
    }

    public function test_norms_column_follows_the_new_norm_list(): void
    {
        $product = $this->enrich(null, ['EN ISO 13688', 'EN 343']);

        $this->assertSame('EN ISO 13688, EN 343', $product->norms);
    }

    public function test_brand_outside_the_list_still_describes_from_manufacturer_and_shops(): void
    {
        config(['enrichment.manufacturer_only_sources' => []]);
        $prompts = new \ArrayObject;
        $product = $this->enrich($prompts);

        $extraction = $this->extractionPrompt($prompts);
        $this->assertStringContainsString(self::MFR, $extraction);
        $this->assertStringContainsString('behapownia', $extraction, 'bez reguły sklep zostaje w puli jak dotąd');
        // strona testowa odtwarza usterkę: bez reguły lista sklepu trafia na kartę (tak powstało „34…74, xxs…6xl”)
        $this->assertStringContainsString('74', (string) ($product->enrichment_payload['attributes']['rozmiar'] ?? ''));
    }

    public function test_shop_link_pinned_by_a_human_stays_in_the_pool(): void
    {
        $prompts = new \ArrayObject;
        $this->enrich($prompts, [], self::SHOP);

        $this->assertStringContainsString('behapownia', $this->extractionPrompt($prompts), 'adres wskazany przez człowieka to jego decyzja');
    }

    public function test_brand_rule_matches_brand_variants_but_not_a_brand_containing_the_word(): void
    {
        $identity = app(ProductSearchIdentity::class);

        $this->assertTrue($identity->usesManufacturerSourcesOnly(new Product(['manufacturer' => 'AJ GROUP', 'sku' => '906', 'name' => 'Płaszcz'])));
        $this->assertTrue($identity->usesManufacturerSourcesOnly(new Product(['manufacturer' => 'PROS', 'sku' => '106 R', 'name' => 'Płaszcz'])));
        $this->assertTrue($identity->usesManufacturerSourcesOnly(new Product(['manufacturer' => 'MAPA Professional', 'sku' => '34650008', 'name' => 'Butoflex 650'])));
        $this->assertFalse($identity->usesManufacturerSourcesOnly(new Product(['manufacturer' => 'DUPONT PROSHIELD', 'sku' => 'PS10', 'name' => 'Kombinezon'])));
        $this->assertFalse($identity->usesManufacturerSourcesOnly(new Product(['manufacturer' => 'Ansell', 'sku' => '11-800', 'name' => 'HyFlex'])));
    }

    public function test_outlet_is_not_a_source_unless_a_human_pinned_it(): void
    {
        $service = app(ProductEnrichmentService::class);
        $drop = new \ReflectionMethod($service, 'dropBlockedSourceHosts');
        $outlet = 'https://outlet.pros.pl/odziez-wodoochronna-ostrzegawcza/108-plaszcz-ostrzegawczy-model-106r.html';
        $card = 'https://pros.pl/pl/odziez-wodoochronna-ostrzegawcza/103-plaszcz-model-106-r.html';
        $rows = [['url' => $outlet], ['url' => $card]];

        $product = new Product(['sku' => '106 R', 'name' => 'Płaszcz wodoochronny ostrzegawczy', 'manufacturer' => 'AJ GROUP']);
        $this->assertSame([$card], array_column($drop->invoke($service, $rows, $product), 'url'));

        $pinned = new Product(['sku' => '106 R', 'name' => 'Płaszcz', 'manufacturer' => 'AJ GROUP', 'shop_source_url' => $outlet]);
        $this->assertSame([$outlet, $card], array_column($drop->invoke($service, $rows, $pinned), 'url'), 'adres wskazany przez człowieka zostaje');
    }

    public function test_merged_second_round_does_not_carry_the_stale_norms_column(): void
    {
        $service = app(ProductEnrichmentService::class);
        $product = new Product(['sku' => '906', 'name' => 'PŁASZCZ MĘSKI', 'manufacturer' => 'AJ GROUP', 'norms' => self::STALE_NORMS]);
        $merge = new \ReflectionMethod($service, 'mergeExtracted');

        $merged = $merge->invoke($service, ['norms' => []], ['norms' => [], 'attributes' => ['kategoria_bhp' => 'odziez']], $product);

        $this->assertStringNotContainsString('471', implode(' | ', (array) ($merged['attributes']['normy_en'] ?? [])));
    }

    /**
     * @param  list<string>  $modelNorms
     */
    private function enrich(?\ArrayObject $prompts = null, array $modelNorms = [], ?string $pinnedUrl = null): Product
    {
        Queue::fake();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = Product::query()->create([
            'sku' => '906',
            'name' => 'PŁASZCZ MĘSKI',
            'manufacturer' => 'AJ GROUP',
            'norms' => self::STALE_NORMS,
            'shop_source_url' => $pinnedUrl,
            'catalog_price_net' => 200,
            'purchase_price' => 150,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        $this->fakeSearch();
        $this->fakeModel($prompts ?? new \ArrayObject, $modelNorms);
        Http::fake([
            self::MFR => Http::response($this->manufacturerPage(), 200),
            self::SHOP => Http::response($this->shopPage(), 200),
            '*' => Http::response('', 404),
        ]);

        $this->postJson("/api/products/{$product->id}/enrich", ['force' => true])->assertOk();

        return $product->fresh();
    }

    private function extractionPrompt(\ArrayObject $prompts): string
    {
        $extraction = collect($prompts->getArrayCopy())->first(fn (string $p): bool => str_contains($p, 'Strony (po filtrze AI)'));
        $this->assertNotNull($extraction, 'wiadomość do modelu z treścią stron');

        return (string) $extraction;
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
                ['url' => self::SHOP, 'title' => 'Męski płaszcz przeciwdeszczowy BeMoreGreen 906', 'snippet' => 'Płaszcz 906 AJ GROUP'],
                ['url' => self::MFR, 'title' => 'PŁASZCZ MĘSKI 906 - BeMoreGreen', 'snippet' => 'Płaszcz męski 906'],
                ['url' => 'https://outlet.pros.pl/plaszcz/906-plaszcz-meski.html', 'title' => 'Płaszcz męski 906', 'snippet' => 'outlet'],
            ],
            'errors' => [],
        ]);
        $this->app->instance(HybridWebSearchService::class, $search);
    }

    /**
     * Model wskazuje oba adresy i nie podaje norm — to kod ma zdecydować o źródłach i o kolumnie norm.
     *
     * @param  list<string>  $norms
     */
    private function fakeModel(\ArrayObject $prompts, array $norms): void
    {
        $handler = static function (array $messages) use ($prompts, $norms): array {
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
                'description' => 'Męski płaszcz przeciwdeszczowy 906 marki AJ GROUP (Be More Green), prosty fason do kolan. '
                    .'Wykonany z lekkiego, recyklingowego materiału Plavitex Eco, miękkiego i przyjemnego w dotyku. '
                    .'Wyposażony w obszerny kaptur ściągany sznurkiem, zapięcie na napy oraz dwie zewnętrzne kieszenie z patkami. '
                    .'Szwy zgrzewane w technologii Solar Welding zwiększają ich wytrzymałość. Zaprojektowany i wykonany w Polsce.',
                'features' => ['kaptur ściągany sznurkiem', 'zapięcie na napy'],
                'specs' => ['Materiał: Plavitex Eco', 'Długość: do kolan'],
                'norms' => $norms,
                'certificates' => [],
                'materials' => ['Plavitex Eco'],
                'use_cases' => ['ochrona przed deszczem'],
                'attributes' => ['kategoria_bhp' => 'odziez', 'normy_en' => $norms],
                'image_urls' => [],
                'source_urls' => [self::MFR, self::SHOP],
                'confidence' => 0.9,
            ];
        };
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->andReturn(['candidates' => []]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    private function manufacturerPage(): string
    {
        $sizes = '';
        foreach (['XS/48', 'S/50', 'M/52', 'L/54', 'XL/56', 'XXL/58', '3XL/60', '4XL/62'] as $i => $size) {
            $sizes .= '<li class="input-container float-left"><input class="input-radio" type="radio" name="group[1]" title="'
                .$size.'" value="'.($i + 2).'"><span class="radio-label">'.$size.'</span></li>';
        }

        return '<!doctype html><html><head><title>Be More Green - PŁASZCZ MĘSKI 906 - BeMoreGreen</title></head><body>'
            .'<h1 class="h1 page-title"><span>PŁASZCZ MĘSKI 906</span></h1>'
            .'<div class="product-description"><p>Prosty płaszcz przeciwdeszczowy do kolan, wykonany z lekkiego, recyklingowego '
            .'i przyjemnego w dotyku materiału Plavitex Eco. Wyposażony w obszerny kaptur ściągany sznurkiem, zapinanie na napy '
            .'oraz dwie zewnętrzne kieszenie przykryte patkami. Ponadczasowy krój i design sprawią, że świetnie sprawdzi się '
            .'również w wersji unisex. Płaszcz wyróżnia się zwiększoną wytrzymałością szwów, którą zapewnia zastosowanie '
            .'technologii Solar Welding. Zaprojektowany i wykonany w Polsce, co gwarantuje najwyższą jakość produktu.</p></div>'
            .'<div class="product-reference"><label>Index: </label><span itemprop="sku">BEMOREGREEN-906-00001</span></div>'
            .'<div class="product-variants"><span class="form-control-label">rozmiar</span><ul id="group_1">'.$sizes.'</ul></div>'
            .'</body></html>';
    }

    private function shopPage(): string
    {
        // lista opcji jak na behapownia.pl: numery 34…74 i litery XXS…6XL w jednym polu „Rozmiar ubrania”
        $options = '';
        foreach ([...range(34, 74, 2), 'XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '5XL', '6XL'] as $i => $size) {
            $options .= '<option value="'.(17000 + $i).'" >'.$size.'</option>';
        }

        return '<!doctype html><html><head><title>Męski płaszcz przeciwdeszczowy BeMoreGreen 906</title></head><body>'
            .'<h1>Męski płaszcz przeciwdeszczowy BeMoreGreen 906</h1>'
            .'<div class="product-description"><p>Męski płaszcz przeciwdeszczowy AJ GROUP BeMoreGreen 906 z tej samej kolekcji. '
            .'Kolor: zielony + biały nadruk. Rozmiar: XS-4XL. Materiał: miękki, przyjemny w dotyku, recyklingowy Plavitex Eco. '
            .'Kaptur ściągany sznurkiem, zapięcie na napy, dwie kieszenie z patkami. Idealny na spacery i wycieczki w deszczowe dni. '
            .'Sprawdź też nasze kurtki ostrzegawcze zgodne z EN ISO 20471 w kategorii odzieży roboczej.</p></div>'
            .'<label>Rozmiar ubrania:</label><div class="stock-options f-grid-6"><div class="option_select option_truestock option_required">'
            .'<select id="option_2893" name="option_2893">'.$options.'</select></div></div>'
            .'</body></html>';
    }
}
