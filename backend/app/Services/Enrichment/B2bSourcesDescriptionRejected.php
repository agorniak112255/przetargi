<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use RuntimeException;
use Throwable;

/**
 * Opis z opisu sklepu i karty PDF odrzucony — karta zostaje z tekstem ze sklepu; powód w komunikacie.
 *
 * $permanent — to samo wejście da ten sam wynik (np. PDF opisuje wyłącznie inny wariant obuwia), więc dla tych samych
 * źródeł nie ma po co pytać modelu ponownie. Domyślnie false: odrzucenie zależne od odpowiedzi modelu (pusty albo
 * ucięty JSON, poziom normy spoza źródeł) bywa losowe i DescribeB2bProductFromDatasheetJob ponawia je do limitu prób.
 */
final class B2bSourcesDescriptionRejected extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly bool $permanent = false,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
