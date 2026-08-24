<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use Tests\TestCase;

final class DuckDuckGoHtmlSearchTest extends TestCase
{
    public function test_parses_result_links_and_decodes_uddg(): void
    {
        $html = <<<HTML
<a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.uvex-safety.com%2Fpl%2Fproduct%2Fhg">UVEX HG</a>
<a class="result__a" href="https://duckduckgo.com/about">About</a>
<a rel="nofollow" href="https://bhp24.pl/uvex-hg">BHP24 UVEX</a>
HTML;

        $results = (new DuckDuckGoHtmlSearch)->parseHtml($html);

        $this->assertSame('https://www.uvex-safety.com/pl/product/hg', $results[0]['url'] ?? null);
        $this->assertSame('UVEX HG', $results[0]['title'] ?? null);
        $urls = array_column($results, 'url');
        $this->assertContains('https://bhp24.pl/uvex-hg', $urls);
        $this->assertNotContains('https://duckduckgo.com/about', $urls);
    }

    public function test_parses_searxng_json_and_drops_engine_urls(): void
    {
        $json = json_encode([
            'results' => [
                [
                    'url' => 'https://www.bhpnawigator.com.pl/rekawice-ochronne-robfm.html',
                    'title' => 'Rękawice ROBFM JS Gloves',
                    'content' => 'Rękawice termiczne ROBFM do 250C',
                ],
                ['url' => 'https://www.google.com/search?q=robfm', 'title' => 'Google', 'content' => ''],
                ['url' => 'not-a-url', 'title' => 'Śmieć', 'content' => ''],
            ],
        ], JSON_THROW_ON_ERROR);

        $results = (new DuckDuckGoHtmlSearch)->parseSearxngJson($json);

        $this->assertCount(1, $results);
        $this->assertSame('https://www.bhpnawigator.com.pl/rekawice-ochronne-robfm.html', $results[0]['url']);
        $this->assertSame('Rękawice ROBFM JS Gloves', $results[0]['title']);
        $this->assertSame('Rękawice termiczne ROBFM do 250C', $results[0]['snippet']);
    }

    public function test_parses_google_url_redirects(): void
    {
        $html = <<<HTML
<a href="/url?q=https%3A%2F%2Fwww.bhpnawigator.com.pl%2Fochrona-termiczna%2Frekawice-ochronne-robfm.html&amp;sa=U">Rękawice ROBFM JS Gloves</a>
<a href="https://www.google.com/search?q=robfm">Więcej</a>
HTML;

        $results = (new DuckDuckGoHtmlSearch)->parseGoogleHtml($html);

        $this->assertSame(
            'https://www.bhpnawigator.com.pl/ochrona-termiczna/rekawice-ochronne-robfm.html',
            $results[0]['url'] ?? null
        );
        $this->assertSame('Rękawice ROBFM JS Gloves', $results[0]['title'] ?? null);
        $this->assertCount(1, $results);
    }

    public function test_decodes_bing_ck_redirects(): void
    {
        $target = 'https://www.bhpniedzielscy.com.pl/katalog-bhp/towar,id-687';
        $href = 'https://www.bing.com/ck/a?!&amp;&amp;p=abc&amp;u=a1'.rtrim(base64_encode($target), '=').'&amp;ntb=1';
        $html = '<ol id="b_results"><li class="b_algo"><h2><a href="'.$href.'">Rękawice ROBFM</a></h2></li></ol>';

        $results = (new DuckDuckGoHtmlSearch)->parseBingHtml($html);

        $this->assertSame($target, $results[0]['url'] ?? null);
        $this->assertSame('Rękawice ROBFM', $results[0]['title'] ?? null);
    }

    public function test_bing_snippet_after_title_is_kept(): void
    {
        $target = 'https://shop.example/rekawice-termiczne';
        $href = 'https://www.bing.com/ck/a?!&amp;u=a1'.rtrim(base64_encode($target), '=').'&amp;ntb=1';
        $html = '<ol id="b_results"><li class="b_algo"><h2><a href="'.$href.'">Rękawice termiczne</a></h2>'
            .'<p>Model ROBFM JS Gloves do 250C.</p></li></ol>';

        $results = (new DuckDuckGoHtmlSearch)->parseBingHtml($html);

        $this->assertSame($target, $results[0]['url'] ?? null);
        $this->assertStringContainsString('ROBFM', $results[0]['snippet'] ?? '');
    }
}
