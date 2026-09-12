<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\JinaSearchClient;
use App\Services\Enrichment\SearchEngineOutage;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class JinaSearchClientTest extends TestCase
{
    public function test_parses_results_and_sends_key_without_page_content(): void
    {
        config(['enrichment.reader_api_key' => 'jina_test_key']);
        Http::fake([
            's.jina.ai/*' => Http::response([
                'code' => 200,
                'data' => [
                    [
                        'url' => 'https://www.ansell.com/pl/pl/products/hyflex-11-801',
                        'title' => 'HyFlex 11-801 | Ansell',
                        'description' => 'Rękawice HyFlex 11-801 z powłoką nitrylową.',
                    ],
                    ['title' => 'Bez adresu', 'description' => 'wiersz do pominięcia'],
                    [
                        'url' => 'https://bhp24.pl/hyflex-11-801',
                        'title' => 'HyFlex 11-801 BHP24',
                        'description' => 'Sklep BHP24',
                    ],
                ],
            ], 200),
        ]);

        $results = (new JinaSearchClient)->search('HyFlex 11-801 Ansell');

        $this->assertSame([
            [
                'url' => 'https://www.ansell.com/pl/pl/products/hyflex-11-801',
                'title' => 'HyFlex 11-801 | Ansell',
                'snippet' => 'Rękawice HyFlex 11-801 z powłoką nitrylową.',
            ],
            [
                'url' => 'https://bhp24.pl/hyflex-11-801',
                'title' => 'HyFlex 11-801 BHP24',
                'snippet' => 'Sklep BHP24',
            ],
        ], $results);
        Http::assertSent(static function ($request): bool {
            return str_starts_with($request->url(), 'https://s.jina.ai/?q=')
                && $request->hasHeader('Authorization', 'Bearer jina_test_key')
                && $request->hasHeader('X-Respond-With', 'no-content');
        });
    }

    public function test_rate_limit_is_reported_as_engine_outage(): void
    {
        config(['enrichment.reader_api_key' => 'jina_test_key']);
        Http::fake(['s.jina.ai/*' => Http::response('too many requests', 429)]);

        $message = null;
        try {
            (new JinaSearchClient)->search('AlphaTec 58-330 Ansell');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        // status w komunikacie: po nim SearchEngineOutage kieruje produkt do ponowienia, nie do ręki
        $this->assertNotNull($message);
        $this->assertStringContainsString('Jina HTTP 429', $message);
        $this->assertTrue(SearchEngineOutage::matches($message), $message);
    }

    public function test_without_key_does_not_ask_jina(): void
    {
        config(['enrichment.reader_api_key' => '']);
        Http::fake();

        $client = new JinaSearchClient;
        $this->assertFalse($client->isConfigured());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Jina: brak klucza API.');
        try {
            $client->search('HyFlex 11-801 Ansell');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_empty_data_is_no_results_not_outage(): void
    {
        config(['enrichment.reader_api_key' => 'jina_test_key']);
        Http::fake(['s.jina.ai/*' => Http::response(['code' => 200, 'data' => []], 200)]);

        $this->assertSame([], (new JinaSearchClient)->search('YE65T-00803-07-GA2 Ansell'));
    }
}
