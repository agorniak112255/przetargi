<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Wartość kolumny products.norms z listy norm opisu (enrichment_payload.norms): pierwsze 8 niepustych wpisów po
 * przecinku, null przy pustej liście. Ta sama reguła co ProductEnrichmentService::writeNormsColumn i opis B2B
 * z karty produktu — kolumna należy do opisu i jest jego skrótem, nie osobnym źródłem.
 */
final class ProductNormsColumn
{
    /** Tyle norm mieści kolumna; reszta zostaje w enrichment_payload.norms. */
    public const MAX_NORMS = 8;

    public static function fromList(mixed $norms): ?string
    {
        if (! is_array($norms)) {
            return null;
        }
        $list = array_values(array_filter($norms, static fn ($v): bool => is_string($v) && trim($v) !== ''));

        return $list !== [] ? implode(', ', array_slice($list, 0, self::MAX_NORMS)) : null;
    }
}
