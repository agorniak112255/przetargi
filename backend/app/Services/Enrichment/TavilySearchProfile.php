<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Parametry zużycia Tavily przy wzbogacaniu produktów.
 *
 * - eco: minimum kredytów (1 zapytanie, 1 faza gdy się uda)
 * - balanced: stop po 1 wyniku, dłuższy cache, bez zbędnych faz
 * - full: agresywne wyszukiwanie (jak wcześniej)
 */
final class TavilySearchProfile
{
    public const MODE_ECO = 'eco';

    public const MODE_BALANCED = 'balanced';

    public const MODE_FULL = 'full';

    public const MODES = [self::MODE_ECO, self::MODE_BALANCED, self::MODE_FULL];

    public function __construct(
        public readonly string $mode,
        public readonly int $maxQueries,
        public readonly int $stopAfterResults,
        public readonly bool $bothPhasesAlways,
        public readonly bool $retailerFallback,
        public readonly bool $openWebFallback,
        public readonly int $docsMaxQueries,
        public readonly bool $docsOpenWebFallback,
        public readonly int $cacheDays,
        public readonly int $maxResults,
    ) {}

    public static function fromMode(?string $mode): self
    {
        $normalized = is_string($mode) ? strtolower(trim($mode)) : self::MODE_BALANCED;

        return match ($normalized) {
            self::MODE_ECO => new self(
                mode: self::MODE_ECO,
                maxQueries: 1,
                stopAfterResults: 1,
                bothPhasesAlways: false,
                retailerFallback: false,
                openWebFallback: true,
                docsMaxQueries: 1,
                docsOpenWebFallback: false,
                cacheDays: 30,
                maxResults: 3,
            ),
            self::MODE_FULL => new self(
                mode: self::MODE_FULL,
                maxQueries: 4,
                stopAfterResults: 2,
                bothPhasesAlways: true,
                retailerFallback: true,
                openWebFallback: true,
                docsMaxQueries: 5,
                docsOpenWebFallback: true,
                cacheDays: 7,
                maxResults: 5,
            ),
            default => new self(
                mode: self::MODE_BALANCED,
                maxQueries: 2,
                stopAfterResults: 1,
                bothPhasesAlways: false,
                retailerFallback: true,
                openWebFallback: true,
                docsMaxQueries: 2,
                docsOpenWebFallback: true,
                cacheDays: 30,
                maxResults: 5,
            ),
        };
    }
}
