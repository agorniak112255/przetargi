<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Wyszukiwarka Jina (s.jina.ai) — ten sam klucz co reader z BlockedPageReader.
 * Bez klucza ma limit ~20 zapytań/min, więc w batchu nie ma sensu jej używać.
 * Komunikaty błędów niosą status HTTP: po nim SearchEngineOutage odróżnia
 * awarię silnika (429, 5xx) od realnego braku wyników.
 */
final class JinaSearchClient
{
    private const ENDPOINT = 'https://s.jina.ai/';

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function search(string $query, int $maxResults = 10): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            throw new RuntimeException('Jina: brak klucza API.');
        }

        try {
            $response = Http::timeout(20)
                ->connectTimeout(8)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$key,
                    // sama lista wyników — bez pełnej treści stron
                    'X-Respond-With' => 'no-content',
                    'User-Agent' => 'Mozilla/5.0 (compatible; SUPON-Enrichment/1.4)',
                ])
                ->get(self::ENDPOINT, ['q' => $query]);
        } catch (Throwable $e) {
            throw new RuntimeException('Jina: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException('Jina HTTP '.$response->status().': brak wyników wyszukiwania.');
        }

        return array_slice($this->parseJson((string) $response->body()), 0, max(1, $maxResults));
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function parseJson(string $json): array
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $rows = is_array($payload) ? ($payload['data'] ?? []) : [];
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
            if ((! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) || isset($seen[$url])) {
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

    private function apiKey(): string
    {
        return trim((string) config('enrichment.reader_api_key', ''));
    }
}
