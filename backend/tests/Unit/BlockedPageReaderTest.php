<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\BlockedPageReader;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BlockedPageReaderTest extends TestCase
{
    public function test_fetch_for_crawl_caps_huge_reader_html(): void
    {
        Http::fake([
            'https://r.jina.ai/*' => Http::response(str_repeat('A', 2_000_000), 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $html = (new BlockedPageReader)->fetchForCrawl('https://optimumbhp.pl/');

        $this->assertNotNull($html);
        $this->assertLessThanOrEqual(400000, strlen((string) $html));
        $this->assertGreaterThan(40, strlen((string) $html));
    }

    public function test_fetch_caps_huge_reader_markdown(): void
    {
        Http::fake([
            'https://r.jina.ai/*' => Http::response(
                str_repeat('Filtr Adflo 837012 do systemu PAPR. ', 80000),
                200
            ),
        ]);

        $page = (new BlockedPageReader)->fetch('https://www.3mpolska.pl/3M/pl_PL/p/d/v101524110/');

        $this->assertIsArray($page);
        $this->assertLessThanOrEqual(5000, mb_strlen((string) ($page['text'] ?? '')));
    }

    public function test_fetch_retries_once_when_reader_is_rate_limited(): void
    {
        // przy wielu workerach naraz Jina chwilowo odmawia — bez drugiej próby karta
        // Ansella (za Incapsulą) przepadała i model dostawał sam fragment z wyszukiwarki
        Http::fake([
            'https://r.jina.ai/*' => Http::sequence()
                ->push('Rate limit exceeded', 429)
                ->push(
                    "Title: RINGERS R259\n\n# RINGERS™ R259\n\n"
                    .'Wytrzymałe rękawice udarowe z dodatkową warstwą na dłoni, odporność na przecięcia EN 388 E.',
                    200
                ),
        ]);

        $page = (new BlockedPageReader)->fetch('https://www.ansell.com/pl/pl/products/ringers-r259');

        $this->assertIsArray($page);
        $this->assertStringContainsString('EN 388 E', $page['text']);
        Http::assertSentCount(2);
    }

    public function test_fetch_does_not_retry_missing_page(): void
    {
        Http::fake(['https://r.jina.ai/*' => Http::response('Not found', 404)]);

        $this->assertNull((new BlockedPageReader)->fetch('https://www.ansell.com/pl/pl/products/ringers-r259'));
        Http::assertSentCount(1);
    }
}
