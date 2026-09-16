<?php

declare(strict_types=1);

namespace App\Services\B2b;

use RuntimeException;

/**
 * Prośba o zatrzymanie przebiegu („Zatrzymaj” w panelu) zauważona poza pętlą produktów — przy pobieraniu listy
 * dostawcy, które przy pełnym cenniku trwa kilka minut. Przerywa pobieranie; przebieg kończy się jako zatrzymany,
 * nie jako błąd.
 */
final class B2bCancelledException extends RuntimeException {}
