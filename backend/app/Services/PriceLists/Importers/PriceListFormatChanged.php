<?php

declare(strict_types=1);

namespace App\Services\PriceLists\Importers;

use RuntimeException;

/** Plik ma inny układ niż ten, pod który napisano importer (nagłówki, arkusze) — importer trzeba poprawić. */
final class PriceListFormatChanged extends RuntimeException
{
    public static function because(string $detail): self
    {
        return new self('Format pliku się zmienił: '.$detail);
    }
}
