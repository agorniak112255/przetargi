<?php

declare(strict_types=1);

namespace App\Services\Bzp;

use RuntimeException;

/**
 * Odczyt asortymentu z treści ogłoszenia (NoticeItemsReader) nie ruszył: wszystkie miejsca na odczyt modelem są zajęte
 * albo ten sam odczyt trwa u kogoś innego dłużej niż czas oczekiwania. Kontrolery odpowiadają 503 — wystarczy ponowić.
 */
final class NoticeItemsBusyException extends RuntimeException
{
    public function __construct(string $message = 'Model zajęty — spróbuj za chwilę.')
    {
        parent::__construct($message);
    }
}
