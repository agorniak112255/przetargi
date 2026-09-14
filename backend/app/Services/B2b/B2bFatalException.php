<?php

declare(strict_types=1);

namespace App\Services\B2b;

use RuntimeException;

/**
 * Błąd, po którym dalsze pobieranie zapisałoby złe dane (utrata sesji konta, blokada, seria błędów HTTP).
 * B2bCatalogSync nie łapie go per produkt — przebieg kończy się jako „failed”.
 */
final class B2bFatalException extends RuntimeException {}
