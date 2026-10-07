<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Kod wyrobu w tekście jako osobny ciąg — reguła z ProductEnrichmentService (supplementCodeKey / textCarriesCode),
 * wyjęta do wspólnego użytku: werdykt tożsamości źródła (SourceIdentity) liczy go tak samo jak uzupełnianie opisu.
 */
final class ProductCodeMatch
{
    /** Kod do porównania: małe litery i cyfry, bez separatorów („PSSBL30-014” → „pssbl30014”). */
    public static function key(string $code): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($code));
    }

    /**
     * Kod stoi w tekście jako osobny ciąg — między znakami kodu wolno separator („PSSBL30-014”, „TRACPSF”), przed
     * i za nim nie ma litery ani cyfry (TRACPSF nie trafia w TRACPSFX).
     */
    public static function textCarries(string $text, string $key): bool
    {
        if ($key === '' || $text === '') {
            return false;
        }
        $chars = preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pattern = implode('[\s._\/-]?', array_map(static fn (string $c): string => preg_quote($c, '/'), $chars));

        return preg_match('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/iu', $text) === 1;
    }
}
