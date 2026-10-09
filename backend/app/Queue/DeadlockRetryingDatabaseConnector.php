<?php

declare(strict_types=1);

namespace App\Queue;

use Illuminate\Queue\Connectors\DatabaseConnector;

/** Sterownik `database` kolejki z ponawianiem usunięcia zadania po zakleszczeniu — zob. DeadlockRetryingDatabaseQueue. */
final class DeadlockRetryingDatabaseConnector extends DatabaseConnector
{
    public function connect(array $config)
    {
        return new DeadlockRetryingDatabaseQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null
        );
    }
}
