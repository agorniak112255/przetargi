<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Stan karty na starcie uzupełniania krótkiego opisu B2B (B2bDescriptionSupplement::context) — wejście dla
 * ProductEnrichmentService::supplementB2bDescription i odcisk do compare-and-set przy zapisie.
 *
 * - $b2bText — obecny opis karty: tekst z B2B albo jego polskie tłumaczenie;
 * - $b2bUrl — adres karty u dostawcy (shop_source_url na witrynie łącznika tego konta), inaczej '';
 * - $descriptionHash / $sourceDescriptionHash — hashe wszystkich powiązań konta z kartą przy starcie (są równe);
 * - $sourceSha1 — odcisk tekstu źródła: source_description_hash ?? description_hash;
 * - $productDescription — opis karty przy starcie.
 */
final readonly class B2bSupplementContext
{
    /**
     * @param  list<int>  $linkIds
     * @param  list<string>  $hosts
     */
    public function __construct(
        public int $accountId,
        public array $linkIds,
        public string $descriptionHash,
        public ?string $sourceDescriptionHash,
        public string $sourceSha1,
        public string $b2bText,
        public string $b2bUrl,
        public array $hosts,
        public string $hostsSha1,
        public int $minChars,
        public string $productDescription,
    ) {}
}
