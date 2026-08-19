<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\TavilyQuotaGuard;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Podpowiedź spoza katalogu — nigdy nie tworzy produktu SUPON.
 */
final class ExternalCatalogHintService
{
    public function __construct(
        private readonly AiSettingsService $settings,
    ) {}

    /**
     * @return array{url: string, title: string}|null
     */
    public function hint(string $requirement): ?array
    {
        $query = trim($requirement);
        if (mb_strlen($query) < 12) {
            return null;
        }

        $cfg = $this->settings->resolve();
        $key = $cfg['tavily_api_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return null;
        }

        try {
            TavilyQuotaGuard::assertAllowed();
        } catch (Throwable) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(12)
                ->connectTimeout(5)
                ->post('https://api.tavily.com/search', [
                    'api_key' => $key,
                    'query' => mb_substr($query, 0, 400),
                    'search_depth' => 'basic',
                    'include_answer' => false,
                    'max_results' => 3,
                    'include_images' => false,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || $response->status() === 429) {
            return null;
        }

        $rows = $response->json('results');
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || ! str_starts_with($url, 'http')) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? $url));

            return [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
            ];
        }

        return null;
    }
}
