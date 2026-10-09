<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Queue\DeadlockRetryingDatabaseQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * Partia #507 (09.10.2026): `delete from jobs` po skończonym PrefetchProductSourcesJob trafił na zakleszczenie MariaDB
 * 10.5 (bez SKIP LOCKED workery pobierają zadania przez `select … for update`). DatabaseJob::delete() oznacza zadanie
 * jako usunięte przed zapytaniem, więc worker go nie zwalnia — wiersz zostaje zarezerwowany i po retry_after (480 s)
 * kolejka oddaje to samo, już wykonane zadanie drugi raz. W laravel.log 08–09.10: kilkanaście takich zakleszczeń.
 *
 * Osobne połączenie SQLite bez RefreshDatabase: transakcja testu zagnieżdżałaby transakcję kolejki, a zagnieżdżonej
 * Laravel po zakleszczeniu nie ponawia.
 */
final class DatabaseQueueDeadlockRetryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.queue_deadlock_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        $this->connection = DB::connection('queue_deadlock_test');
        $this->connection->getSchemaBuilder()->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('queue_deadlock_test');
        parent::tearDown();
    }

    public function test_plain_database_queue_leaves_finished_job_reserved_after_deadlock(): void
    {
        $job = $this->reservedJob(new DatabaseQueue($this->connection, 'jobs', 'prefetch', 480));
        $this->deadlockOnce('delete from "jobs"');

        try {
            $job->delete();
            $this->fail('Zakleszczenie powinno wyjść z delete().');
        } catch (QueryException) {
        }

        // worker widzi zadanie jako usunięte (nie zwalnia go), a wiersz czeka na ponowne wydanie po retry_after
        $this->assertTrue($job->isDeleted());
        $this->assertSame(1, $this->connection->table('jobs')->count());
    }

    public function test_finished_job_is_deleted_despite_deadlock(): void
    {
        $job = $this->reservedJob(new DeadlockRetryingDatabaseQueue($this->connection, 'jobs', 'prefetch', 480));
        $this->deadlockOnce('delete from "jobs"');

        $job->delete();

        $this->assertSame(0, $this->connection->table('jobs')->count());
    }

    public function test_released_job_is_requeued_once_despite_deadlock(): void
    {
        $job = $this->reservedJob(new DeadlockRetryingDatabaseQueue($this->connection, 'jobs', 'prefetch', 480));
        $this->deadlockOnce('insert into "jobs"');

        $job->release(10);

        $rows = $this->connection->table('jobs')->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->reserved_at);
        $this->assertSame(1, (int) $rows[0]->attempts);
    }

    public function test_database_driver_resolves_to_deadlock_retrying_queue(): void
    {
        $this->assertInstanceOf(DeadlockRetryingDatabaseQueue::class, app('queue')->connection('database'));
        $this->assertInstanceOf(DeadlockRetryingDatabaseQueue::class, app('queue')->connection('database_embeddings'));
        $this->assertInstanceOf(DeadlockRetryingDatabaseQueue::class, app('queue')->connection('database_inquiries'));
    }

    private function reservedJob(DatabaseQueue $queue): DatabaseJob
    {
        $queue->setContainer($this->app);
        $queue->setConnectionName('database');
        $queue->pushRaw(json_encode(['job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []], JSON_THROW_ON_ERROR));
        $job = $queue->pop();
        $this->assertInstanceOf(DatabaseJob::class, $job);

        return $job;
    }

    /** Pierwsze zapytanie zaczynające się od $sqlPrefix kończy się zakleszczeniem, jak na MariaDB 10.5. */
    private function deadlockOnce(string $sqlPrefix): void
    {
        $thrown = false;
        $this->connection->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use (&$thrown, $sqlPrefix): void {
            if ($thrown || ! str_starts_with($query, $sqlPrefix)) {
                return;
            }
            $thrown = true;
            $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            throw new QueryException($connection->getName(), $query, $bindings, $pdo);
        });
    }
}
