<?php

declare(strict_types=1);

namespace App\Services\PriceLists;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * Podgląd importu cennika bez zapisów (10.10.2026) — programista uruchamia go także na produkcji (price-lists:preview).
 * Warstwy: pamięć podręczna na czas wywołania w tablicy (cache.default=array), kolejka bez wykonania (queue.default →
 * sterownik null), na MySQL/MariaDB sesja SET SESSION TRANSACTION READ ONLY, transakcja wycofywana na końcu oraz
 * sprawdzenie każdego zapytania PRZED wykonaniem (Connection::beforeExecuting): zapis (insert/update/delete/replace/
 * create/alter/drop/truncate) nie dochodzi do bazy, a ReadOnlyViolation jest zapamiętany — wyjątek połknięty przez
 * wołany kod i tak wraca po zakończeniu wywołania. Zapytania innych połączeń łapie nasłuch QueryExecuted (po wykonaniu).
 */
final class ReadOnlyGuard
{
    private const WRITE_SQL = '/^\s*(?:\/\*.*?\*\/\s*)*(insert|update|delete|replace|create|alter|drop|truncate)\b/is';

    /** Głębokość zagnieżdżonych run() — sprawdzanie działa tylko w środku. */
    private static int $depth = 0;

    private static ?ReadOnlyViolation $violation = null;

    /** @var WeakMap<Dispatcher, true>|null dyspozytory zdarzeń z już dopiętym nasłuchem (nowa aplikacja w testach = nowy) */
    private static ?WeakMap $listening = null;

    /** @var WeakMap<Connection, true>|null połączenia z już dopiętym sprawdzeniem przed wykonaniem */
    private static ?WeakMap $guarded = null;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws ReadOnlyViolation
     */
    public function run(callable $callback): mixed
    {
        $config = [
            'cache.default' => config('cache.default'),
            'queue.default' => config('queue.default'),
        ];
        $outer = self::$depth === 0;
        config([
            'cache.default' => 'array',
            'queue.connections.null' => ['driver' => 'null'],
            'queue.default' => 'null',
        ]);
        try {
            $connection = DB::connection();
            $this->guard($connection);
            $mysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
            $level = $connection->transactionLevel();
            if ($outer) {
                self::$violation = null;
            }
            $readOnlySession = false;
            try {
                if ($mysql && $level === 0) {
                    $connection->statement('SET SESSION TRANSACTION READ ONLY');
                    $readOnlySession = true;
                }
                $connection->beginTransaction();
                self::$depth++;
                try {
                    $result = $callback();
                } catch (QueryException $e) {
                    // MySQL/MariaDB w sesji READ ONLY odrzuca zapis (SQLSTATE 25006), którego wzorzec nie rozpoznał
                    if ($mysql && (string) $e->getCode() === '25006') {
                        self::$violation ??= ReadOnlyViolation::forSql($e->getSql());
                        throw self::$violation;
                    }
                    throw $e;
                } finally {
                    self::$depth--;
                }
                if (self::$violation !== null) {
                    throw self::$violation;
                }

                return $result;
            } finally {
                try {
                    if ($connection->transactionLevel() > $level) {
                        $connection->rollBack($level);
                    }
                } finally {
                    if ($readOnlySession) {
                        $connection->statement('SET SESSION TRANSACTION READ WRITE');
                    }
                }
            }
        } finally {
            if ($outer) {
                self::$violation = null;
            }
            config($config);
        }
    }

    private function guard(Connection $connection): void
    {
        self::$guarded ??= new WeakMap;
        if (! isset(self::$guarded[$connection])) {
            self::$guarded[$connection] = true;
            $connection->beforeExecuting(static function (string $query): void {
                self::check($query);
            });
        }

        /** @var Dispatcher $events */
        $events = app('events');
        self::$listening ??= new WeakMap;
        if (isset(self::$listening[$events])) {
            return;
        }
        self::$listening[$events] = true;
        $events->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            self::check($query->sql);
        });
    }

    private static function check(string $sql): void
    {
        if (self::$depth <= 0 || preg_match(self::WRITE_SQL, $sql) !== 1) {
            return;
        }
        $violation = ReadOnlyViolation::forSql($sql);
        self::$violation ??= $violation;

        throw $violation;
    }

    /** Czy zapytanie jest zapisem (dla testów i diagnostyki). */
    public static function isWrite(string $sql): bool
    {
        return preg_match(self::WRITE_SQL, $sql) === 1;
    }
}
