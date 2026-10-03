<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ochrona przed powtórnym powiadomieniem: to samo zdarzenie o tej samej rzeczy w tym samym okresie trafia do
 * osoby raz (insertOrIgnore — 0 wstawionych wierszy = już wysłane).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('subject_key', 80);
            $table->string('period_key', 40);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'event', 'subject_key', 'period_key'], 'notification_dispatches_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
    }
};
