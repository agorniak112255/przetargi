<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolejka wektorów w osobnej tabeli (połączenie `database_embeddings`, config/queue.php).
 * MariaDB 10.5 na serwerze nie zna SKIP LOCKED, więc 3 workery wektorów i 28 pozostałych
 * pobierało zadania z tej samej tabeli `jobs` przez `select ... for update` i zakleszczało się
 * przy każdej większej synchronizacji B2B.
 *
 * Zadania czekające w `jobs` przenosimy, żeby nie utknęły: po wdrożeniu nikt ich tam nie pobierze.
 * Zarezerwowane zostają — kończy je stary worker w trakcie restartu.
 *
 * Bez `lockForUpdate`: blokada wierszy wracałaby do zakleszczeń z pracującymi workerami,
 * a zakleszczenie w migracji zatrzymałoby server-update.sh. Gdy worker zdąży zarezerwować wiersz
 * między odczytem a skasowaniem, przeliczenie wektora wykona się dwa razy — to nieszkodliwe
 * (indeks porównuje embedding_hash karty).
 */
return new class extends Migration
{
    private const QUEUE = 'embeddings';

    private const CHUNK = 200;

    public function up(): void
    {
        if (! Schema::hasTable('jobs_embeddings')) {
            Schema::create('jobs_embeddings', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        $this->move('jobs', 'jobs_embeddings');
    }

    public function down(): void
    {
        $this->move('jobs_embeddings', 'jobs');
        Schema::dropIfExists('jobs_embeddings');
    }

    private function move(string $from, string $to): void
    {
        if (! Schema::hasTable($from) || ! Schema::hasTable($to)) {
            return;
        }

        while (true) {
            $moved = DB::transaction(function () use ($from, $to): int {
                $rows = DB::table($from)
                    ->where('queue', self::QUEUE)
                    ->whereNull('reserved_at')
                    ->orderBy('id')
                    ->limit(self::CHUNK)
                    ->get(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']);

                if ($rows->isEmpty()) {
                    return 0;
                }

                DB::table($to)->insert($rows->map(static fn (object $row): array => [
                    'queue' => $row->queue,
                    'payload' => $row->payload,
                    'attempts' => $row->attempts,
                    'reserved_at' => null,
                    'available_at' => $row->available_at,
                    'created_at' => $row->created_at,
                ])->all());

                DB::table($from)->whereIn('id', $rows->pluck('id')->all())->delete();

                return $rows->count();
            });

            if ($moved === 0) {
                return;
            }
        }
    }
};
