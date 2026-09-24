<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * Zapytanie do modelu zakończone przez limit zapytań (HTTP 429) — także gdy po limicie przyszło przeciążenie,
 * błąd serwera albo pusta odpowiedź. Wyszukiwarka nie schodzi wtedy na konfigurację główną: 24.09.2026 przy serii
 * 429 od przypiętego dostawcy odpowiedź zastępczego modelu źle dobierała karty do przetargu.
 */
final class AiRateLimitedException extends RuntimeException {}
