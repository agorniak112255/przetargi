<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolejka analiz zapytań klientów w osobnej tabeli (połączenie `database_inquiries`, config/queue.php) —
 * z tego samego powodu co jobs_embeddings: MariaDB 10.5 nie zna SKIP LOCKED, a wspólna tabela `jobs`
 * z workerami opisów zakleszczała się. Osobna kolejka też nie czeka za tysiącami zadań opisów.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs_inquiries')) {
            return;
        }

        Schema::create('jobs_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs_inquiries');
    }
};
