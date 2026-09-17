<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prośba „wyślij to przez Thunderbirda”. Przeglądarka nie umie sięgnąć do
 * klienta pocztowego, więc zostawia tu znacznik, a dodatek go podejmuje
 * i otwiera okno odpowiedzi. Kasuje go dodatek albo ponowne kliknięcie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->timestamp('send_requested_at')->nullable()->after('replied_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropColumn('send_requested_at');
        });
    }
};
