<?php

declare(strict_types=1);

namespace App\Services\B2b;

use RuntimeException;

/**
 * Tłumaczenie odrzucone przez walidację (np. zgubiona albo dopisana liczba, norma, kod; inna liczba segmentów).
 * Karta zostaje z tekstem źródła — nie jest to błąd połączenia z modelem.
 *
 * $modelResponse — odpowiedź modelu, którą odrzucono (do logu): bez niej nie da się ocenić, czy odrzucenie było
 * słuszne (23.09.2026: „zgubiony token: PVC” — czy model napisał „PCW”?).
 */
final class B2bTranslationRejected extends RuntimeException
{
    /** @param  array<string, mixed>|null  $modelResponse */
    public function __construct(string $message, public readonly ?array $modelResponse = null)
    {
        parent::__construct($message);
    }

    /** @param  array<string, mixed>  $modelResponse */
    public function withResponse(array $modelResponse): self
    {
        return new self($this->getMessage(), $modelResponse);
    }
}
