<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Znacznik „wysłano” przy zapytaniu klienta — pracownik kopiuje list do maila
 * i oznacza zapytanie jako obsłużone (odwracalne, bo może cofnąć oznaczenie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->timestamp('replied_at')->nullable()->after('reply_body');
        });
    }

    public function down(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropColumn('replied_at');
        });
    }
};
