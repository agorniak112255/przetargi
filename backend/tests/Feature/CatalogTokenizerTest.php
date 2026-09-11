<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogPage;
use App\Models\Product;
use App\Services\Enrichment\CatalogIndexSearch;
use App\Services\Enrichment\CatalogSitemapIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tokeny indeksu decydują o tym, czy karta da się znaleźć po kodzie produktu.
 */
final class CatalogTokenizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_from_url_tail_survives_a_long_title(): void
    {
        // tytuł karty bywa dłuższy niż limit tokenów — kod z końcówki adresu ginął,
        // więc karta leżała w indeksie, ale nie dawała się znaleźć po SKU
        $tokens = app(CatalogSitemapIndexer::class)->tokensFor(
            'https://sklep.example.pl/sklep/obuwie/obuwie-robocze/arya-300-673560-s1-pl',
            'Buty robocze ochronne skórzane antypoślizgowe z podnoskiem kompozytowym '
                .'oraz wkładką antyprzebiciową dla magazynierów i pracowników produkcji '
                .'w rozmiarach od trzydziestu pięciu do czterdziestu ośmiu wysyłka gratis '
                .'najtaniej promocja wyprzedaż nowość polecane hit sezonu'
        );

        $this->assertContains('673560', $tokens);
        $this->assertContains('arya', $tokens);
    }

    public function test_refresh_drops_tokens_from_old_rules(): void
    {
        $url = 'https://sklep.example.pl/produkt/krytech-380';
        $page = CatalogPage::query()->create([
            'host' => 'sklep.example.pl',
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'haystack' => $url,
        ]);
        DB::table('catalog_page_tokens')->insert([
            'catalog_page_id' => $page->id,
            'token' => 'token-po-starych-regulach',
        ]);

        app(CatalogSitemapIndexer::class)->storeTokens([$page->url_hash], true);

        $this->assertDatabaseMissing('catalog_page_tokens', [
            'catalog_page_id' => $page->id,
            'token' => 'token-po-starych-regulach',
        ]);
        $this->assertDatabaseHas('catalog_page_tokens', [
            'catalog_page_id' => $page->id,
            'token' => 'krytech380',
        ]);
    }

    public function test_multi_segment_sku_matches_tokens_the_index_really_stores(): void
    {
        $product = new Product([
            'sku' => '7-003 B S1',
            'name' => 'Rękawice powlekane',
            'manufacturer' => 'Reis',
        ]);

        $codes = app(CatalogIndexSearch::class)->codes($product);
        $indexTokens = app(CatalogSitemapIndexer::class)
            ->tokensFor('https://sklep.example.pl/rekawice-7-003-b-s1');

        // obie strony liczą tokeny tak samo — wcześniej zapytanie szło po „7003bs1”,
        // którego indeks nigdy nie zapisuje, i karta nie dawała się znaleźć
        $this->assertContains('7003', $codes);
        $this->assertContains('7003', $indexTokens);
        $this->assertNotEmpty(array_intersect($codes, $indexTokens));
    }
}
