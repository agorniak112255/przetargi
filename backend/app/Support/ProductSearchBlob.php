<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * Jedna znormalizowana kolumna pod wyszukiwanie pełnotekstowe. Bez niej indeks
 * musiałby objąć pięć kolumn i JSON, a „250 gr” z SIWZ i tak nigdy nie trafiłoby
 * w „250 g/m²” z karty — kanoniczne tokeny cech dokleja właśnie ten builder.
 */
final class ProductSearchBlob
{
    /** Kolumny, po których zmianie blob przestaje być aktualny. */
    public const SOURCE_COLUMNS = [
        'sku', 'name', 'manufacturer', 'category', 'norms',
        'description', 'enrichment_payload',
    ];

    private const MAX_LENGTH = 16000;

    public function __construct(
        private readonly ProductFeatureMatch $features,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly PpeAssortment $assortment,
        private readonly PpeFilterType $filterType = new PpeFilterType,
    ) {}

    /**
     * @return array{search_blob: string, search_blob_hash: string, ppe_family: string|null}
     */
    public function build(Product $product): array
    {
        $raw = $this->rawText($product);
        $blob = trim($this->features->normalize($raw).' '.$this->canonicalFeatures($raw));
        if (mb_strlen($blob) > self::MAX_LENGTH) {
            $blob = mb_substr($blob, 0, self::MAX_LENGTH);
        }

        return [
            'search_blob' => $blob,
            'search_blob_hash' => hash('sha256', $blob),
            'ppe_family' => $this->assortment->productFamily($product),
        ];
    }

    /**
     * Kanoniczne tokeny cech liczbowych: „250 gr”, „250 g/m²” i „250 gsm” dają
     * jedno „250gsm”, „EN ISO 20471” i „EN20471” jedno „en20471”. Dopiero po tym
     * porównanie tokenów przez FULLTEXT ma sens.
     */
    public function canonicalFeatures(string $text): string
    {
        $tokens = [];
        foreach ($this->features->grammages($text) as $grammage) {
            $tokens[] = $grammage.'gsm';
        }
        foreach ($this->features->norms($text) as $norm) {
            $tokens[] = 'en'.$norm;
        }
        foreach ($this->filterType->compactCodes($text) as $code) {
            $tokens[] = $code;
            $tokens[] = strtolower($this->filterType->hyphenated($code));
        }
        $footwearClass = $this->bhpAttributes->footwearClass($text);
        if ($footwearClass !== null) {
            $tokens[] = $this->bhpAttributes->footwearClassToken($footwearClass);
        }

        return implode(' ', array_values(array_unique($tokens)));
    }

    private function rawText(Product $product): string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];

        return trim(implode(' ', array_filter([
            (string) $product->sku,
            (string) $product->name,
            (string) ($product->manufacturer ?? ''),
            (string) ($product->category ?? ''),
            (string) ($product->norms ?? ''),
            (string) ($product->description ?? ''),
            $this->flattenPayload($payload),
            $this->bhpAttributes->toSearchText($this->bhpAttributes->forProduct($product)),
        ], static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function flattenPayload(array $payload): string
    {
        $parts = [];
        foreach (['materials', 'features', 'use_cases', 'norms', 'specs', 'certificates'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value)) {
                $parts[] = $value;

                continue;
            }
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $parts[] = trim($item);
                }
            }
        }

        return implode(' ', $parts);
    }
}
