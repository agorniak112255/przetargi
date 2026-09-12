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

    /** @var list<array{t: string, m: string, url?: string, urls?: list<string>, why?: list<string>}> */
    private array $steps = [];

    public function reset(): void
    {
        $this->steps = [];
    }

    /**
     * @param  list<string>  $urls
     * @param  list<string>  $why  powód przy adresie o tym samym indeksie
     */
    public function add(string $type, string $message, ?string $url = null, array $urls = [], array $why = []): void
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
        $reasons = [];
        foreach (array_values($urls) as $i => $item) {
            if (is_string($item) && $item !== '') {
                $clean[] = mb_substr($item, 0, 300);
                $reasons[] = mb_substr((string) ($why[$i] ?? ''), 0, 120);
            }
            if (count($clean) >= 8) {
                break;
            }
        }
        if ($clean !== []) {
            $row['urls'] = $clean;
            if (array_filter($reasons, static fn (string $r): bool => $r !== '') !== []) {
                $row['why'] = $reasons;
            }
        }
        $this->steps[] = $row;
    }

    /**
     * Odrzuceni kandydaci jako jeden krok — adres z powodem obok.
     *
     * @param  list<array{url: string, reason: string}>  $rejections
     */
    public function addRejections(string $label, array $rejections): void
    {
        $rejections = CandidateRejection::unique($rejections);
        if ($rejections === []) {
            return;
        }
        $this->add(
            'drop',
            $label.': '.CandidateRejection::summary($rejections),
            urls: array_column($rejections, 'url'),
            why: array_map(
                static fn (array $row): string => CandidateRejection::label($row['reason']),
                $rejections
            ),
        );
    }

    /**
     * Treść kroków jednego rodzaju — np. wszystkie „err”, żeby rozpoznać
     * awarię wyszukiwarki, a nie realny brak karty produktu.
     *
     * @return list<string>
     */
    public function messagesOfType(string $type): array
    {
        $out = [];
        foreach ($this->steps as $step) {
            if ($step['t'] === $type) {
                $out[] = $step['m'];
            }
        }

        return $out;
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
