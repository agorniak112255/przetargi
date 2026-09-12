<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Brave Search API — silnik z kluczem, bez captchy i 403 dla ruchu z serwera.
 * Darmowy plan: ~2000 zapytań/mies., 1 zapytanie/s. Kształt wyniku i konwencja
 * wyjątków jak w DuckDuckGoHtmlSearch: „Brave HTTP <status>: …” musi zawierać
 * status, bo po nim SearchEngineOutage rozpoznaje awarię do ponowienia (429, 5xx)
 * — a pusta lista przy 200 to realny brak karty, nie awaria.
 */
final class BraveSearchClient
{
    private const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    /** Największa liczba wyników, jaką API oddaje w jednym zapytaniu. */
    private const MAX_COUNT = 20;

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function search(string $query, int $maxResults = 10): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            throw new RuntimeException('Brave: brak klucza API.');
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'X-Subscription-Token' => $apiKey,
            ])
                ->timeout(12)
                ->connectTimeout(5)
                ->get(self::ENDPOINT, [
                    'q' => $query,
                    'count' => max(1, min(self::MAX_COUNT, $maxResults)),
                    'search_lang' => 'pl',
                    'country' => 'PL',
                ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Brave: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException('Brave HTTP '.$response->status().': brak wyników wyszukiwania.');
        }

        $payload = $response->json();

        return is_array($payload) ? $this->parseJson($payload) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function parseJson(array $payload): array
    {
        $rows = data_get($payload, 'web.results', []);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                continue;
            }
            if ((string) parse_url($url, PHP_URL_HOST) === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $title = trim((string) ($row['title'] ?? ''));
            $out[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'snippet' => trim((string) ($row['description'] ?? '')),
            ];
        }

        return $out;
    }

    private function apiKey(): ?string
    {
        $key = trim((string) config('enrichment.brave_api_key', ''));

        return $key !== '' ? $key : null;
    }
}
