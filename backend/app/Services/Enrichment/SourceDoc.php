<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Carbon\CarbonInterface;

/**
 * Zapisane źródło opisu karty bez tekstu (SourceDocumentStore::forProduct) — tekst po sha256 z SourceDocumentStore::get.
 */
final class SourceDoc
{
    /**
     * @param  list<string>  $roles
     * @param  list<array<string, mixed>>  $normFacts
     * @param  list<array{type: string, value: string}>  $markupCodes
     */
    public function __construct(
        public readonly string $url,
        public readonly ?string $finalUrl,
        public readonly string $host,
        public readonly string $sha256,
        public readonly ?string $filteredSha256,
        public readonly ?string $verdict,
        public readonly ?string $verdictReason,
        public readonly array $roles,
        public readonly array $normFacts,
        public readonly array $markupCodes,
        public readonly ?CarbonInterface $fetchedAt,
    ) {}
}
