<?php

declare(strict_types=1);

namespace Tests\Feature\Norms;

use App\Services\Enrichment\ProductPageFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ProductPageFetcher::fetchRaw — cała strona HTML dla norm ze strony producenta. Zapora, PDF pod adresem karty
 * i status błędu to null, a nie „strona” do czytania.
 */
final class ProductPageFetcherRawTest extends TestCase
{
    private const URL = 'https://www.portwest.com/products/view/A110/BKR';

    public function test_returns_whole_html_and_serves_the_second_call_from_cache(): void
    {
        $html = (string) file_get_contents(base_path('tests/Fixtures/norms/portwest-a110.html'));
        Http::fake([self::URL => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);
        $fetcher = new ProductPageFetcher;

        $this->assertSame(['html' => $html, 'final_url' => self::URL, 'status' => 200, 'from_cache' => false], $fetcher->fetchRaw(self::URL));
        $this->assertSame(['html' => $html, 'final_url' => self::URL, 'status' => 200, 'from_cache' => true], $fetcher->fetchRaw(self::URL));
        Http::assertSentCount(1);
    }

    public function test_bot_wall_pdf_and_error_status_give_null(): void
    {
        $wall = '<html><head><title>Request unsuccessful</title></head><body>'
            .str_repeat('<p>Request unsuccessful. Incapsula incident ID: 123</p>', 30).'</body></html>';
        Http::fake([
            'https://www.ansell.com/*' => Http::response($wall, 200, ['Content-Type' => 'text/html']),
            'https://docs.example.com/*' => Http::response('%PDF-1.7 '.str_repeat('x', 2000), 200, ['Content-Type' => 'application/pdf']),
            'https://gone.example.com/*' => Http::response('<html><body>'.str_repeat('Nie ma takiej strony. ', 100).'</body></html>', 404, ['Content-Type' => 'text/html']),
        ]);
        $fetcher = new ProductPageFetcher;

        $this->assertNull($fetcher->fetchRaw('https://www.ansell.com/pl/pl/products/hyflex-11-800'));
        $this->assertNull($fetcher->fetchRaw('https://docs.example.com/karta.pdf'));
        $this->assertNull($fetcher->fetchRaw('https://gone.example.com/rekawice.html'));
        $this->assertNull($fetcher->fetchRaw('ftp://x.pl/a'));
    }

    public function test_norm_facts_from_html_are_the_frame_pairs(): void
    {
        $html = '<html><body><ul class="norms"><li><div>EN 388</div><div>1121X</div></li><li><div>EN 374-5</div></li></ul></body></html>';

        $this->assertSame(
            [['label' => 'EN 388', 'value' => '1121X'], ['label' => 'EN 374-5']],
            (new ProductPageFetcher)->normFactsFromHtml($html),
        );
    }
}
