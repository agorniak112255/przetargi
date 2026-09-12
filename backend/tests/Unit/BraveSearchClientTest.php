<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\BraveSearchClient;
use App\Services\Enrichment\SearchEngineOutage;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class BraveSearchClientTest extends TestCase
{
    public function test_parses_web_results_and_sends_subscription_token(): void
    {
        config(['enrichment.brave_api_key' => 'BSA-test-key']);
        Http::fake([
            'api.search.brave.com/*' => Http::response([
                'web' => [
                    'results' => [
                        [
                            'url' => 'https://www.ansell.com/pl/pl/products/hyflex-11-840',
                            'title' => 'HyFlex 11-840 | Ansell',
                            'description' => 'Rękawice HyFlex 11-840 z powłoką nitrylową.',
                        ],
                        ['title' => 'Bez adresu', 'description' => 'wiersz do pominięcia'],
                        [
                            'url' => 'https://icd.pl/rekawice-hyflex-11-840',
                            'title' => 'Rękawice HyFlex 11-840',
                            'description' => 'Sklep ICD',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $results = (new BraveSearchClient)->search('HyFlex 11-840 Ansell');

        $this->assertCount(2, $results);
        $this->assertSame('https://www.ansell.com/pl/pl/products/hyflex-11-840', $results[0]['url']);
        $this->assertSame('HyFlex 11-840 | Ansell', $results[0]['title']);
        $this->assertSame('Rękawice HyFlex 11-840 z powłoką nitrylową.', $results[0]['snippet']);
        $this->assertSame('https://icd.pl/rekawice-hyflex-11-840', $results[1]['url']);

        Http::assertSent(static function ($request): bool {
            return $request->hasHeader('X-Subscription-Token', 'BSA-test-key')
                && str_contains($request->url(), 'search_lang=pl')
                && str_contains($request->url(), 'country=PL');
        });
    }

    public function test_rate_limit_is_reported_as_engine_outage(): void
    {
        config(['enrichment.brave_api_key' => 'BSA-test-key']);
        Http::fake(['api.search.brave.com/*' => Http::response('rate limited', 429)]);

        $message = null;
        try {
            (new BraveSearchClient)->search('AlphaTec 58-330 Ansell');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        // 429 to odmowa silnika, nie dowod, ze karty nie ma — status musi byc
        // w tresci, bo po nim SearchEngineOutage rozpoznaje awarie do ponowienia
        $this->assertNotNull($message, '429 powinno konczyc sie wyjatkiem');
        $this->assertStringContainsString('Brave HTTP 429', $message);
        $this->assertTrue(SearchEngineOutage::matches($message), $message);
    }

    public function test_without_api_key_nothing_is_sent(): void
    {
        config(['enrichment.brave_api_key' => null]);
        Http::fake();

        $client = new BraveSearchClient;

        $this->assertFalse($client->isConfigured());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Brave: brak klucza API.');
        try {
            $client->search('HyFlex 11-840 Ansell');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_empty_result_list_is_not_an_outage(): void
    {
        config(['enrichment.brave_api_key' => 'BSA-test-key']);
        Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

        $client = new BraveSearchClient;

        $this->assertTrue($client->isConfigured());
        $this->assertSame([], $client->search('YE65T-00803-07-GA2 Ansell'));
    }
}
