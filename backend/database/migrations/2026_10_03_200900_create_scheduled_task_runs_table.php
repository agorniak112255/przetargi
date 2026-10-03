<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Przebiegi zadań harmonogramu (start, koniec, czas, wynik, końcówka komunikatu) — ekran „Stan systemu” i alerty.
 * Zadania uruchamiane co minutę / co 10 minut zapisują tylko błędy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('task', 100);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 10);
            $table->smallInteger('exit_code')->nullable();
            $table->text('output_tail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['task', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
