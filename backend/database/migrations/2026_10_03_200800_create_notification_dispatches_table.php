<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ochrona przed powtórnym powiadomieniem: to samo zdarzenie o tej samej rzeczy w tym samym okresie trafia do
 * osoby raz (insertOrIgnore — 0 wstawionych wierszy = już wysłane).
 *
 * Kanał e-mail ma własny stan, bo dzwonek idzie raz, a e-mail po błędzie poczty można ponowić w kolejnym
 * przebiegu (NotificationDispatcher): mail_status null = e-mail nie był chciany, pending = czeka na ponowienie,
 * sending = wysyłka w toku, sent = wysłany, failed = poddany po notifications.mail_retry.max_attempts próbach.
 *
 * Kolumny czasu są nullable (MariaDB z explicit_defaults_for_timestamp=0: kolumna timestamp NOT NULL bez
 * wartości domyślnej dostałaby ON UPDATE CURRENT_TIMESTAMP albo zerową datę odrzucaną przez NO_ZERO_DATE).
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
            $table->string('mail_status', 10)->nullable();
            $table->unsignedTinyInteger('mail_attempts')->default(0);
            $table->timestamp('mail_attempted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'event', 'subject_key', 'period_key'], 'notification_dispatches_unique');
            // e-maile czekające na ponowienie (system:check co 10 minut szuka ich dla alertów)
            $table->index(['event', 'mail_status'], 'notification_dispatches_mail_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
    }
};
