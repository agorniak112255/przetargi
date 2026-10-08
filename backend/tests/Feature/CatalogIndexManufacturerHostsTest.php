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
 * CatalogIndexSearch::onManufacturerHosts — strony hostów producenta z indeksu bez kodu w adresie (przegląd SECURA
 * 08.10.2026, partia #502): securabc.com ma adresy z numerem wpisu i nazwą („20-secura-3000.html”,
 * „76-torba-do-rekawic.html”, „36-pochlaniacz-a1-5907553323974.html”), a findFor szukał kodu albo marki w adresie.
 */
final class CatalogIndexManufacturerHostsTest extends TestCase
{
    use RefreshDatabase;

    private const HOSTS = ['securabc.com', 'secura.com.pl'];

    public function test_model_page_wins_over_kits_and_other_hosts_are_ignored(): void
    {
        $shops = [];
        for ($i = 1; $i <= 10; $i++) {
            $shops[] = 'https://sklep-'.$i.'.example/zestaw-secura-3000-lak-polmaska-'.$i;
        }
        $this->index([
            ...$shops,
            'https://www.securabc.com/pl/produkty/61-zestaw-secura-3000-lak.html',
            'https://www.securabc.com/pl/produkty/63-zestaw-ewakuacyjny-secura-3000-e.html',
            'https://www.securabc.com/pl/produkty/67-zestaw-secura-3000-lak-blister.html',
            'https://www.securabc.com/pl/polmaska-wielokrotnego-uzytku-secura/20-secura-3000.html',
            'https://www.securabc.com/pl/polmaska-wielokrotnego-uzytku-secura/21-secura-3100.html',
        ]);
        $product = new Product(['sku' => 'S56T0SM0', 'name' => 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)', 'manufacturer' => 'SECURA']);
        $search = app(CatalogIndexSearch::class);

        $urls = array_column($search->onManufacturerHosts($product, self::HOSTS), 'url');

        $this->assertSame('https://www.securabc.com/pl/polmaska-wielokrotnego-uzytku-secura/20-secura-3000.html', $urls[0] ?? null);
        foreach ($urls as $url) {
            $this->assertStringContainsString('securabc.com', $url, 'tylko hosty producenta');
        }
        $this->assertSame([], $search->onManufacturerHosts($product, ['  ']));
        // kolejne findFor nie dziedziczy hostów — sklepy wracają do wyniku
        $this->assertNotEmpty(array_filter(
            array_column($search->findFor($product), 'url'),
            static fn (string $url): bool => str_contains($url, 'sklep-')
        ));
    }

    public function test_absorber_page_is_found_by_class_word_and_ean(): void
    {
        $this->index([
            'https://www.securabc.com/pl/filtry/42-pochlaniacz-a1-filtr-przeciwpylowy-p1.html',
            'https://www.securabc.com/pl/filtry/37-pochlaniacz-a2-5907553323998.html',
            'https://www.securabc.com/pl/filtry/36-pochlaniacz-a1-5907553323974.html',
        ]);
        $search = app(CatalogIndexSearch::class);

        $byName = new Product(['sku' => 'S565A102', 'name' => 'Pochłaniacz 3021 A1', 'manufacturer' => 'SECURA']);
        $this->assertSame(
            'https://www.securabc.com/pl/filtry/36-pochlaniacz-a1-5907553323974.html',
            $search->onManufacturerHosts($byName, self::HOSTS)[0]['url'] ?? null
        );

        $byEan = new Product(['sku' => 'S565A202', 'name' => 'Pochłaniacz 3031', 'manufacturer' => 'SECURA', 'ean' => '5907553323998']);
        $this->assertSame(
            'https://www.securabc.com/pl/filtry/37-pochlaniacz-a2-5907553323998.html',
            $search->onManufacturerHosts($byEan, self::HOSTS)[0]['url'] ?? null
        );
    }

    public function test_page_without_brand_in_address_is_found_by_name_words(): void
    {
        $this->index([
            'https://www.securabc.com/pl/23-akcesoria-do-rekawic-elektroizolacyjnych',
            'https://www.securabc.com/pl/produkty/76-torba-do-rekawic.html',
        ]);
        $product = new Product(['sku' => 'T596T', 'name' => 'Torba do rękawic', 'manufacturer' => 'SECURA']);

        $urls = array_column(app(CatalogIndexSearch::class)->onManufacturerHosts($product, self::HOSTS), 'url');

        $this->assertSame('https://www.securabc.com/pl/produkty/76-torba-do-rekawic.html', $urls[0] ?? null);
    }

    /** @param  list<string>  $urls */
    private function index(array $urls): void
    {
        $now = now();
        $rows = [];
        foreach ($urls as $url) {
            $rows[] = [
                'host' => (string) preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)),
                'manufacturer' => null, 'url_hash' => CatalogPage::hashFor($url), 'url' => $url, 'title' => null,
                'haystack' => mb_strtolower($url), 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('catalog_pages')->insert($rows);
        app(CatalogSitemapIndexer::class)->storeTokens(array_column($rows, 'url_hash'));
    }
}
