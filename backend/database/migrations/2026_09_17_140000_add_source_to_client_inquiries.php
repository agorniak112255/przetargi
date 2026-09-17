<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pochodzenie zapytania: skąd przyszło („web” = wklejone w aplikacji,
 * „thunderbird” = przekazane z dodatku) oraz Message-ID maila źródłowego.
 *
 * Message-ID nie jest unikalny — dwóch handlowców może obrabiać ten sam mail;
 * indeks służy wyłącznie odnalezieniu zapytania założonego już przez tę osobę.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->string('source_channel', 20)->default('web')->after('tone');
            $table->string('source_message_id', 255)->nullable()->after('source_subject');

            $table->index(['user_id', 'source_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'source_message_id']);
            $table->dropColumn(['source_channel', 'source_message_id']);
        });
    }
};
