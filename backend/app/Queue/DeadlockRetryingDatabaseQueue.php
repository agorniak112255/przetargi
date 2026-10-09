<?php

declare(strict_types=1);

namespace App\Queue;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Queue\DatabaseQueue;
use Throwable;

/**
 * Kolejka bazodanowa, która ponawia usunięcie i zwolnienie zadania po zakleszczeniu.
 *
 * MariaDB 10.5 na serwerze nie zna SKIP LOCKED, więc workery pobierają zadania przez zwykłe `select … for update`
 * i blokują się nawzajem. Zakleszczenie przy `delete from jobs` po skończonym zadaniu jest najgroźniejsze:
 * DatabaseJob::delete() oznacza zadanie jako usunięte jeszcze przed zapytaniem, worker go więc nie zwalnia, wiersz
 * zostaje zarezerwowany i po retry_after kolejka oddaje to samo, wykonane już zadanie drugi raz (partia #507,
 * 09.10.2026: drugi prefetch karty opisanej 6 minut wcześniej zostawił jej pozycję w „running”).
 * Zakleszczenie wycofuje całą transakcję, więc ponowienie zaczyna od czystego stanu.
 */
final class DeadlockRetryingDatabaseQueue extends DatabaseQueue
{
    use DetectsConcurrencyErrors;

    public const ATTEMPTS = 3;

    public function deleteReserved($queue, $id)
    {
        $this->retryOnDeadlock(fn () => parent::deleteReserved($queue, $id));
    }

    public function deleteAndRelease($queue, $job, $delay)
    {
        $this->retryOnDeadlock(fn () => parent::deleteAndRelease($queue, $job, $delay));
    }

    private function retryOnDeadlock(Closure $callback): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $callback();

                return;
            } catch (Throwable $e) {
                // w zewnętrznej transakcji zakleszczenie wycofało też ją — ponowienie samego usunięcia byłoby błędne
                if ($attempt >= self::ATTEMPTS
                    || $this->database->transactionLevel() > 0
                    || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }
                usleep(random_int(20_000, 100_000));
            }
        }
    }
}
