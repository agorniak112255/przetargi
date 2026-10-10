<?php

declare(strict_types=1);

namespace App\Services\PriceLists;

use RuntimeException;

/** Kod uruchomiony w ReadOnlyGuard próbował zapisać do bazy (podgląd importu ma być bez zapisów). */
final class ReadOnlyViolation extends RuntimeException
{
    public static function forSql(string $sql): self
    {
        return new self('Podgląd próbował zapisać do bazy: '.mb_substr(trim($sql), 0, 300));
    }
}
