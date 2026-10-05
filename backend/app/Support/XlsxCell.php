<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;

/** Komórka z datą dla XlsxStreamWriter (XlsxStreamWriter::date() / ::dateTime()). */
final class XlsxCell
{
    public function __construct(
        public readonly DateTimeInterface $value,
        public readonly int $style,
    ) {}
}
