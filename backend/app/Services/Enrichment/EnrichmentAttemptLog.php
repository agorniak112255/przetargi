<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;

/**
 * Przebieg jednej próby pobrania opisu — widać go przy karcie, gdy się nie udało.
 */
final class EnrichmentAttemptLog
{
    private const MAX_STEPS = 40;

    /** @var list<array{t: string, m: string, url?: string, urls?: list<string>}> */
    private array $steps = [];

    public function reset(): void
    {
        $this->steps = [];
    }

    public function add(string $type, string $message, ?string $url = null, array $urls = []): void
    {
        if (count($this->steps) >= self::MAX_STEPS) {
            return;
        }
        $row = [
            't' => mb_substr($type, 0, 16),
            'm' => mb_substr(trim($message), 0, 400),
        ];
        if (is_string($url) && $url !== '') {
            $row['url'] = mb_substr($url, 0, 300);
        }
        $clean = [];
        foreach ($urls as $item) {
            if (is_string($item) && $item !== '') {
                $clean[] = mb_substr($item, 0, 300);
            }
            if (count($clean) >= 8) {
                break;
            }
        }
        if ($clean !== []) {
            $row['urls'] = $clean;
        }
        $this->steps[] = $row;
    }

    /**
     * @return array{at: string, sku: string, name: string, manufacturer: string, steps: list<array<string, mixed>>}
     */
    public function snapshot(Product $product): array
    {
        return [
            'at' => now()->toIso8601String(),
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'manufacturer' => (string) $product->manufacturer,
            'steps' => $this->steps,
        ];
    }
}
