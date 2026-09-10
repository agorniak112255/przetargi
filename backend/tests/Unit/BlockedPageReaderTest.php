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
}
