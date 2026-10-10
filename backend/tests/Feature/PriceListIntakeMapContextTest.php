<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogPage;
use App\Models\PriceList;
use App\Services\Enrichment\CatalogSitemapIndexer;
use App\Services\PriceLists\Importers\DefaultMapContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** DefaultMapContext: kod jako całe słowo, strony indeksu z kodem na hostach, brak pobierania w podglądzie. */
final class PriceListIntakeMapContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_carries_code_only_as_whole_word_without_variant_suffix(): void
    {
        $ctx = new DefaultMapContext($this->list(), false);

        $this->assertTrue($ctx->carriesCode('Rękawice PSSBL30-014 nitrylowe', ['PSSBL30014']));
        $this->assertTrue($ctx->carriesCode('https://mapa-pro.com/pl/butoflex-650', ['650']));
        $this->assertFalse($ctx->carriesCode('TRACPSFX rękawica', ['TRACPSF']));
        $this->assertFalse($ctx->carriesCode('Rękawica 1011 R', ['1011']));
        $this->assertFalse($ctx->carriesCode('Kombinezon 104/1 OC', ['104/1']));
        $this->assertTrue($ctx->carriesCode('Kombinezon 104/1 OC oraz 104/1 bez kaptura', ['104/1']));
        $this->assertTrue($ctx->carriesCode('Rękawica 1011 w rozmiarze 9', ['1011']));
        $this->assertFalse($ctx->carriesCode('A1 maska', ['A1']));
    }

    public function test_pages_with_code_reads_token_index_on_given_hosts(): void
    {
        $this->page('https://www.mapa-pro.com/pl/rekawice/ultrane-500');
        $this->page('https://mapa-pro.com/pl/rekawice/ultrane-5000');
        $this->page('https://sklep.test/ultrane-500');
        $this->page('https://mapa-pro.com/pl/rekawice/af-0100-mata');
        $ctx = new DefaultMapContext($this->list(), false);

        $this->assertSame(['https://www.mapa-pro.com/pl/rekawice/ultrane-500'], array_column($ctx->pagesWithCode('Ultrane 500', ['mapa-pro.com']), 'url'));
        $this->assertSame(['https://mapa-pro.com/pl/rekawice/af-0100-mata'], array_column($ctx->pagesWithCode('AF0100', ['https://mapa-pro.com/']), 'url'));
        $this->assertSame([], $ctx->pagesWithCode('500', []));
    }

    public function test_fetch_is_disabled_without_live_fetch(): void
    {
        Http::fake();
        $ctx = new DefaultMapContext($this->list(), false);

        $this->assertNull($ctx->fetch('https://mapa-pro.com/pl/x'));
        $this->assertFalse($ctx->liveFetch());
        Http::assertNothingSent();
    }

    public function test_live_fetch_bypasses_page_cache_so_final_url_is_real(): void
    {
        $url = 'https://www.portwest.com/products/view/A110/BKR';
        $html = (string) file_get_contents(base_path('tests/Fixtures/norms/portwest-a110.html'));
        Http::fake([$url => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);
        $ctx = new DefaultMapContext($this->list(), true);

        $first = $ctx->fetch($url);
        $second = $ctx->fetch($url);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($url, $first['final_url']);
        $this->assertNotSame('', $first['text']);
        // drugie pobranie też z sieci (z pamięci fetchRaw oddałby adres zapytania zamiast adresu po przekierowaniu)
        Http::assertSentCount(2);
    }

    public function test_list_hosts_come_from_price_list_enrichment_sites(): void
    {
        $list = $this->list();
        $list->update(['enrichment_sites' => ['https://www.dostawca.test/', 'drugi.test']]);

        $this->assertSame(['dostawca.test', 'drugi.test'], (new DefaultMapContext($list->fresh(), false))->listHosts());
    }

    private function page(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $page = CatalogPage::query()->create([
            'host' => preg_replace('/^www\./', '', $host), 'url_hash' => CatalogPage::hashFor($url), 'url' => $url,
            'title' => null, 'haystack' => mb_strtolower($url), 'last_seen_at' => now(),
        ]);
        foreach (array_unique(app(CatalogSitemapIndexer::class)->tokensFor($url)) as $token) {
            DB::table('catalog_page_tokens')->insert(['catalog_page_id' => $page->id, 'token' => $token]);
        }
    }

    private function list(): PriceList
    {
        return PriceList::query()->create([
            'manufacturer' => 'MAPA', 'manufacturer_key' => 'mapa', 'version' => '2025',
            'rows_total' => 0, 'products_created' => 0, 'products_updated' => 0, 'rows_skipped' => 0,
        ]);
    }
}
