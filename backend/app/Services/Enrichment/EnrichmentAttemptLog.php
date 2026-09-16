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

    /**
     * Kroki odtworzone z prefetchu — tyle, żeby było widać, czego szukano, a przebieg
     * na żywo (limit 40 kroków) nie ginął pod dwunastoma identycznymi błędami SearXNG.
     */
    public const MAX_REPLAYED_STEPS = 12;

    /** @var list<array{t: string, m: string, url?: string, urls?: list<string>, why?: list<string>, pf?: bool}> */
    private array $steps = [];

    public function reset(): void
    {
        $this->steps = [];
    }

    /**
     * @param  list<string>  $urls
     * @param  list<string>  $why  powód przy adresie o tym samym indeksie
     * @param  bool  $replayed  krok odtworzony z prefetchu — nie z tej próby
     */
    public function add(string $type, string $message, ?string $url = null, array $urls = [], array $why = [], bool $replayed = false): void
    {
        if (count($this->steps) >= self::MAX_STEPS) {
            return;
        }
        $row = [
            't' => mb_substr($type, 0, 16),
            'm' => mb_substr(trim($message), 0, 400),
        ];
        if ($replayed) {
            $row['pf'] = true;
        }
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
                static fn (array $row): string => CandidateRejection::labelWithDetail($row),
                $rejections
            ),
        );
    }

    /**
     * Treść kroków jednego rodzaju — np. wszystkie „err”, żeby rozpoznać
     * awarię wyszukiwarki, a nie realny brak karty produktu.
     *
     * @param  bool  $includeReplayed  także kroki odtworzone z prefetchu; awarię
     *                                 ocenia się tylko po tej próbie — prefetch sprzed
     *                                 godziny mógł trafić na blokadę, której już nie ma
     * @return list<string>
     */
    public function messagesOfType(string $type, bool $includeReplayed = true): array
    {
        $out = [];
        foreach ($this->steps as $step) {
            if ($step['t'] !== $type || (! $includeReplayed && ! empty($step['pf']))) {
                continue;
            }
            $out[] = $step['m'];
        }

        return $out;
    }

    /**
     * @param  int|null  $maxSteps  ostatnie N kroków — ślad udanego przebiegu zapisujemy skrócony,
     *                              żeby dało się odtworzyć pochodzenie opisu bez pełnego dziennika
     *                              przy każdej z kilkudziesięciu tysięcy kart
     * @return array{at: string, sku: string, name: string, manufacturer: string, steps: list<array<string, mixed>>}
     */
    public function snapshot(Product $product, ?int $maxSteps = null): array
    {
        $steps = $this->steps;
        if ($maxSteps !== null && $maxSteps > 0 && count($steps) > $maxSteps) {
            $steps = array_values(array_slice($steps, -$maxSteps));
        }

        return [
            'at' => now()->toIso8601String(),
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'manufacturer' => (string) $product->manufacturer,
            'steps' => $steps,
        ];
    }
}
