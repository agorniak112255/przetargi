<?php

declare(strict_types=1);

namespace App\Services\B2b;

use RuntimeException;
use Throwable;

/**
 * Plik dostawcy ponad limit rozmiaru — pobieranie przerwane celowo (nagłówek Content-Length albo bajty w trakcie).
 * Wyjątek z on_headers Guzzle opakowuje w RequestException „An error was encountered during the on_headers event”,
 * a Laravel w ConnectionException z tym samym komunikatem, więc łącznik szuka go w łańcuchu, nie w treści komunikatu
 * (Honeywell 08.10.2026: instrukcja kasków NSB ponad 15 MB brana za brak połączenia — ponawianie i przerwany przebieg).
 */
final class B2bFileTooLargeException extends RuntimeException
{
    public static function in(Throwable $e): ?self
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof self) {
                return $cause;
            }
        }

        return null;
    }
}
