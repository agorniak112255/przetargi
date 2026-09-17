<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wykrywanie tego samego zapytania u kilku handlowców.
 *
 * Mail wysłany na kilka adresów albo przekierowany przez serwer zachowuje
 * Message-ID — to dopasowanie pewne. Mail przekazany ręcznie dostaje nowy
 * identyfikator, więc drugim kluczem jest odcisk treści. Liczymy go dwa razy:
 * z całej treści i bez pierwszej linii, bo osoba przekazująca zwykle dopisuje
 * u góry jedno zdanie („zapytanie:”) i sam pełny odcisk by się rozjechał.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->string('source_fingerprint', 64)->nullable()->after('source_message_id');
            $table->string('source_fingerprint_tail', 64)->nullable()->after('source_fingerprint');
            $table->foreignId('duplicate_of_id')->nullable()->after('user_id')
                ->constrained('client_inquiries')->nullOnDelete();

            $table->index('source_fingerprint');
            $table->index('source_fingerprint_tail');
        });
    }

    public function down(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropForeign(['duplicate_of_id']);
            $table->dropIndex(['source_fingerprint']);
            $table->dropIndex(['source_fingerprint_tail']);
            $table->dropColumn(['source_fingerprint', 'source_fingerprint_tail', 'duplicate_of_id']);
        });
    }
};
