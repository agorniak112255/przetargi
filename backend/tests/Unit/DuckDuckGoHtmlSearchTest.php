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
}
