<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class EmbeddingRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** 401/403/400 — retry i dalsze karty nic nie dadzą. */
    public function isTerminal(): bool
    {
        return in_array($this->status, [400, 401, 403, 404], true);
    }
}
