<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, który przed pierwszym produktem długo pobiera listę (wiele stron). Przez komunikaty postępu przebieg
 * daje sygnał życia — bez nich b2b:sync-due uznałby go za przerwany po B2bSyncRun::STALE_MINUTES.
 */
interface B2bListProgressAware
{
    /**
     * @param  callable(string): void  $callback  komunikat do dziennika przebiegu (np. „Lista: strona 40/288”)
     */
    public function onListProgress(callable $callback): void;
}
