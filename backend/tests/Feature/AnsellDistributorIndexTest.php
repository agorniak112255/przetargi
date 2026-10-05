<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogPage;
use App\Models\Product;
use App\Services\Enrichment\CatalogIndexSearch;
use App\Services\Enrichment\CatalogSitemapIndexer;
use App\Services\Enrichment\ProductSearchIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 05.10.2026, cennik Ansell: 420 z 423 kart z modelem NN-NNN ma w indeksie kartę dystrybutora (icd.pl
 * „rekawice-ansell-hyflex-nr-11-840”), a wyszukiwanie w indeksie oddawało ją dla 26 — 8 miejsc wyniku zajmowały
 * kopie językowe tej samej karty ansell.com (au/en, cn/zh-hans, hk/en…), a następne partie kolejne kopie.
 */
final class AnsellDistributorIndexTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['au/en', 'cn/zh-hans', 'cn/en', 'hk/en', 'in/en', 'id/id', 'id/en', 'jp/ja', 'int/en', 'pl/pl', 'gb/en', 'nz/en'];

    private const ICD = 'https://icd.pl/rekawice-ansell-hyflex-nr-11-840.html';

    public function test_language_copies_of_the_manufacturer_card_count_once_and_leave_room_for_distributors(): void
    {
        foreach (self::LOCALES as $locale) {
            $this->page('https://www.ansell.com/'.$locale.'/products/hyflex-11-840', 'ansell');
        }
        $this->page(self::ICD, null);
        $this->page('https://bhp-sklep.com.pl/produkt/ansell-11-840-hyflex-rekawice/', null);
        $product = new Product(['sku' => '11840120', 'name' => 'HyFlex 11840', 'manufacturer' => 'Ansell']);

        $urls = array_column(app(CatalogIndexSearch::class)->findFor($product), 'url');

        $this->assertContains(self::ICD, $urls);
        $this->assertContains('https://bhp-sklep.com.pl/produkt/ansell-11-840-hyflex-rekawice/', $urls);
        $ansell = array_values(array_filter($urls, static fn (string $u): bool => str_contains($u, 'ansell.com')));
        $this->assertSame(['https://www.ansell.com/pl/pl/products/hyflex-11-840'], $ansell, 'jedna kopia, w preferowanym języku');

        // następna partia nie oddaje kolejnych kopii tej samej karty
        $next = array_column(app(CatalogIndexSearch::class)->findFor($product, $urls), 'url');
        $this->assertSame([], array_values(array_filter($next, static fn (string $u): bool => str_contains($u, 'ansell.com'))));
    }

    public function test_locale_key_joins_only_ansell_card_copies(): void
    {
        $identity = app(ProductSearchIdentity::class);

        $this->assertSame(
            $identity->localeInsensitivePageKey('https://www.ansell.com/cn/zh-hans/products/hyflex-11-840'),
            $identity->localeInsensitivePageKey('https://www.ansell.com/pl/pl/products/hyflex-11-840')
        );
        $this->assertSame(
            $identity->localeInsensitivePageKey('https://www.ansell.com/int/en/products/hyflex-11-840'),
            $identity->localeInsensitivePageKey('https://ansell.com/gb/en/products/hyflex-11-840')
        );
        $this->assertNotSame(
            $identity->localeInsensitivePageKey('https://www.ansell.com/pl/pl/products/hyflex-11-840'),
            $identity->localeInsensitivePageKey('https://www.ansell.com/pl/pl/products/hyflex-11-842')
        );
        $this->assertSame(mb_strtolower(self::ICD), $identity->localeInsensitivePageKey(self::ICD));
    }

    /** Ringers mają trzycyfrowy model — strona z tym numerem w innym modelu to inny wyrób. */
    public function test_ringers_card_is_not_confirmed_by_another_model_with_the_same_digits(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $r840 = new Product(['sku' => '840-12', 'name' => 'Ringers R840', 'manufacturer' => 'Ansell']);
        $r570 = new Product(['sku' => 'R570-13', 'name' => 'Ringers R570', 'manufacturer' => 'Ansell']);
        $r665 = new Product(['sku' => '665-13', 'name' => 'Ringers 665', 'manufacturer' => 'Ansell']);

        $this->assertTrue($identity->pageClaimsAnotherCode(self::ICD, '', $r840));
        $this->assertFalse($identity->pageClaimsAnotherCode('https://www.bpbhp.pl/rekawice-antywibracyjne-ansell-r840', '', $r840));
        $this->assertTrue($identity->pageClaimsAnotherCode(
            'https://www.thesafetysupplycompany.co.uk/p/9554547/ansell-ringers-r169-cut-resistant-impact-red-glove---an-r169.html',
            '',
            $r570
        ));
        $this->assertTrue($identity->pageClaimsAnotherCode('https://bhp-sklep.com.pl/produkt/ansell-92-665-touch-n-tuff-rekawice/', '', $r665));
        $this->assertFalse($identity->pageClaimsAnotherCode('https://www.safetysupplies.co.uk/ansell-ringers-r665-impact-gloves/', '', $r665));
    }

    private function page(string $url, ?string $manufacturer): void
    {
        CatalogPage::query()->create([
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'manufacturer' => $manufacturer,
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'title' => null,
            'haystack' => mb_strtolower($url),
            'last_seen_at' => now(),
        ]);
        app(CatalogSitemapIndexer::class)->storeTokens([CatalogPage::hashFor($url)]);
    }
}
