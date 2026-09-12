<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use App\Services\Enrichment\SearchEngineOutage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class DuckDuckGoHtmlSearchTest extends TestCase
{
    public function test_search_interval_divides_by_number_of_search_ips(): void
    {
        config(['enrichment.search_min_interval' => 1.5, 'enrichment.search_lanes' => 1]);
        $this->assertEqualsWithDelta(1.5, DuckDuckGoHtmlSearch::searchInterval(), 0.001);

        // skrypt workerów podaje liczbę adresów, z których SearXNG rotuje zapytania
        putenv('ENRICHMENT_SEARCH_LANES=9');
        try {
            $this->assertSame(9, DuckDuckGoHtmlSearch::searchLanes());
            $this->assertEqualsWithDelta(1.5 / 9, DuckDuckGoHtmlSearch::searchInterval(), 0.001);
        } finally {
            putenv('ENRICHMENT_SEARCH_LANES');
        }
    }

    public function test_parses_result_links_and_decodes_uddg(): void
    {
        $html = <<<'HTML'
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

    public function test_drops_social_and_engine_junk_hosts(): void
    {
        $html = <<<'HTML'
<a class="result__a" href="https://www.reddit.com/r/ChatGPT">Reddit</a>
<a class="result__a" href="https://github.com/login">GitHub</a>
<a class="result__a" href="https://www.microsoft.com/word">Word</a>
<a class="result__a" href="https://safespec.dupont.com/product/tychem-6000">Tychem</a>
HTML;

        $results = (new DuckDuckGoHtmlSearch)->parseHtml($html);
        $urls = array_column($results, 'url');

        $this->assertSame(['https://safespec.dupont.com/product/tychem-6000'], $urls);
    }

    public function test_query_without_results_is_remembered_and_not_asked_again(): void
    {
        config(['enrichment.search_min_interval' => 0]);
        Cache::flush();
        // wszystkie silniki odpowiadaja, tylko nie maja nic na to zapytanie
        Http::fake(['*' => Http::response('<html><body>brak</body></html>', 200)]);

        $search = new DuckDuckGoHtmlSearch;
        $first = null;
        try {
            $search->search('YE65T-00803-07-GA2 Ansell');
        } catch (RuntimeException $e) {
            $first = $e->getMessage();
        }
        $this->assertNotNull($first, 'brak wynikow powinien konczyc sie wyjatkiem');
        $asked = 0;
        Http::assertSent(static function () use (&$asked): bool {
            $asked++;

            return true;
        });
        $this->assertGreaterThan(0, $asked);

        $second = null;
        try {
            $search->search('YE65T-00803-07-GA2 Ansell');
        } catch (RuntimeException $e) {
            $second = $e->getMessage();
        }
        $this->assertSame($first, $second);

        // drugie podejscie nie dolozylo ani jednego zapytania do silnikow
        $afterSecond = 0;
        Http::assertSent(static function () use (&$afterSecond): bool {
            $afterSecond++;

            return true;
        });
        $this->assertSame($asked, $afterSecond);
    }

    public function test_engine_outage_is_not_remembered_as_missing_page(): void
    {
        config(['enrichment.search_min_interval' => 0]);
        Cache::flush();
        // 429 to odmowa silnika, a nie dowod, ze karty nie ma
        Http::fake(['*' => Http::response('too many requests', 429)]);

        $search = new DuckDuckGoHtmlSearch;
        $count = static function (): int {
            $n = 0;
            Http::assertSent(static function () use (&$n): bool {
                $n++;

                return true;
            });

            return $n;
        };

        try {
            $search->search('AlphaTec 5000 9151 Ansell');
        } catch (RuntimeException) {
            // oczekiwane
        }
        $afterFirst = $count();

        $second = null;
        try {
            $search->search('AlphaTec 5000 9151 Ansell');
        } catch (RuntimeException $e) {
            $second = $e->getMessage();
        }

        // awaria nie moze zostac zapamietana jako „brak strony”: drugi przebieg
        // (tu: przez bezpiecznik) nadal konczy sie awaria do ponowienia,
        // a pamiec pustych wynikow zostaje pusta
        $this->assertNotNull($second);
        $this->assertTrue(SearchEngineOutage::matches($second), $second);
        $this->assertNull(Cache::get('free_web_search_miss_v1:'.hash('sha256', 'AlphaTec 5000 9151 Ansell')));
        $this->assertGreaterThanOrEqual($afterFirst, $count());
    }

    public function test_forget_query_drops_remembered_miss(): void
    {
        config(['enrichment.search_min_interval' => 0]);
        Cache::flush();
        Http::fake(['*' => Http::response('<html><body>brak</body></html>', 200)]);

        $search = new DuckDuckGoHtmlSearch;
        try {
            $search->search('C244110 NITROTOUGH');
        } catch (RuntimeException) {
            // oczekiwane
        }
        $before = 0;
        Http::assertSent(static function () use (&$before): bool {
            $before++;

            return true;
        });

        $search->forgetQuery('C244110 NITROTOUGH');
        try {
            $search->search('C244110 NITROTOUGH');
        } catch (RuntimeException) {
            // oczekiwane
        }

        $after = 0;
        Http::assertSent(static function () use (&$after): bool {
            $after++;

            return true;
        });
        $this->assertGreaterThan($before, $after, 'po wyczyszczeniu cache pytamy silniki ponownie');
    }

    public function test_full_public_outage_opens_breaker_for_next_queries(): void
    {
        config(['enrichment.search_min_interval' => 0]);
        Cache::flush();
        // Google 429, DDG 429, Qwant 429 — wszystkie trzy padly naraz
        Http::fake(['*' => Http::response('too many requests', 429)]);

        $search = new DuckDuckGoHtmlSearch;
        $count = static function (): int {
            $n = 0;
            Http::assertSent(static function () use (&$n): bool {
                $n++;

                return true;
            });

            return $n;
        };

        try {
            $search->search('HyFlex 11-840 Ansell');
        } catch (RuntimeException) {
            // oczekiwane
        }
        $afterFirst = $count();
        $this->assertGreaterThan(0, $afterFirst);

        // inne zapytanie: bezpiecznik otwarty, ani jednego zapytania do silnikow
        $second = null;
        try {
            $search->search('AlphaTec 58-330 Ansell');
        } catch (RuntimeException $e) {
            $second = $e->getMessage();
        }
        $this->assertSame($afterFirst, $count(), 'przy otwartym bezpieczniku nie pytamy silnikow');
        $this->assertNotNull($second);
        $this->assertStringContainsString('silniki zablokowane', mb_strtolower($second));
        // to nadal awaria do ponowienia, nie „wpisz recznie”
        $this->assertTrue(SearchEngineOutage::matches($second));
    }

    public function test_partial_outage_keeps_breaker_closed(): void
    {
        config(['enrichment.search_min_interval' => 0]);
        Cache::flush();
        // Google odpowiada (200, bez wynikow), DDG i Qwant padly — silniki zyja
        Http::fake([
            '*google*' => Http::response('<html><body>brak</body></html>', 200),
            '*' => Http::response('too many requests', 429),
        ]);

        $search = new DuckDuckGoHtmlSearch;
        $count = static function (): int {
            $n = 0;
            Http::assertSent(static function () use (&$n): bool {
                $n++;

                return true;
            });

            return $n;
        };
        try {
            $search->search('HyFlex 11-840 Ansell');
        } catch (RuntimeException) {
            // oczekiwane
        }
        $afterFirst = $count();
        try {
            $search->search('AlphaTec 58-330 Ansell');
        } catch (RuntimeException) {
            // oczekiwane
        }
        $this->assertGreaterThan($afterFirst, $count(), 'gdy jeden silnik zyje, drugie zapytanie idzie do silnikow');
    }

    public function test_searxng_retries_fallback_engines_when_blocked(): void
    {
        $hit = 'https://shop.example/portwest-2205';
        Http::fake([
            'http://127.0.0.1:8088/search*' => Http::sequence()
                ->push([
                    'results' => [],
                    'unresponsive_engines' => [
                        ['brave', 'Suspended: too many requests'],
                        ['qwant', 'CAPTCHA'],
                    ],
                ], 200)
                ->push([
                    'results' => [[
                        'url' => $hit,
                        'title' => 'Portwest 2205',
                        'content' => 'Portwest 2205 kurtka',
                    ]],
                ], 200),
        ]);

        $results = (new DuckDuckGoHtmlSearch)->searchSearxng('http://127.0.0.1:8088', '2205 Portwest');

        $this->assertSame($hit, $results[0]['url'] ?? null);
        Http::assertSent(static function ($request): bool {
            return str_contains(urldecode($request->url()), 'engines=yep,startpage');
        });
    }

    public function test_reads_blocked_engines_from_searxng_json(): void
    {
        $json = json_encode([
            'results' => [],
            'unresponsive_engines' => [
                ['google cse', 'Suspended: too many requests'],
                ['qwant', 'CAPTCHA'],
            ],
        ], JSON_THROW_ON_ERROR);

        $blocked = (new DuckDuckGoHtmlSearch)->searxngBlockedEngines($json);

        $this->assertStringContainsString('google cse: Suspended: too many requests', $blocked);
        $this->assertStringContainsString('qwant: CAPTCHA', $blocked);
        $this->assertSame('', (new DuckDuckGoHtmlSearch)->searxngBlockedEngines('{"results":[]}'));
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
        $html = <<<'HTML'
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
