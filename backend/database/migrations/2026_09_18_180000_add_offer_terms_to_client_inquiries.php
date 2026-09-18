<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warunki oferty wpisane przez handlowca: termin realizacji, dostawa, płatność
 * i ważność oferty. Klient pyta o nie wprost („Proszę również o podanie: …”),
 * a dotąd nie miały gdzie stanąć — odpowiedź na te pytania kończyła się
 * w dopisku albo nie szła wcale.
 *
 * Trzymamy je przy zapytaniu, a nie w ustawieniach: przy każdej ofercie bywają
 * inne, a to, co wpisano ostatnio, podpowiada się przy następnej.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->json('offer_terms')->nullable()->after('extra_note');
        });
    }

    public function down(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropColumn('offer_terms');
        });
    }
};
