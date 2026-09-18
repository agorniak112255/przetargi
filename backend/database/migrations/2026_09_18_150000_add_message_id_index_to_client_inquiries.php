<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks po samym Message-ID.
 *
 * Dotychczasowy indeks złożony ['user_id', 'source_message_id'] obsługuje
 * pytanie „czy JA mam już zapytanie z tego maila”. Dodatek do Thunderbirda
 * pyta inaczej — o paczkę identyfikatorów bez zawężenia do jednej osoby
 * (POST /api/inquiries/lookup) — a do tego tamten indeks się nie nadaje,
 * bo jego pierwszą kolumną jest user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->index('source_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropIndex(['source_message_id']);
        });
    }
};
