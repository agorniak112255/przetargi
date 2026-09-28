<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\B2bAccount;
use App\Models\CatalogPage;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\B2b\B2bSupplementContext;
use App\Services\Enrichment\B2bSourcesDescriptionRejected;
use App\Services\Enrichment\B2bSupplementNoPages;
use App\Services\Enrichment\CatalogIndexSearch;
use App\Services\Enrichment\CatalogSitemapIndexer;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ProductEnrichmentService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Uzupełnianie krótkiego opisu B2B ze stron wskazanych przy koncie (ProductEnrichmentService::supplementB2bDescription,
 * decyzja użytkownika 28.09.2026): najpierw strony konta, potem jak zwykle; źródła zapisane; nic lepszego = opis z B2B
 * zostaje (wyjątek); przy normach producenta kody norm tylko ze źródeł dostawcy.
 */
final class SupplementB2bDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT_HOST = 'konto-sklep.example';

    private const ACCOUNT_URL = 'https://www.konto-sklep.example/rekawice-norvik-kx-2210';

    private const SHOP_URL = 'https://inny-sklep.example/produkt/norvik-kx-2210';

    private const B2B_URL = 'https://b2b.dostawca.example/produkt/kx-2210';

    private const B2B_TEXT = 'Rękawice ochronne Norvik KX-2210 powlekane nitrylem, rozmiary 7–11.';

    private const LONG_DESCRIPTION = 'Rękawice ochronne Norvik KX-2210 z dzianiny poliestrowej, powlekane nitrylem na części chwytnej. '
        ."Mankiet ściągaczowy ułatwia zakładanie.\n\nPowłoka nitrylowa zapewnia pewny chwyt przy pracach montażowych. "
        .'Dostępne rozmiary 7–11.';

    /** @var list<string> wiadomości użytkownika wysłane do modelu opisu */
    private array $prompts = [];

    /** @var array<string, mixed> */
    private array $answer = [];

    /** @var array<string, string> adres => HTML */
    private array $pagesHtml = [];

    /** @var (\Closure(array<string, mixed>): list<array<string, string>>)|null wyniki Tavily dla treści zapytania */
    private ?\Closure $tavily = null;

    /** @var list<array<string, mixed>> treści zapytań wysłanych do Tavily */
    private array $tavilyCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $handler = function (array $messages): array {
            $system = (string) ($messages[0]['content'] ?? '');
            if (str_contains($system, 'filtrem treści')) {
                // filtr stron niedostępny — zostaje surowy tekst stron bez UI sklepu
                throw new RuntimeException('filtr stron niedostępny w teście');
            }
            $this->prompts[] = (string) ($messages[1]['content'] ?? '');

            return $this->answer;
        };
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake(function (Request $request): PromiseInterface {
            if (str_contains($request->url(), 'api.tavily.com') && $this->tavily !== null) {
                $body = $request->data();
                $this->tavilyCalls[] = $body;

                return Http::response(['results' => ($this->tavily)($body)], 200);
            }
            $html = $this->pagesHtml[$request->url()] ?? null;

            return $html !== null
                ? Http::response($html, 200, ['Content-Type' => 'text/html'])
                : Http::response('', 404);
        });
    }

    public function test_account_host_page_is_used_and_cited(): void
    {
        $product = $this->product();
        $this->pagesHtml[self::ACCOUNT_URL] = $this->cardHtml('Mankiet ściągaczowy ułatwia zakładanie. Dzianina poliestrowa.');
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchOnHosts')->once()
            ->with(Mockery::any(), [self::ACCOUNT_HOST])
            ->andReturn([['url' => self::ACCOUNT_URL, 'title' => 'Norvik KX-2210', 'snippet' => '']]));
        $this->answer = $this->answerWith(self::LONG_DESCRIPTION);

        $result = $this->supplement($product);

        $this->assertSame(self::LONG_DESCRIPTION, $result['description']);
        $this->assertSame([self::ACCOUNT_URL], $result['web_source_urls']);
        $this->assertSame([self::B2B_URL, self::ACCOUNT_URL], $result['payload']['source_urls']);
        $this->assertSame(self::ACCOUNT_URL, $result['payload']['primary_source_url']);
        $this->assertSame('b2b_supplement', $result['payload']['primary_source_kind']);
        $this->assertFalse($result['payload']['from_cache']);
        $this->assertCount(1, $this->prompts);
        // źródła dostawcy przed stroną z internetu
        $prompt = $this->prompts[0];
        $this->assertStringContainsString('Opis wyrobu z konta B2B dostawcy', $prompt);
        $this->assertStringContainsString('Mankiet ściągaczowy', $prompt);
        $this->assertLessThan(strpos($prompt, 'Mankiet ściągaczowy'), strpos($prompt, 'powlekane nitrylem, rozmiary'));
        $this->assertStringContainsString('Brak informacji = pomiń', $prompt);
    }

    public function test_pages_of_another_variant_are_skipped(): void
    {
        // produkcja 28.09.2026 (Bolle): TRACPSF dostał strony TRACPSI/TRACPSJ — ten sam model, inny wariant
        $product = $this->product();
        Product::query()->create([
            'sku' => 'KX-2211', 'name' => 'Rękawice ochronne Norvik KX-2211', 'manufacturer' => 'NORVIK',
            'catalog_price_net' => 10, 'purchase_price' => 5, 'currency' => 'PLN',
        ]);
        $other = 'https://www.konto-sklep.example/rekawice-norvik-kx-2211';
        // strona innego wariantu wymienia nasz kod w „podobnych produktach”
        $this->pagesHtml[$other] = '<html><head><title>Rękawice Norvik KX-2211</title></head><body><h1>Rękawice ochronne NORVIK KX-2211</h1>'
            .'<div class="product-description"><p>Rękawice NORVIK z dzianiny, powlekane nitrylem. Podobne produkty: KX-2210.</p><p>'
            .str_repeat('Powłoka nitrylowa na części chwytnej rękawic NORVIK. ', 20).'</p></div></body></html>';
        $familyNoCode = 'https://www.konto-sklep.example/rekawice-norvik-seria-kx';
        $this->pagesHtml[$familyNoCode] = '<html><head><title>Rękawice Norvik seria KX</title></head><body><h1>Rękawice ochronne NORVIK KX</h1>'
            .'<div class="product-description"><p>Rękawice ochronne NORVIK KX-2210 i pokrewne — patrz tabela.</p><p>'
            .str_repeat('Powłoka nitrylowa na części chwytnej rękawic NORVIK. ', 20).'</p></div></body></html>';
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchOnHosts')->once()->andReturn([
            ['url' => $other, 'title' => 'Norvik KX-2211', 'snippet' => ''],
            ['url' => $familyNoCode, 'title' => 'Norvik seria KX', 'snippet' => ''],
        ]));
        $this->answer = $this->answerWith(self::LONG_DESCRIPTION);

        $result = $this->supplement($product);

        // strona serii z naszym kodem w treści i bez cudzego kodu w adresie/tytule zostaje; strona KX-2211 — nie
        $this->assertSame([$familyNoCode], $result['web_source_urls']);
        $this->assertStringNotContainsString('Podobne produkty', $this->prompts[0]);
    }

    public function test_page_without_the_card_code_gives_no_pages(): void
    {
        $product = $this->product();
        $noCode = 'https://www.konto-sklep.example/rekawice-norvik-nitrylowe';
        // model i marka są (bramka tożsamości je przyjmuje), kodu wariantu brak
        $this->pagesHtml[$noCode] = '<html><head><title>Rękawice Norvik nitrylowe</title></head><body><h1>Rękawice ochronne NORVIK KX</h1>'
            .'<div class="product-description"><p>Rękawice NORVIK KX z dzianiny poliestrowej, powlekane nitrylem.</p><p>'
            .str_repeat('Powłoka nitrylowa na części chwytnej rękawic NORVIK KX. ', 20).'</p></div></body></html>';
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchOnHosts')->once()
            ->andReturn([['url' => $noCode, 'title' => 'Norvik KX nitrylowe', 'snippet' => '']]));
        $this->answer = $this->answerWith(self::LONG_DESCRIPTION);

        $this->expectException(B2bSupplementNoPages::class);
        $this->supplement($product);
    }

    public function test_account_datasheet_goes_to_the_model_and_is_cited(): void
    {
        $product = $this->product();
        $account = B2bAccount::query()->create(['username' => 'konto', 'password' => 'haslo', 'sites' => ['dostawca.example'], 'connector' => 'procera']);
        $sheetUrl = 'https://b2b.dostawca.example/pliki/kx-2210.pdf';
        ProductDocument::query()->create([
            'product_id' => $product->id,
            'b2b_account_id' => $account->id,
            'path' => 'products/kx-2210.pdf',
            'source_url' => $sheetUrl,
            'title' => 'Karta techniczna KX-2210',
            'text' => str_repeat('Karta techniczna KX-2210: dzianina poliestrowa 13 gauge, powłoka nitrylowa. ', 6),
            'kind' => ProductDocument::KIND_DATASHEET,
            'sort_order' => 0,
        ]);
        $this->pagesHtml[self::ACCOUNT_URL] = $this->cardHtml('Mankiet ściągaczowy ułatwia zakładanie. Dzianina poliestrowa.');
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchOnHosts')->once()
            ->andReturn([['url' => self::ACCOUNT_URL, 'title' => 'Norvik KX-2210', 'snippet' => '']]));
        $this->answer = $this->answerWith(self::LONG_DESCRIPTION);

        $result = $this->supplement($product, (int) $account->id);

        $this->assertStringContainsString('13 gauge', $this->prompts[0]);
        // pochodzenie: konto B2B, karta katalogowa dostawcy, strona z internetu
        $this->assertSame([self::B2B_URL, $sheetUrl, self::ACCOUNT_URL], $result['payload']['source_urls']);
        $this->assertSame([self::ACCOUNT_URL], $result['web_source_urls']);
    }

    public function test_account_host_page_goes_before_other_shop_pages_from_the_usual_search(): void
    {
        $product = $this->product();
        $this->pagesHtml[self::SHOP_URL] = $this->cardHtml('Sklep: rękawice robocze z dzianiny, powłoka nitrylowa.');
        $this->pagesHtml[self::ACCOUNT_URL] = $this->cardHtml('Mankiet ściągaczowy ułatwia zakładanie. Dzianina poliestrowa.');
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchBothPhases')->once()->andReturn([
            'results' => [
                ['url' => self::SHOP_URL, 'title' => 'Norvik KX-2210', 'snippet' => ''],
                ['url' => self::ACCOUNT_URL, 'title' => 'Norvik KX-2210', 'snippet' => ''],
            ],
            'errors' => [],
        ]));
        $this->answer = $this->answerWith(self::LONG_DESCRIPTION);

        $result = $this->supplement($product);

        $this->assertSame([self::ACCOUNT_URL, self::SHOP_URL], $result['web_source_urls']);
        $this->assertSame(self::ACCOUNT_URL, $result['payload']['primary_source_url']);
        $this->assertLessThan(strpos($this->prompts[0], 'Sklep: rękawice'), strpos($this->prompts[0], 'Mankiet ściągaczowy'));
    }

    public function test_no_confirmed_page_throws_no_pages_and_skips_the_model(): void
    {
        $product = $this->product();
        // strona bez kodu i marki wyrobu (w adresie, tytule i treści) nie przechodzi bramki tożsamości
        $generic = 'https://inny-sklep.example/produkt/rekawice-robocze-123';
        $this->pagesHtml[$generic] = '<html><head><title>Rękawice robocze</title></head><body><h1>Rękawice robocze</h1><p>'
            .str_repeat('Rękawice robocze z dzianiny, różne modele i rozmiary. ', 20).'</p></body></html>';
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchBothPhases')->andReturn([
            'results' => [['url' => $generic, 'title' => 'Rękawice robocze', 'snippet' => '']],
            'errors' => [],
        ]));

        try {
            $this->supplement($product);
            $this->fail('oczekiwano B2bSupplementNoPages');
        } catch (B2bSupplementNoPages $e) {
            $this->assertStringContainsString('brak potwierdzonej strony', $e->getMessage());
        }
        $this->assertSame([], $this->prompts);
    }

    public function test_b2b_link_itself_is_not_a_web_page(): void
    {
        $product = $this->product();
        $this->pagesHtml[self::B2B_URL] = $this->cardHtml('Strona dostawcy B2B.');
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchOnHosts')
            ->andReturn([['url' => self::B2B_URL, 'title' => 'Norvik KX-2210', 'snippet' => '']]));

        $this->expectException(B2bSupplementNoPages::class);
        $this->supplement($product);
    }

    public function test_description_not_longer_than_b2b_text_is_rejected(): void
    {
        $product = $this->product();
        $this->pagesHtml[self::ACCOUNT_URL] = $this->cardHtml('Mankiet ściągaczowy ułatwia zakładanie.');
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchOnHosts')
            ->andReturn([['url' => self::ACCOUNT_URL, 'title' => 'Norvik KX-2210', 'snippet' => '']]));
        $this->answer = $this->answerWith('Rękawice ochronne Norvik KX-2210 powlekane nitrylem.');

        try {
            $this->supplement($product);
            $this->fail('oczekiwano odrzucenia');
        } catch (B2bSupplementNoPages) {
            $this->fail('to nie jest brak stron');
        } catch (B2bSourcesDescriptionRejected $e) {
            $this->assertStringContainsString('nie dłuższy niż opis z B2B', $e->getMessage());
        }
    }

    public function test_norm_code_only_on_shop_page_is_rejected_when_card_has_manufacturer_norms(): void
    {
        $product = $this->product();
        $product->update(['manufacturer_norms' => [
            'source' => ['connector' => 'strona-producenta', 'brand' => 'NORVIK', 'url' => 'https://norvik.example/kx-2210', 'synced_at' => now()->toIso8601String()],
            'rows' => [['label' => 'EN ISO 21420:2020']],
            'normy_en' => ['EN ISO 21420:2020'],
        ]]);
        $this->pagesHtml[self::ACCOUNT_URL] = $this->cardHtml('Norma EN 388:2016 4131X. Mankiet ściągaczowy.');
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchOnHosts')
            ->andReturn([['url' => self::ACCOUNT_URL, 'title' => 'Norvik KX-2210', 'snippet' => '']]));
        $this->answer = $this->answerWith(self::LONG_DESCRIPTION.' Zgodne z EN 388:2016 4131X.');

        try {
            $this->supplement($product->refresh());
            $this->fail('oczekiwano odrzucenia');
        } catch (B2bSourcesDescriptionRejected $e) {
            $this->assertNotInstanceOf(B2bSupplementNoPages::class, $e);
            $this->assertStringContainsString('spoza źródeł dostawcy', $e->getMessage());
            $this->assertStringContainsString('4131X', $e->getMessage());
        }
        // normy producenta idą do modelu w tekście dostawcy razem z zakazem norm ze stron z internetu
        $this->assertStringContainsString('EN ISO 21420:2020', $this->prompts[0]);
        $this->assertStringContainsString('normy i poziomy podane tylko na stronach z internetu pomiń', $this->prompts[0]);
    }

    public function test_norm_code_from_shop_page_is_accepted_without_manufacturer_norms(): void
    {
        $product = $this->product();
        $this->pagesHtml[self::ACCOUNT_URL] = $this->cardHtml('Norma EN 388:2016 4131X. Mankiet ściągaczowy.');
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchOnHosts')
            ->andReturn([['url' => self::ACCOUNT_URL, 'title' => 'Norvik KX-2210', 'snippet' => '']]));
        $this->answer = $this->answerWith(self::LONG_DESCRIPTION.' Zgodne z EN 388:2016 4131X.', norms: ['EN 388:2016 4131X']);

        $result = $this->supplement($product);

        $this->assertStringContainsString('EN 388:2016 4131X', $result['description']);
        $this->assertSame('EN 388:2016 4131X', $result['norms']);
    }

    public function test_unsupported_claim_is_dropped_from_description_and_lists(): void
    {
        $product = $this->product();
        $this->pagesHtml[self::ACCOUNT_URL] = $this->cardHtml('Mankiet ściągaczowy ułatwia zakładanie. Dzianina poliestrowa.');
        $this->search(fn (MockInterface $search) => $search->shouldReceive('searchOnHosts')
            ->andReturn([['url' => self::ACCOUNT_URL, 'title' => 'Norvik KX-2210', 'snippet' => '']]));
        $this->answer = $this->answerWith(
            self::LONG_DESCRIPTION.' Rękawice są wodoodporne i nie przemakają.',
            features: ['wodoodporna powłoka', 'mankiet ściągaczowy'],
        );

        $result = $this->supplement($product);

        $this->assertStringNotContainsString('wodoodporn', $result['description']);
        $this->assertStringContainsString('Mankiet ściągaczowy ułatwia zakładanie.', $result['description']);
        $this->assertSame(['mankiet ściągaczowy'], $result['payload']['features']);
        $this->assertNotEmpty(array_filter($result['dropped_claims'], static fn (string $c): bool => str_contains($c, 'wodoodporne')));
    }

    public function test_catalog_index_only_hosts_filters_before_the_candidate_pool_is_cut(): void
    {
        $now = now();
        $rows = [];
        for ($i = 1; $i <= 405; $i++) {
            $url = 'https://duzy-sklep.example/rekawice-kx-2210-wariant-'.$i;
            $rows[] = [
                'host' => 'duzy-sklep.example', 'manufacturer' => null, 'url_hash' => CatalogPage::hashFor($url),
                'url' => $url, 'title' => null, 'haystack' => $url, 'last_seen_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $accountUrl = 'https://www.'.self::ACCOUNT_HOST.'/rekawice-kx-2210';
        $rows[] = [
            'host' => 'www.'.self::ACCOUNT_HOST, 'manufacturer' => null, 'url_hash' => CatalogPage::hashFor($accountUrl),
            'url' => $accountUrl, 'title' => null, 'haystack' => $accountUrl, 'last_seen_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('catalog_pages')->insert($chunk);
        }
        app(CatalogSitemapIndexer::class)->storeTokens(array_column($rows, 'url_hash'));
        $product = new Product(['sku' => 'KX-2210', 'name' => 'Rękawice KX-2210', 'manufacturer' => 'NORVIK']);
        $search = app(CatalogIndexSearch::class);

        $everywhere = array_column($search->findFor($product), 'url');
        $onAccount = array_column($search->findFor($product, [], [self::ACCOUNT_HOST]), 'url');

        $this->assertNotContains($accountUrl, $everywhere, 'bez filtra strona konta nie mieści się w puli kandydatów');
        $this->assertSame([$accountUrl], $onAccount);
        $this->assertSame([], $search->findFor($product, [], ['  ']));
        // kolejne wywołanie bez filtra nie dziedziczy hostów
        $this->assertNotContains($accountUrl, array_column($search->findFor($product), 'url'));
    }

    public function test_search_on_hosts_asks_each_host_and_keeps_only_those_hosts(): void
    {
        $this->useTavily();
        $this->tavily = static fn (array $body): array => [
            ['url' => 'https://obcy.example/norvik-kx-2210', 'title' => 'Norvik KX-2210', 'content' => 'Norvik KX-2210'],
            ['url' => 'https://'.$body['include_domains'][0].'/katalog/norvik-kx-2210-rekawice', 'title' => 'Norvik KX-2210 rękawice', 'content' => 'Norvik KX-2210'],
        ];
        $product = new Product(['sku' => 'KX-2210', 'name' => 'Rękawice Norvik KX-2210', 'manufacturer' => 'NORVIK']);

        $results = app(HybridWebSearchService::class)->searchOnHosts($product, ['https://www.pierwszy.example/sklep', 'drugi.example']);

        // host z kartą z kodem po frazie nie dostaje już zapytania o kod — jedno zapytanie na host
        $this->assertCount(2, $this->tavilyCalls);
        $this->assertStringStartsWith('site:pierwszy.example ', $this->tavilyCalls[0]['query']);
        $this->assertSame(['pierwszy.example'], $this->tavilyCalls[0]['include_domains']);
        $this->assertStringStartsWith('site:drugi.example ', $this->tavilyCalls[1]['query']);
        $this->assertSame(['drugi.example'], $this->tavilyCalls[1]['include_domains']);
        $this->assertSame([
            'https://pierwszy.example/katalog/norvik-kx-2210-rekawice',
            'https://drugi.example/katalog/norvik-kx-2210-rekawice',
        ], array_column($results, 'url'));
    }

    public function test_search_on_hosts_sends_code_query_when_phrase_finds_nothing(): void
    {
        $this->useTavily();
        $this->tavily = static fn (array $body): array => [];
        $product = new Product(['sku' => 'KX-2210', 'name' => 'Rękawice Norvik KX-2210', 'manufacturer' => 'NORVIK']);

        $this->assertSame([], app(HybridWebSearchService::class)->searchOnHosts($product, ['jeden.example']));

        $queries = array_column($this->tavilyCalls, 'query');
        $this->assertCount(2, $queries);
        $this->assertStringStartsWith('site:jeden.example ', $queries[0]);
        $this->assertSame('site:jeden.example "KX-2210"', $queries[1]);
    }

    private function useTavily(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'model' => 'test',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'search_engine' => 'tavily',
            'tavily_api_key' => 'tvly-test',
            'web_search_enabled' => false,
        ]);
    }

    private function product(): Product
    {
        return Product::query()->create([
            'sku' => 'KX-2210',
            'name' => 'Rękawice ochronne Norvik KX-2210',
            'manufacturer' => 'NORVIK',
            'description' => self::B2B_TEXT,
            'shop_source_url' => self::B2B_URL,
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'currency' => 'PLN',
        ]);
    }

    /**
     * @param  callable(MockInterface): mixed  $configure
     */
    private function search(callable $configure): void
    {
        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('catalogHitsOnHosts')->zeroOrMoreTimes()->andReturn([]);
        $configure($search);
        $search->shouldReceive('searchOnHosts')->zeroOrMoreTimes()->andReturn([]);
        $search->shouldReceive('searchBothPhases')->zeroOrMoreTimes()->andReturn(['results' => [], 'errors' => []]);
        $search->shouldReceive('dropListingResults')->zeroOrMoreTimes()
            ->andReturnUsing(static fn (array $results): array => $results);
        $search->shouldReceive('searchMappedRetailers')->zeroOrMoreTimes()->andReturn([]);
        $this->app->instance(HybridWebSearchService::class, $search);
    }

    /**
     * @return array{description: string, payload: array<string, mixed>, norms: string|null, packaging: string|null, web_source_urls: list<string>, dropped: list<string>, dropped_claims: list<string>}
     */
    private function supplement(Product $product, int $accountId = 1): array
    {
        $context = new B2bSupplementContext(
            accountId: $accountId,
            linkIds: [],
            descriptionHash: sha1(self::B2B_TEXT),
            sourceDescriptionHash: null,
            sourceSha1: sha1(self::B2B_TEXT),
            b2bText: self::B2B_TEXT,
            b2bUrl: self::B2B_URL,
            hosts: [self::ACCOUNT_HOST],
            hostsSha1: sha1(self::ACCOUNT_HOST),
            minChars: 1000,
            productDescription: self::B2B_TEXT,
        );

        return app(ProductEnrichmentService::class)->supplementB2bDescription($product, $context);
    }

    private function cardHtml(string $facts): string
    {
        return '<html><head><title>Rękawice Norvik KX-2210</title></head><body><h1>Rękawice ochronne NORVIK KX-2210</h1>'
            .'<div class="product-description"><p>Rękawice NORVIK KX-2210 z dzianiny poliestrowej, powlekane nitrylem. '
            .$facts.'</p><p>'.str_repeat('Powłoka nitrylowa na części chwytnej rękawic KX-2210. ', 20).'</p></div></body></html>';
    }

    /**
     * @param  list<string>  $features
     * @param  list<string>  $norms
     * @return array<string, mixed>
     */
    private function answerWith(string $description, array $features = [], array $norms = []): array
    {
        return [
            'description' => $description,
            'features' => $features,
            'specs' => [],
            'norms' => $norms,
            'certificates' => [],
            'materials' => [],
            'use_cases' => [],
            'source_urls' => [],
            'confidence' => 0.9,
        ];
    }
}
