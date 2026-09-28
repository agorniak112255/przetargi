<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use RuntimeException;

/**
 * Uzupełnianie krótkiego opisu B2B nie znalazło stron, bo wyszukiwarka nie odpowiedziała (przerwa bezpiecznika
 * SearXNG, 422 readera) — nie wiadomo, czy strona wyrobu istnieje. SupplementB2bDescriptionJob odkłada kartę
 * w kolejce zamiast liczyć błąd (jak EnrichProductJob::waitForSearchBackend).
 */
final class B2bSupplementSearchOutage extends RuntimeException {}
